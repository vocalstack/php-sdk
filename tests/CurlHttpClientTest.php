<?php

declare(strict_types=1);

namespace VocalStack\Tests;

use PHPUnit\Framework\TestCase;
use VocalStack\Http\ConnectionException;
use VocalStack\Http\CurlHttpClient;
use VocalStack\Upload;
use VocalStack\VocalStack;
use VocalStack\VocalStackException;

final class CurlHttpClientTest extends TestCase
{
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        self::$port = (int) substr($name, strrpos($name, ':') + 1);
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'upload_max_filesize=64M', '-d', 'post_max_size=64M', '-S', '127.0.0.1:' . self::$port, __DIR__ . '/Support/echo_server.php'],
            [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
        );
        $until = microtime(true) + 5;
        while (microtime(true) < $until) {
            $c = @fsockopen('127.0.0.1', self::$port);
            if ($c) {
                fclose($c);
                return;
            }
            usleep(50_000);
        }
        self::fail('php -S did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private static function vs(): VocalStack
    {
        return new VocalStack(apiKey: 'vs_key_x', baseUrl: 'http://127.0.0.1:' . self::$port, timeout: 10.0);
    }

    public function testJsonAndForms(): void
    {
        $got = self::vs()->broadcasts->create(['name' => 'Talk'], idempotencyKey: 'k1');
        $this->assertSame('POST', $got['method']);
        $this->assertSame('application/json', $got['contentType']);
        $this->assertSame('{"name":"Talk"}', $got['raw']);
        $this->assertSame('k1', $got['headers']['idempotency-key']);
        $this->assertSame('Bearer vs_key_x', $got['headers']['authorization']);
        $this->assertStringStartsWith('vocalstack-php/', $got['headers']['user-agent']);
        $got = self::vs()->connect->token('c', 'v', 'https://a.test/cb');
        $this->assertSame('application/x-www-form-urlencoded', $got['contentType']);
        $this->assertSame('grant_type=authorization_code&code=c&code_verifier=v&redirect_uri=https%3A%2F%2Fa.test%2Fcb', $got['raw']);
        $this->assertArrayNotHasKey('authorization', $got['headers']);
    }

    public function testAFileIsStreamedAsMultipart(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vs') . '.wav';
        $data = random_bytes(3 * 1024 * 1024 + 17);
        file_put_contents($path, $data);
        try {
            $got = self::vs()->transcriptions->create(['name' => 'big'], file: $path);
            $this->assertStringStartsWith('multipart/form-data; boundary=', $got['contentType']);
            $this->assertSame(['options' => '{"name":"big"}'], $got['post']);
            $this->assertSame(basename($path), $got['files']['file']['name']);
            $this->assertSame('audio/x-wav', $got['files']['file']['type']);
            $this->assertSame(strlen($data), $got['files']['file']['size']);
            $this->assertSame(sha1($data), $got['files']['file']['sha1']);
        } finally {
            @unlink($path);
        }
    }

    public function testAStreamOfUnknownSizeGoesChunked(): void
    {
        $proc = popen('printf "chunked-body"', 'r');
        try {
            $got = self::vs()->voices->create('Ana', sample: Upload::stream($proc, 'ana.webm'));
            $this->assertNull($got['contentLength']);
            $this->assertSame(sha1('chunked-body'), $got['files']['sample']['sha1']);
            $this->assertSame(['name' => 'Ana', 'consent' => true], json_decode($got['post']['meta'], true));
        } finally {
            pclose($proc);
        }
    }

    public function testRedirectsAreNotFollowedAndErrorsAreParsed(): void
    {
        $t = new CurlHttpClient(5.0);
        $resp = $t->send('GET', 'http://127.0.0.1:' . self::$port . '/redirect', [], null);
        $this->assertSame(302, $resp->status);
        $this->assertSame('https://media.test/signed', $resp->header('Location'));
        $vs = new VocalStack(apiKey: 'k', baseUrl: 'http://127.0.0.1:' . self::$port . '/error?', maxRetries: 0);
        try {
            $vs->me->get();
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('rate_limited', $e->errorCode);
            $this->assertSame(429, $e->status);
            $this->assertSame(7.0, $e->retryAfter());
        }
    }

    public function testNoAnswerIsAConnectionException(): void
    {
        $this->expectException(ConnectionException::class);
        (new CurlHttpClient(2.0))->send('GET', 'http://127.0.0.1:1/', [], null);
    }
}
