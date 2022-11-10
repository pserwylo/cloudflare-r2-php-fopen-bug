<?php

error_reporting(E_ALL);

require_once('vendor/autoload.php');

use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Configure using the following env vars:
 *
 *
 * BUCKET_NAME (Required)
 *
 *   The the script will create the relevant test files of appropriate sizes
 *   (they are small, less than 1MiB), and upload them.
 *   Ensure your PROFILE has write access in order to do this.
 *
 *
 * ENDPOINT_URL (Required)
 *
 *   This is a PoC relating to Cloudflare R2, which uses per-account URLs for
 *   R2 buckets.
 *
 *
 * PROFILE
 *
 *   Default: 'default'
 *
 *   Used to source credentials from your ~/.aws/credentials file.
 *
 *
 * REGION
 *
 *   Default: 'auto'
 *
 *   Given this PoC is about Cloudflare, this defaults to 'auto' as required
 *   by the R2 docs. However if you want to test AWS S3 or other providers,
 *   you will probably need to specify a region.
 *
 *
 * PROXY_URL
 *
 *   Default: empty
 *
 *   If you want to investigate further, you may need to MITM yourself in order
 *   to view the contents of the HTTPS requests. For example, using mitmproxy.
 *
 */
final class S3Test extends TestCase {

	private string $bucketName;

	private int $numAttempts = 10;

	// 2^15 and below don't seem to cause any issues.
	// 2^16 exactly doesn't cause any issues.
	// 2^16 + some bytes causes issues if you ask for a range that starts
	//        below 65535 but extends above it. All bytes after 65535 will
	//        be truncated in this case (when it intermittently fails).
	private int $filesize = 65535 + 50;

	private S3Client $client;

	/**
	 * @throws Exception If missing required env vars.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->bucketName = getenv('BUCKET_NAME');

		if (!$this->bucketName) {
			throw new Exception("BUCKET_NAME env var not set.");
		}

		$endpoint = getenv('ENDPOINT_URL');
		if (!$endpoint) {
			throw new Exception("ENDPOINT_URL env var not set.");
		}

		$options = [
			'version' => 'latest',
			'endpoint' => $endpoint,
			'region' => getenv('REGION') ?: 'auto',
			'profile' => getenv('PROFILE') ?: 'default',
			// 'use_path_style_endpoint' => true,
		];

		$this->client = new S3Client($options);

		if (!$this->client->doesObjectExist($this->bucketName, "$this->filesize.txt")) {
			$this->client->putObject([
				'Bucket' => $this->bucketName,
				'Key' => "$this->filesize.txt",
				'Body' => self::generateRandomString($this->filesize),
			]);
		}

	}

	/*
	public function testReadingRangeWithAllBytesAfter65536() {
		$this->runFOpenMultipeTimes(65536, 20);
	}

	public function testReadingRangeWithAllBytesBefore65536() {
		$this->runFOpenMultipeTimes(65500, 20);
	}
	*/

	public function testReadingRangeWith10BytesBeforeAnd10BytesAfter65536() {
		$this->runFOpenMultipeTimes(65526, 20);
		echo "HERE";
	}

	private function runFOpenMultipeTimes(int $offset, int $count) {
		for ($i = 0; $i < $this->numAttempts; $i ++) {
			$string = $this->readUsingFOpen($offset, $count);
			$this->assertEquals(
				$count,
				strlen($string),
				"Read incorrect number of bytes on attempt " . ($i + 1) . " of $this->numAttempts"
			);
		}
	}

	/*
	 * Commented out for now, because I can't manage to find a way to make this *fail*.
	 * Only fopen() fails at this point, and only with Cloudflare R2, not AWS S3.

	 public function testGuzzle() {
		for ($i = 0; $i < $this->numAttempts; $i ++) {
			$string = $this->readUsingGuzzle();
			$this->assertEquals(
				$this->bytesFromEnd,
				strlen($string),
				"Read incorrect number of bytes on attempt " . ($i + 1) . " of $this->numAttempts"
			);
		}
	}*/

	private function createGetObjectRequest(int $offset, int $count): RequestInterface {
		$end = $offset + $count;
		return \Aws\serialize(
			$this->client->getCommand('GetObject', [
				'Bucket' => $this->bucketName,
				'Key' => "$this->filesize.txt",
				'Range' => "bytes=$offset-$end",
			])
		);
	}

	private function createStreamContext(RequestInterface $request) {
		$headers = [];
		foreach ($request->getHeaders() as $name => $values) {
			$headers[] = "$name: $values[0]";
		}

		$opts = [
			'http' => [
				'protocol_version' => $request->getProtocolVersion(),
				'header' => $headers,
			],
		];

		$proxy = getenv('PROXY_URL') ?: null;
		if ($proxy) {
			$opts['http']['proxy'] = $proxy;
			$opts['http']['request_fulluri'] = true;
		}

		return stream_context_create($opts);
	}

	function readUsingFOpen(int $offset, int $count): string {
		$request = $this->createGetObjectRequest($offset, $count);
		$context = $this->createStreamContext($request);
		$fh = fopen($request->getUri(), 'r', false, $context);
		return fread($fh, $count) ?: '';
	}

	function readUsingGuzzle(int $offset, int $count): string {
		$request = $this->createGetObjectRequest($offset, $count);
		$response = (new GuzzleHttp\Client())->sendRequest($request);
		return (string)$response->getBody();
	}

	private static function generateRandomString($length = 10): string {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, $charactersLength - 1)];
		}
		return $randomString;
	}

}
