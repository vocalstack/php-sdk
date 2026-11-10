<?php

declare(strict_types=1);

namespace VocalStack\Tests;

use PHPUnit\Framework\TestCase;
use VocalStack\Http\HttpResponse;
use VocalStack\Tests\Support\FakeHttpClient;
use VocalStack\Upload;
use VocalStack\VocalStack;

final class UploadsTest extends TestCase
{
    public function testSendPutsEachPartToItsLinkThenCompletes(): void
    {
        $http = new FakeHttpClient([static function (array $call): HttpResponse {
            $url = $call['url'];
            if ($url === 'http://api.test/v1/uploads') {
                return new HttpResponse(201, ['content-type' => 'application/json'], (string) json_encode(['id' => 'up-1', 'state' => 'uploading', 'name' => 'a.mp3', 'bytes' => 10, 'partBytes' => 6, 'expiresAt' => 'x',
                    'parts' => [['number' => 1, 'bytes' => 6, 'url' => 'https://store.test/p1'], ['number' => 2, 'bytes' => 4, 'url' => 'https://store.test/p2']]]));
            }
            if (str_starts_with($url, 'https://store.test/')) {
                $n = str_ends_with($url, 'p1') ? 1 : 2;
                return new HttpResponse(200, ['etag' => "\"e{$n}\""]);
            }
            return new HttpResponse(200, ['content-type' => 'application/json'], (string) json_encode(['id' => 'up-1', 'state' => 'complete'] + json_decode($call['content'], true)));
        }]);
        $vs = new VocalStack(apiKey: 'vs_key_x', baseUrl: 'http://api.test', httpClient: $http);
        $seen = [];
        $up = $vs->uploads->send(Upload::bytes('0123456789', 'a.mp3'), onProgress: function (int $s, int $t) use (&$seen) { $seen[] = [$s, $t]; });
        $this->assertSame('complete', $up['state']);
        $this->assertSame([['number' => 1, 'etag' => '"e1"'], ['number' => 2, 'etag' => '"e2"']], $up['parts']);
        $this->assertSame(['name' => 'a.mp3', 'bytes' => 10], json_decode($http->calls[0]['content'], true));
        $this->assertSame('012345', $http->calls[1]['content']);
        $this->assertSame('6789', $http->calls[2]['content']);
        $this->assertArrayNotHasKey('authorization', $http->calls[1]['headers']);
        $this->assertSame([10, 10], end($seen));
    }

    public function testSwitchedOffIsTheServersError(): void
    {
        $http = new FakeHttpClient([[409, ['code' => 'invalid_state', 'status' => 409, 'message' => 'Direct uploads are not available yet; send the file with POST /v1/transcriptions.']]]);
        $vs = new VocalStack(apiKey: 'vs_key_x', baseUrl: 'http://api.test', httpClient: $http);
        try {
            $vs->uploads->send(Upload::bytes('abc', 'a.mp3'));
            $this->fail('no error');
        } catch (\VocalStack\VocalStackException $e) {
            $this->assertSame('invalid_state', $e->errorCode);
        }
        $this->assertCount(1, $http->calls);
    }
}
