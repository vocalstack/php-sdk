<?php

declare(strict_types=1);

namespace VocalStack\Tests;

use PHPUnit\Framework\TestCase;
use VocalStack\Connect\Pkce;
use VocalStack\Http\MultipartBody;
use VocalStack\Tests\Support\FakeHttpClient;
use VocalStack\Upload;
use VocalStack\VocalStack;

final class UploadTest extends TestCase
{
    private static function client(array $answers = [[200, ['id' => 't1']]], array $opts = []): array
    {
        $http = new FakeHttpClient($answers);
        return [new VocalStack(...['apiKey' => 'vs_key_x', 'baseUrl' => 'http://api.test', 'httpClient' => $http] + $opts), $http];
    }

    public function testCreateFromAFileStreamsAMultipartBodyWithItsOptionsAndKey(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vs') . '.mp3';
        file_put_contents($path, str_repeat('ID3', 1000));
        try {
            [$vs, $http] = self::client();
            $vs->transcriptions->create(['settings' => ['spokenLanguage' => 'en'], 'name' => null], file: $path, idempotencyKey: 'k-file');
            $call = $http->calls[0];
            $this->assertSame('POST', $call['method']);
            $this->assertSame('http://api.test/v1/transcriptions', $call['url']);
            $this->assertSame('k-file', $call['headers']['idempotency-key']);
            $this->assertInstanceOf(MultipartBody::class, $call['body']);
            $body = $call['body'];
            $this->assertSame('multipart/form-data; boundary=' . $body->boundary, $call['headers']['content-type']);
            [$options, $file] = $body->parts();
            $this->assertSame('options', $options->name);
            $this->assertSame('{"settings":{"spokenLanguage":"en"}}', $options->value);
            $this->assertNull($options->filename);
            $this->assertSame('file', $file->name);
            $this->assertSame(basename($path), $file->filename);
            $this->assertSame('audio/mpeg', $file->contentType);
            $this->assertSame($path, $file->upload->path, 'streamed from its path, not read into the body');
            $this->assertSame(strlen($call['content']), $body->length());
            $this->assertStringContainsString(
                "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($path) . "\"\r\nContent-Type: audio/mpeg\r\n\r\n" . str_repeat('ID3', 1000) . "\r\n",
                $call['content'],
            );
            $this->assertStringEndsWith('--' . $body->boundary . "--\r\n", $call['content']);
        } finally {
            @unlink($path);
        }
    }

    public function testCreateFromBytesOrAStreamNamesTheFile(): void
    {
        [$vs, $http] = self::client();
        $vs->transcriptions->create(file: Upload::bytes('abc'));
        $this->assertSame('upload', $http->calls[0]['body']->parts()[1]->filename);
        $this->assertSame('{}', $http->calls[0]['body']->parts()[0]->value);
        $vs->transcriptions->create(file: Upload::bytes('abc'), fileName: 'talk.wav');
        $this->assertSame('talk.wav', $http->calls[1]['body']->parts()[1]->filename);
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, 'xxhello');
        fseek($stream, 2);
        $vs->transcriptions->create(file: $stream);
        $part = $http->calls[2]['body']->parts()[1];
        $this->assertSame('upload', $part->filename);
        $this->assertSame(5, $part->size());
        $this->assertStringContainsString("\r\n\r\nhello\r\n", $http->calls[2]['content']);
        $this->assertStringContainsString("\r\n\r\nhello\r\n", $http->calls[2]['body']->contents(), 'a second read starts over');
    }

    public function testCreateFromAUrlIsJsonAndCarriesTheIdempotencyKey(): void
    {
        [$vs, $http] = self::client();
        $vs->transcriptions->create(['url' => 'https://x.test/a.mp3', 'name' => 'A'], idempotencyKey: 'k-url');
        $vs->transcriptions->createFromUrl('https://x.test/b.mp3', ['shareLink' => true], idempotencyKey: 'k2');
        $this->assertSame('application/json', $http->calls[0]['headers']['content-type']);
        $this->assertSame('{"url":"https://x.test/a.mp3","name":"A"}', $http->calls[0]['content']);
        $this->assertSame('k-url', $http->calls[0]['headers']['idempotency-key']);
        $this->assertSame('{"url":"https://x.test/b.mp3","shareLink":true}', $http->calls[1]['content']);
        $this->assertSame('k2', $http->calls[1]['headers']['idempotency-key']);
    }

    public function testVoicesCreateSendsAJsonMetaPartEvenWithoutASample(): void
    {
        [$vs, $http] = self::client();
        $vs->voices->create('Ana', sampleUrl: 'https://x.test/s.mp3');
        $parts = $http->calls[0]['body']->parts();
        $this->assertCount(1, $parts);
        $this->assertSame('meta', $parts[0]->name);
        $this->assertSame('application/json', $parts[0]->contentType);
        $this->assertNull($parts[0]->filename);
        $this->assertSame(['name' => 'Ana', 'sampleUrl' => 'https://x.test/s.mp3', 'consent' => true], json_decode($parts[0]->value, true));
        $vs->voices->create('Draft', sample: Upload::bytes('webm'), draft: true);
        $parts = $http->calls[1]['body']->parts();
        $this->assertSame(['name' => 'Draft', 'draft' => true, 'consent' => false], json_decode($parts[0]->value, true));
        $this->assertSame('sample.webm', $parts[1]->filename);
        $this->assertSame('video/webm', $parts[1]->contentType);
    }

    public function testHeadersQueriesAndBodiesAsOnTheWire(): void
    {
        [$vs, $http] = self::client([[200, []]], ['actingPrincipal' => 'org_1', 'deviceId' => 'dev']);
        $vs->recordings->clip('r/1', 1.0, 2.5, shareCode: 'abc');
        $call = $http->calls[0];
        $this->assertSame('http://api.test/v1/recordings/r%2F1/audio/clip?shareCode=abc&startS=1.0&endS=2.5', $call['url']);
        $this->assertSame('*/*', $call['headers']['accept']);
        $this->assertSame('Bearer vs_key_x', $call['headers']['authorization']);
        $this->assertSame('org_1', $call['headers']['x-acting-principal']);
        $this->assertSame('dev', $call['headers']['x-device-id']);
        $this->assertSame('vocalstack-php/' . VocalStack::VERSION, $call['headers']['user-agent']);
        $vs->recordings->stop('r1');
        $this->assertSame('{"reason":null}', $http->calls[1]['content']);
        $vs->broadcasts->startRecording('b1');
        $this->assertSame('{}', $http->calls[2]['content']);
        $vs->broadcasts->archive('b1');
        $this->assertNull($http->calls[3]['body']);
        $vs->broadcasts->checkAudioSource('b1', 's1', fresh: true);
        $this->assertSame('http://api.test/v1/broadcasts/b1/audio-sources/s1/check?fresh=true', $http->calls[4]['url']);
        $vs->search->query('hello world', broadcasts: ['a', 'b'], limit: 5);
        $this->assertSame('http://api.test/v1/search?q=hello+world&broadcasts=a%2Cb&limit=5', $http->calls[5]['url']);
        $vs->voices->available('fr', asOwner: false);
        $this->assertArrayNotHasKey('authorization', $http->calls[6]['headers']);
        $vs->jobs->start('r1', startS: 3.0);
        $this->assertSame('{"startS":3.0}', $http->calls[7]['content']);
    }

    public function testATokenCallableIsReadPerRequest(): void
    {
        $n = 0;
        $http = new FakeHttpClient([[200, []]]);
        $vs = new VocalStack(baseUrl: 'http://api.test', token: function () use (&$n): string {
            return 'tok' . ++$n;
        }, httpClient: $http);
        $vs->me->get();
        $vs->me->get();
        $this->assertSame(['Bearer tok1', 'Bearer tok2'], array_map(static fn ($c) => $c['headers']['authorization'], $http->calls));
        $this->expectException(\InvalidArgumentException::class);
        new VocalStack(apiKey: 'k', token: 't', httpClient: $http);
    }

    public function testARedirectAnswersItsLocation(): void
    {
        [$vs] = self::client([[302, null, ['location' => 'https://media.test/a.mp3']]]);
        $this->assertSame('https://media.test/a.mp3', $vs->recordings->audioUrl('r1', kind: 'delivery'));
    }

    public function testPkceMatchesRfc7636(): void
    {
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
        $v = Pkce::verifier();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{64}$/', $v);
        $this->assertNotSame($v, Pkce::verifier());
        $this->assertSame(43, strlen(Pkce::challenge($v)));
        $this->assertSame('S256', Pkce::METHOD);
    }
}
