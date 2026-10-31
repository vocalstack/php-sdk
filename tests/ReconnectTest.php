<?php

declare(strict_types=1);

namespace VocalStack\Tests;

use PHPUnit\Framework\TestCase;
use VocalStack\RealtimeSession;
use VocalStack\Tests\Support\WsServer;
use VocalStack\VocalStackException;

final class ReconnectTest extends TestCase
{
    private const READY = ['type' => 'session.ready'];

    private static function done(): array
    {
        return ['type' => 'session.done', 'reason' => 'eof', 'stats' => ['audioS' => 1, 'processingS' => 0.5, 'realtimeFactor' => 2]];
    }

    private static function committed(string $text): array
    {
        return ['type' => 'transcript.committed', 'segment' => ['seq' => 0, 'start' => 0, 'end' => 1, 'text' => $text, 'speaker' => 0, 'words' => []]];
    }

    public function testARestartCloseReconnectsToTheSameUrlAndTheSessionGoesOn(): void
    {
        $server = new WsServer(['connections' => [
            ['frames' => [self::READY, ['$delayMs' => 300], ['$close' => RealtimeSession::RESTART_CLOSE]]],
            ['frames' => [self::READY, ['$waitEof' => true], self::committed('carried on'), self::done()]],
        ]]);
        $session = RealtimeSession::connect('s', $server->url());
        $session->sendAudio(str_repeat("\0", 3200));
        $this->assertSame([], $session->poll(2.5));
        $this->assertFalse($session->ended());
        $session->sendAudio(str_repeat("\0", 1600));
        $session->eof();
        $texts = [];
        foreach ($session as $ev) {
            $texts[] = $ev['segment']['text'];
        }
        $this->assertSame(['carried on'], $texts);
        $this->assertSame('eof', $session->doneReason);
        $byConn = [];
        foreach ($server->received() as $f) {
            if ($f['op'] === 2) {
                $byConn[$f['conn']] = ($byConn[$f['conn']] ?? 0) + $f['payload'];
            }
        }
        $this->assertSame([0 => 3200, 1 => 1600], $byConn, 'audio on the first socket, then on the second');
    }

    public function testRestartClosesPastTheTriesAreEngineLost(): void
    {
        $again = ['frames' => [self::READY, ['$close' => RealtimeSession::RESTART_CLOSE]]];
        $server = new WsServer(['connections' => [$again, $again, $again, $again]]);
        $session = RealtimeSession::connect('s', $server->url(), readyDeadline: 2.0);
        try {
            iterator_to_array($session->events());
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('engine_lost', $e->errorCode);
            $this->assertStringContainsString('1012', $e->getMessage());
        }
        $this->assertTrue($session->ended());
    }

    public function testAnyOtherCloseIsEngineLostAtOnce(): void
    {
        $server = new WsServer(['connections' => [
            ['frames' => [self::READY, ['$close' => 1011]]],
            ['frames' => [self::READY, self::done()]],
        ]]);
        $session = RealtimeSession::connect('s', $server->url());
        $t0 = microtime(true);
        try {
            iterator_to_array($session->events());
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('engine_lost', $e->errorCode);
            $this->assertStringContainsString('1011', $e->getMessage());
        }
        $this->assertLessThan(0.9, microtime(true) - $t0, 'no reconnect wait');
    }
}
