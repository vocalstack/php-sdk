<?php

declare(strict_types=1);

namespace VocalStack\Tests;

use PHPUnit\Framework\TestCase;
use VocalStack\RealtimeSession;
use VocalStack\Tests\Support\FakeHttpClient;
use VocalStack\Tests\Support\WsServer;
use VocalStack\VocalStack;

/**
 * The helpers the reference's guides lean on: `$vs->jobs->wait()`'s
 * `onProgress` (against FakeHttpClient) and a realtime socket's
 * `onFrame` (against a local WebSocket server). No network.
 */
final class HelpersTest extends TestCase
{
    public function testEveryJobReadReachesOnProgressTheLastOneIncluded(): void
    {
        $states = [['queued', 0], ['transcribing', 40], ['done', 100]];
        $answers = [];
        foreach ($states as [$state, $pct]) {
            $answers[] = [200, [
                'recordingId' => 'r1', 'transcriptionId' => 't1', 'state' => $state, 'attempts' => 0,
                'createdAt' => '2026-10-08T00:00:00Z', 'hasAudio' => $state === 'done',
                'progress' => ['pct' => $pct, 'processedS' => 0.0, 'segmentsDone' => 0],
            ]];
        }
        $http = new FakeHttpClient($answers);
        $vs = new VocalStack(apiKey: 'vs_key_x', baseUrl: 'http://api.test', httpClient: $http);
        $seen = [];
        $job = $vs->jobs->wait('r1', intervalS: 0, onProgress: function (array $j) use (&$seen): void {
            $seen[] = "{$j['state']}:{$j['progress']['pct']}";
        });
        $this->assertSame('done', $job['state']);
        $this->assertSame(['queued:0', 'transcribing:40', 'done:100'], $seen);
        $this->assertSame('http://api.test/v1/recordings/r1/job', $http->calls[0]['url']);
    }

    public function testEveryFrameReachesOnFrameLifecycleFramesIncluded(): void
    {
        $server = new WsServer(['frames' => [
            ['type' => 'session.loading', 'stage' => 'model'],
            ['type' => 'session.ready', 'sessionId' => 's1'],
            ['$waitEof' => true],
            ['type' => 'transcript.draft', 'segments' => [['start' => 0, 'text' => 'hel', 'speaker' => 0, 'tentative' => true]]],
            ['type' => 'transcript.committed', 'segment' => ['seq' => 0, 'start' => 0, 'end' => 1, 'text' => 'hello', 'speaker' => 0, 'words' => []]],
            ['type' => 'session.done', 'reason' => 'eof', 'stats' => ['audioS' => 1, 'processingS' => 0.5, 'realtimeFactor' => 2]],
        ]]);
        $frames = [];
        $session = RealtimeSession::connect('s1', $server->url(), onFrame: function (array $f) use (&$frames): void {
            $frames[] = $f['type'];
        });
        $before = $frames;
        $session->eof();
        $events = [];
        foreach ($session as $ev) {
            $events[] = $ev['type'];
        }
        $this->assertSame(['session.loading', 'session.ready'], $before);
        $this->assertSame(['transcript.draft', 'transcript.committed'], $events);
        $this->assertSame(['session.loading', 'session.ready', 'transcript.draft', 'transcript.committed', 'session.done'], $frames);
        $this->assertSame('eof', $session->doneReason);
        $this->assertSame(2, $session->stats['realtimeFactor']);
    }
}
