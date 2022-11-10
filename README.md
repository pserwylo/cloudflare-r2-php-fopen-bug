## What we know

### Small files are fine

Small files below 65535 don't cause any issues.
It doesn't matter if you request the whole file, or a subset using a `Range:` header,
you will always get what you asked for without issue.

### Large files are fine if you request the whole file

A file over 65535 (e.g. an extra 50 bytes larger) will successfully return
the entire file. You can do this using a range request (`Range: bytes=0-`), or
by requesting a normal `GET` without a `Range:` header.

### Range requests that straddle 65535

#### Using `fopen` causes intermittent failure

Example: `Range: bytes=65525-65545` (10 bytes before and 10 bytes after 65535).

The failures are intermittent, but frequent.
This test attempts 10 times, and usually fails on one of the first three attempts.

When failing, it only returns the bytes from before 65535 and then truncates the
rest.
For example:
* If I request 10 bytes before and 30 after: I will get the first 10 bytes back.
* If I request 30 bytes before and 10 after: I will get the first 30 bytes back.
* If I request 2 bytes before and 5 after: I will get the first 2 bytes back.

#### Using `Guzzle` never fails

None of the above comments regarding `fopen` failures apply when using `Guzzle`.
I could try 100 consecutive requests, and it will succeed every time.

### Difficulty observing `fopen` issues

Requests to Cloudflare R2 are obviously over HTTPS.

Therefore, WireShark or other network sniffers are unable to inspect the traffic
so that we can compare the `fopen` requests to the `Guzzle` requests.

Using a MITM proxy (e.g. `mitmproxy`), the proxy generally runs in plain text
such as `http://localhost:8080`.
This seems to invoke a different code path in PHP when using `fopen`, and all
of a sudden every request works flawlessly.
Compare this to when it intermittently fails where we observe failure in almost
30% of all requests.
