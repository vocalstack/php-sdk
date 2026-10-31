<?php

declare(strict_types=1);

namespace VocalStack\Tests;

use PHPUnit\Framework\TestCase;
use VocalStack\Http\HttpResponse;
use VocalStack\RealtimeSession;
use VocalStack\Tests\Support\FakeHttpClient;
use VocalStack\Tests\Support\WsServer;
use VocalStack\Upload;
use VocalStack\VocalStack;
use VocalStack\VocalStackException;

use function VocalStack\paceRealtime;

final class RealtimeTest extends TestCase
{
    private static function committed(int $seq, string $text): array
    {
        return ['type' => 'transcript.committed', 'segment' => ['seq' => $seq, 'start' => $seq, 'end' => $seq + 1, 'text' => $text, 'speaker' => 0, 'words' => []]];
    }

    private static function done(string $reason = 'eof'): array
    {
        return ['type' => 'session.done', 'reason' => $reason, 'stats' => ['audioS' => 1, 'processingS' => 0.5, 'realtimeFactor' => 2]];
    }

    private static function connectError(array $scenario, float $deadline = 5.0): VocalStackException
    {
        $server = new WsServer($scenario);
        try {
            RealtimeSession::connect('s1', $server->url(), readyDeadline: $deadline);
        } catch (VocalStackException $e) {
            return $e;
        }
        self::fail('connected');
    }

    public function testARefusedUpgradeIsReadFromItsBody(): void
    {
        $this->assertSame('capacity_exceeded', self::connectError(['rejectUpgrade' => ['status' => 503]])->errorCode);
        $e = self::connectError(['rejectUpgrade' => ['status' => 503, 'body' => ['code' => 'unavailable', 'message' => 'waking', 'data' => ['retryable' => true]]]]);
        $this->assertSame('unavailable', $e->errorCode);
        $this->assertSame(503, $e->status);
        $e = self::connectError(['rejectUpgrade' => ['status' => 410, 'body' => ['code' => 'session_expired', 'message' => 'late', 'data' => ['retryable' => false]]]]);
        $this->assertSame('session_expired', $e->errorCode);
        $this->assertSame('late', $e->getMessage());
        $this->assertSame('capacity_exceeded', self::connectError(['rejectUpgrade' => ['status' => 502, 'body' => '<html>']])->errorCode);
    }

    public function testAnUnansweredUpgradeIsCapacityExceeded(): void
    {
        $e = self::connectError(['hang' => 3], 0.5);
        $this->assertSame('capacity_exceeded', $e->errorCode);
        $this->assertTrue($e->retryable);
    }

    public function testSilenceBeforeAnyFrameIsCapacityExceededAfterLoadingItIsEngineUnavailable(): void
    {
        $e = self::connectError(['frames' => [['$delayMs' => 1500]]], 0.4);
        $this->assertSame('capacity_exceeded', $e->errorCode);
        $e = self::connectError(['frames' => [['type' => 'session.loading', 'stage' => 'model'], ['$delayMs' => 1500]]], 0.4);
        $this->assertSame('engine_unavailable', $e->errorCode);
    }

    public function testLoadingHeartbeatsReArmTheDeadline(): void
    {
        $server = new WsServer(['frames' => [
            ['type' => 'session.loading', 'stage' => 'download'], ['$delayMs' => 300],
            ['type' => 'session.loading', 'stage' => 'download'], ['$delayMs' => 300],
            ['type' => 'session.loading', 'stage' => 'model'], ['$delayMs' => 300],
            ['type' => 'session.ready'], self::done(),
        ]]);
        $session = RealtimeSession::connect('s1', $server->url(), readyDeadline: 0.5);
        $this->assertSame([], iterator_to_array($session->events(), false));
        $this->assertSame('eof', $session->doneReason);
    }

    public function testAnErrorBeforeReadyAndCloseBeforeReady(): void
    {
        $e = self::connectError(['frames' => [['type' => 'error', 'code' => 'invalid_options', 'message' => 'bad', 'retryable' => false]]]);
        $this->assertSame('invalid_options', $e->errorCode);
        $this->assertSame('engine_unavailable', self::connectError(['frames' => []])->errorCode);
    }

    public function testAnErrorFrameEndsIterationAndRaisesAgain(): void
    {
        $server = new WsServer(['frames' => [
            ['type' => 'session.ready'], self::committed(0, 'before'),
            ['type' => 'error', 'code' => 'slow_consumer', 'message' => 'behind', 'retryable' => true], ['$close' => true],
        ]]);
        $session = RealtimeSession::connect('s1', $server->url());
        $seen = [];
        try {
            foreach ($session as $ev) {
                $seen[] = $ev['segment']['text'];
            }
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('slow_consumer', $e->errorCode);
            $this->assertTrue($e->retryable);
        }
        $this->assertSame(['before'], $seen);
        $this->expectException(VocalStackException::class);
        iterator_to_array($session->events());
    }

    public function testASocketThatClosesWithNoSessionDoneIsEngineLost(): void
    {
        $server = new WsServer(['frames' => [['type' => 'session.ready'], self::committed(0, 'before the loss'), ['$close' => true]]]);
        $session = RealtimeSession::connect('s1', $server->url());
        $seen = [];
        try {
            foreach ($session as $ev) {
                $seen[] = $ev['segment']['text'];
            }
            $this->fail('a lost engine ended quietly');
        } catch (VocalStackException $e) {
            $this->assertSame('engine_lost', $e->errorCode);
            $this->assertTrue($e->retryable);
        }
        $this->assertSame(['before the loss'], $seen, 'what came before the loss is still read');
        $this->assertNull($session->doneReason);
        $this->assertTrue($session->ended());
    }

    public function testASocketClosedByCloseEndsQuietly(): void
    {
        $server = new WsServer(['frames' => [['type' => 'session.ready'], ['$waitEof' => true], self::done()]]);
        $session = RealtimeSession::connect('s1', $server->url());
        $session->close();
        $this->assertSame([], iterator_to_array($session->events()));
    }

    public function testWhatTheClientSends(): void
    {
        $server = new WsServer(['frames' => [['type' => 'session.ready'], ['$waitEof' => true], self::done()]]);
        $session = RealtimeSession::connect('s1', $server->url());
        $session->sendAudio(str_repeat("\0", 6400));
        $session->update(latencyMode: 'fast', translate: ['fr', 'de']);
        $session->cancel();
        $session->sendFile(Upload::bytes(str_repeat('a', 600 * 1024)));
        foreach ($session as $ev) {
        }
        $session->close();
        $session->sendAudio('late');
        $got = array_map(
            static fn (array $f) => $f['op'] === 1 ? $f['payload'] : "binary:{$f['payload']}",
            array_values(array_filter($server->received(), static fn ($f) => $f['op'] === 1 || $f['op'] === 2)),
        );
        $this->assertSame([
            'binary:6400',
            '{"type":"session.update","latencyMode":"fast","translate":["fr","de"]}',
            '{"type":"cancel"}',
            'binary:262144', 'binary:262144', 'binary:90112',
            '{"type":"eof"}',
        ], $got);
        $this->assertSame('eof', $session->doneReason);
    }

    public function testRealtimeConnectCreatesTheSessionThenOpensItsSocket(): void
    {
        $server = new WsServer(['frames' => [['type' => 'session.ready'], ['$waitEof' => true],
            ['type' => 'transcript.progress', 'processedS' => 1.0, 'totalS' => 2.0], self::committed(0, 'one'),
            ['type' => 'stream.pull'], ['type' => 'transcript.shiny'], self::committed(1, 'two'), self::done()]]);
        $http = new FakeHttpClient([static fn (array $call) => new HttpResponse(200, [], (string) json_encode([
            'sessionId' => 'fx', 'streamUrl' => $server->url(), 'expiresAt' => '2026-10-19T00:00:00Z',
        ]))]);
        $vs = new VocalStack(apiKey: 'vs_key_x', baseUrl: 'http://api.test', httpClient: $http);
        $progress = [];
        $commits = [];
        $result = $vs->realtime->transcribeFile(
            Upload::bytes('RIFF....'),
            ['spokenLanguage' => 'en', 'latencyMode' => null],
            onProgress: function (array $p) use (&$progress): void {
                $progress[] = $p['processedS'];
            },
            onCommit: function (array $s) use (&$commits): void {
                $commits[] = $s['seq'];
            },
        );
        $this->assertSame('http://api.test/v1/realtime/sessions', $http->calls[0]['url']);
        $this->assertSame(['input' => ['kind' => 'file'], 'spokenLanguage' => 'en'], json_decode($http->calls[0]['content'], true));
        $this->assertSame('one two', $result->text());
        $this->assertSame('eof', $result->doneReason);
        $this->assertEquals([1.0], $progress);
        $this->assertSame([0, 1], $commits);
        $this->assertArrayNotHasKey('authorization', $server->request()['headers'], 'the stream URL is signed; no key on it');
    }

    public function testPaceRealtimeYieldsEventsWhileItSends(): void
    {
        $server = new WsServer(['frames' => [['type' => 'session.ready'], ['$delayMs' => 100], self::committed(0, 'early'),
            ['$waitEof' => true], self::committed(1, 'late'), self::done()]]);
        $session = RealtimeSession::connect('s1', $server->url());
        $pcm = str_repeat("\0", 64000 / 2);
        $start = microtime(true);
        $texts = [];
        foreach (paceRealtime($session, $pcm, chunkSeconds: 0.1, speed: 2.0) as $ev) {
            $texts[] = $ev['segment']['text'];
        }
        $this->assertGreaterThanOrEqual(0.2, microtime(true) - $start, 'paced at 2x: about 0.25 s');
        $this->assertSame(['early', 'late'], $texts);
        $binary = array_filter($server->received(), static fn ($f) => $f['op'] === 2);
        $this->assertSame([6400, 6400, 6400, 6400, 6400], array_column(array_values($binary), 'payload'));
    }

    public function testPublishOpensTheGrantsSocketAndRefusesAnUpload(): void
    {
        $server = new WsServer(['frames' => [['type' => 'session.ready'], self::done('cancelled')]]);
        $vs = new VocalStack(apiKey: 'vs_key_x', baseUrl: 'http://api.test', httpClient: new FakeHttpClient([[200, null]]));
        $session = $vs->recordings->publish(['streamUrl' => $server->url(), 'recording' => ['id' => 'r1']]);
        $this->assertSame('r1', $session->sessionId);
        iterator_to_array($session);
        $this->assertSame('cancelled', $session->doneReason);
        try {
            $vs->recordings->publish(['recording' => ['id' => 'r1']]);
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('invalid_state', $e->errorCode);
        }
    }

    public function testApiSocketsCarryTheCredentialAsAHeader(): void
    {
        $server = new WsServer(['frames' => [['type' => 'subscribe.ready', 'lastSeq' => 3]]]);
        $vs = new VocalStack(apiKey: 'vs_key_x', baseUrl: "http://127.0.0.1:{$server->port}", deviceId: 'dev1', httpClient: new FakeHttpClient([[200, null]]));
        $ws = $vs->recordings->subscribe('r 1', lang: 'fr');
        $this->assertSame([['type' => 'subscribe.ready', 'lastSeq' => 3]], iterator_to_array($ws->messages(), false));
        $req = $server->request();
        $this->assertSame('Bearer vs_key_x', $req['headers']['authorization']);
        $this->assertStringStartsWith('GET /v1/recordings/r%201/subscribe?lang=fr&device=dev1 ', $req['head']);
    }
}
