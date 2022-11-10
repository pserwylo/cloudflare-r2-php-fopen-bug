# Investigating file truncation in PHP and/or Cloudflare R2

> **Disclaimer:** I have no idea if this is a Cloudflare R2 issue, a PHP issue, or
> if I am just going crazy and there is actually no issue at all here (though I'm
> fairly confident there is an issue somewhere).

When running Nextcloud server unit tests, but hard coding the S3Tests configuration
to point to a live Cloudflare R2 bucket instead of a mock S3 server, I see intermittent
file truncation issues that cause the tests to fail.

This repo is a minimal reproduction of this issue without any of the Nextcloud
infrastructure (though the usage of `fopen` is copied from the Nextcloud implementation).

These intermittent errors seem related to HTTP requests that include the `Range:` header
to request only a portion of the file. For certain conditions, these requests return
the correct `206 Partial Response` response, but intermittently truncate some
of the bytes beyond a certain part of the expected response.

# Table of contents

* [Running the suite](#running-the-suite)
* [What we know](#what-we-know)
  * [What works](#what-works)
    * [Small files](#small-files)
    * [Large files when requesting the whole file](#large-files-when-requesting-the-whole-file)
    * [Any request using `Guzzle` instead of `fopen`](#any-request-using-guzzle-instead-of-fopen)
    * [AWS S3](#aws-s3)
  * [What doesn't work](#what-doesnt-work)
    * [Range requests that straddle 65535](#range-requests-that-straddle-65535)
    * [Certain other range requests](#certain-other-range-requests)
  * [Difficulty observing `fopen` issues](#difficulty-observing-fopen-issues)
    * [MITM SSL Requests](#mitm-ssl-requests)
    * [Using GDB to debug PHP `fopen` source code](#using-gdb-to-debug-php-fopen-source-code)

# Running the suite

1. `cp .env.example .env`
2. Edit `.env` as per the documentation at the top of `R2FopenTest.php` (to point to your S3 bucket and endpoint).
3. `source .env`
4. (Optional) Edit the `filesizes()` function ni `R2FopenTest.php` to run the test with different power-of-two filesizes.
4. `phpunit R2FopenTest.php`

The test will automatically create files of the appropriate size and put them in the
bucket the first time it is run. Subsequent test runs will use these files for performance
reasons.

# What we know

## What works

### Small files

Small files below 65535 don't cause any issues.
It doesn't matter if you request the whole file, or a subset using a `Range:` header,
you will always get what you asked for without issue.

### Large files when requesting the whole file

A file over 65535 (e.g. an extra 50 bytes larger) will successfully return
the entire file. You can do this using a range request (`Range: bytes=0-`), or
by requesting a normal `GET` without a `Range:` header.

### Any request using `Guzzle` instead of `fopen`

PHP has a (cool/horrifying) feature where `fopen` can open HTTPS URLs (amoung other
things) in addition to local files on disk. The intermimttent issues happen when using
this feature (as is the case with Nextcloud Server). When using a more sane HTTP client
such as the well known `Guzzle`, I have been unable to reproduce the issues.

### AWS S3

It seems that using an AWS S3 bucket does not hit this issue, only Cloudflare R2. I haven't
tried any other S3 compatible storage providers to investigate further yet.

## What doesn't work

### Range requests that straddle 65535

Example: `Range: bytes=65525-65545` (10 bytes before and 10 bytes after 65535).

The failures are intermittent, but frequent.
This test attempts 10 times, and usually fails on one of the first three attempts.

When failing, it only returns the bytes from before 65535 and then truncates the
rest.
For example:
* If I request 10 bytes before and 30 after: I will get the first 10 bytes back.
* If I request 30 bytes before and 10 after: I will get the first 30 bytes back.
* If I request 2 bytes before and 5 after: I will get the first 2 bytes back.

### Certain other range requests

The `filesizes()` function in `R2FopenTest.php` has a few different power-of-2 filesizes,
with most commented out. The most reliable failure I could observe was using 2^16, but
I also witnessed issues (though less frequently) with 2^24`.

## Difficulty observing `fopen` issues

Requests to Cloudflare R2 are obviously over HTTPS.

Therefore, WireShark or other network sniffers are unable to inspect the traffic
so that we can compare the `fopen` requests to the `Guzzle` requests.

### MITM SSL Requests

Using a MITM proxy (e.g. `mitmproxy`), and using the proper proxy configuration
available to `fopen` the same way that Nextcloud does, is unable to reproduce the issue.
This is because the proxy generally runs in plain text such as `http://localhost:8080`.
This seems to invoke a different code path in PHP when using `fopen`, and all
of a sudden every request works flawlessly. The different code path is either in
the proxy handling code in PHP, or the plain-text HTTP code.

If you configure `mitmproxy` as a reverse proxy instead, and tweak the HTTP calls
to call `https://localhost` instead of `https://ACCOUNT.cloudflare.....`, you can
intercept the HTTPS calls. But the signature verification built into the AWS
request signs the `host` header and so the request (correctly) gets rejected by
the upstream server.

### Using GDB to debug PHP `fopen` source code

Latest effort involved compiling PHP from source with debug symbols, then using
GDB to step through the `fopen` codebase. This made me sad due to my lack of GDB
skills (though the graphical GDB integration in CLion was great for this), and
also due to the intermittent nature of the issue.

The file I suspect is at issue is `ext/standard/http_fopen_wrapper.c` ([link](https://github.com/php/php-src/blob/master/ext/standard/http_fopen_wrapper.c)).

While debugging, I did note that the HTTP request which was constructed was fine.
Here is a request captured from `gdb` just prior to sending the request:

```
"GET /65585.txt HTTP/1.1\r\nConnection: close\r\nHost: cloudflare-r2-php-fopen-bug.04cbb5bec03d50d4853aa685c9531851.r2.cloudflarestorage.com\r\nRange: bytes=65526-65546\r\nUser-Agent: aws-sdk-php/3.184.6 OS/Linux/5.4.0-126-generic\r\naws-sdk-invocation-id: ...snip...\r\naws-sdk-retry: 0/0\r\nx-amz-content-sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855\r\nX-Amz-Date: 20221113T021251Z\r\nAuthorization: AWS4-HMAC-SHA256 Credential=...snip.../auto/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=...snip...\r\n\r\n\377\377\377\377\377\377\377\377\377\377\377\377\377\216"
```

I'm unsure what the bytes at the end are, but to view the contents I needed to
cast the `ZVAL` php string to a `char*`, instead of the `char[1]` that `gdb`
says it is when just inspecting the `ZVAL` directly. I suspect that the strings
are not null terminated, because the `ZVAL` does explicitly keep a `len` value
which could be used, so I guess these `\377` bytes are just post the end of the
string and not of use to us here.

Converting this request to a `curl` command using the (terribly-hacked-together!) `gdb-output/process.php` file, we get the following:

```
curl  -H "Connection: close" \
 -H "Range: bytes=65526-65546" \
 -H "User-Agent: aws-sdk-php/3.184.6 OS/Linux/5.4.0-126-generic" \
 -H "aws-sdk-invocation-id: ...snip..." \
 -H "aws-sdk-retry: 0/0" \
 -H "x-amz-content-sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855" \
 -H "X-Amz-Date: 20221113T021251Z" \
 -H "Authorization: AWS4-HMAC-SHA256 Credential=...snip.../auto/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=...snip..." \
 https://BUCKET_NAME.ACCOUNT.r2.cloudflarestorage.com/65585.txt
```

All headers looked appropriate and similar to the Guzzle versions. No matter
how many times I ran the `curl` comand, it would **never** fail.

Therefore, I'm back to assuming it is something to do with the way the PHP code reads from
the socket, populates its internal buffers, and then copies them into PHP strings
to be used by the PHP script.

This is where I got to and then was unable to proceed further. My lack of GDB experience,
coupled with the intermittent nature of this and my lack of free time meant I was not able
to pin this down any further sorry.
