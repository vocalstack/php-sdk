<?php

declare(strict_types=1);

namespace VocalStack\Tests;

use PHPUnit\Framework\TestCase;
use VocalStack\Tests\Support\FakeHttpClient;
use VocalStack\VocalStack;

final class PlayerTest extends TestCase
{
    private static function client(array $answers, ?string $token = null): array
    {
        $http = new FakeHttpClient($answers);
        return [new VocalStack(token: $token, baseUrl: 'http://api.test', httpClient: $http), $http];
    }

    public function testRequestVoiceKeepsTheLangsOnlyCall(): void
    {
        [$vs, $http] = self::client([[200, []]]);
        $vs->transcriptions->requestVoice('t1', ['es']);
        $this->assertSame('http://api.test/v1/transcriptions/t1/voice', $http->calls[0]['url']);
        $this->assertSame(['langs' => ['es']], json_decode($http->calls[0]['content'], true));
    }

    public function testRequestVoiceForAShareReaderWithACast(): void
    {
        [$vs, $http] = self::client([[200, []]]);
        $vs->transcriptions->requestVoice('t1', ['es'], voiceBySpeaker: [0 => 'stock-a'], near: 4, shareCode: 'abc');
        $this->assertSame('http://api.test/v1/transcriptions/t1/voice?shareCode=abc', $http->calls[0]['url']);
        $this->assertSame('{"langs":["es"],"voiceBySpeaker":{"0":"stock-a"},"near":4}', $http->calls[0]['content']);
    }

    public function testRequestVoiceAsksForAClonedVoiceWithConsent(): void
    {
        [$vs, $http] = self::client([[200, []]]);
        $vs->transcriptions->requestVoice('t1', ['es'], voiceBySpeaker: [0 => 'clone:abc'], consent: true);
        $this->assertSame('{"langs":["es"],"consent":true,"voiceBySpeaker":{"0":"clone:abc"}}', $http->calls[0]['content']);
    }

    public function testOpenShareSendsNoCredential(): void
    {
        [$vs, $http] = self::client([[200, ['code' => 'abc']]], 'session');
        $this->assertSame('abc', $vs->shareLinks->open('abc')['code']);
        $this->assertSame('http://api.test/v1/shares/abc', $http->calls[0]['url']);
        $this->assertArrayNotHasKey('authorization', $http->calls[0]['headers']);
    }

    public function testLookupGivesNullWhenTheLinkHasNoTranscription(): void
    {
        [$vs, $http] = self::client([[200, ['transcription' => null]]], 'session');
        $this->assertNull($vs->transcriptions->lookup('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame(['url' => 'https://youtu.be/dQw4w9WgXcQ'], $http->query(0));
    }
}
