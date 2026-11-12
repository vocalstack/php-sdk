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

    public function testSocketsThatStayedUpStartTheTriesAnew(): void
    {
        $steady = new \ReflectionProperty(RealtimeSession::class, 'steadyS');
        $before = $steady->getValue();
        $steady->setValue(null, 0.2);
        try {
            $again = ['frames' => [self::READY, ['$delayMs' => 300], ['$close' => RealtimeSession::RESTART_CLOSE]]];
            $server = new WsServer(['connections' => [
                $again, $again, $again, $again, $again,
                ['frames' => [self::READY, ['$waitEof' => true], self::committed('still here'), self::done()]],
            ]]);
            $session = RealtimeSession::connect('s', $server->url(), readyDeadline: 2.0);
            $this->assertSame([], $session->poll(8.0));
            $this->assertFalse($session->ended(), 'five restart closes, each after a steady socket');
            $session->eof();
            $texts = [];
            foreach ($session as $ev) {
                $texts[] = $ev['segment']['text'];
            }
            $this->assertSame(['still here'], $texts);
            $this->assertSame('eof', $session->doneReason);
        } finally {
            $steady->setValue(null, $before);
        }
    }

    private static function lost(array $then): WsServer
    {
        $again = ['rejectUpgrade' => ['status' => 503, 'body' => ['code' => 'engine_unavailable', 'message' => 'The connection to this session was lost and is being restored. Try again in a moment.', 'data' => ['retryable' => true]]]];
        $gone = ['rejectUpgrade' => ['status' => 410, 'body' => ['code' => 'session_expired', 'message' => 'session token connect-by deadline has passed', 'data' => ['retryable' => false]]]];
        $served = ['frames' => [self::READY, ['$waitEof' => true], self::committed('carried on'), self::done()]];
        $drop = ['frames' => [self::READY, ['$delayMs' => 200], ['$drop' => true]]];
        $connections = [$drop];
        foreach ($then as $how) {
            $connections[] = ['again' => $again, 'gone' => $gone, 'served' => $served, 'drop' => $drop][$how];
        }
        return new WsServer(['connections' => $connections]);
    }

    private static function quickWaits(): \Closure
    {
        $waits = new \ReflectionProperty(RealtimeSession::class, 'lostWaitsS');
        $before = $waits->getValue();
        $waits->setValue(null, [0.05, 0.05, 0.05, 0.05]);
        $steady = new \ReflectionProperty(RealtimeSession::class, 'steadyS');
        $steadyBefore = $steady->getValue();
        $steady->setValue(null, 0.1);
        return function () use ($waits, $before, $steady, $steadyBefore): void {
            $waits->setValue(null, $before);
            $steady->setValue(null, $steadyBefore);
        };
    }

    private static function quickWaitsOnly(): \Closure
    {
        $waits = new \ReflectionProperty(RealtimeSession::class, 'lostWaitsS');
        $before = $waits->getValue();
        $waits->setValue(null, [0.05, 0.05, 0.05, 0.05]);
        return function () use ($waits, $before): void {
            $waits->setValue(null, $before);
        };
    }

    public function testASocketLostWithNoCloseFrameSoonAfterItOpenedIsOpenedAgainOnce(): void
    {
        $restore = self::quickWaitsOnly();
        try {
            $server = self::lost(['served']);
            $session = RealtimeSession::connect('s', $server->url(), readyDeadline: 2.0);
            $this->assertSame([], $session->poll(1.5));
            $this->assertFalse($session->ended(), 'the server died in the session\'s first seconds; it went on');
            $session->eof();
            $texts = [];
            foreach ($session as $ev) {
                $texts[] = $ev['segment']['text'];
            }
            $this->assertSame(['carried on'], $texts);
            $this->assertSame('eof', $session->doneReason);
        } finally {
            $restore();
        }
    }

    public function testASecondYoungSocketLostIsEngineLostSoADroppingServerCannotLoopTheClient(): void
    {
        $restore = self::quickWaitsOnly();
        try {
            $server = self::lost(['drop', 'served']);
            $session = RealtimeSession::connect('s', $server->url(), readyDeadline: 2.0);
            try {
                iterator_to_array($session->events());
                $this->fail('no exception');
            } catch (VocalStackException $e) {
                $this->assertSame('engine_lost', $e->errorCode);
            }
            $this->assertTrue($session->ended());
        } finally {
            $restore();
        }
    }

    public function testASocketLostWithNoCloseFrameIsOpenedAgainWhileTheServerSaysTryAgain(): void
    {
        $restore = self::quickWaits();
        try {
            $server = self::lost(['again', 'again', 'served']);
            $session = RealtimeSession::connect('s', $server->url(), readyDeadline: 2.0);
            $this->assertSame([], $session->poll(1.5));
            $this->assertFalse($session->ended(), 'the server its socket was on died; the session went on');
            $session->eof();
            $texts = [];
            foreach ($session as $ev) {
                $texts[] = $ev['segment']['text'];
            }
            $this->assertSame(['carried on'], $texts);
            $this->assertSame('eof', $session->doneReason);
        } finally {
            $restore();
        }
    }

    public function testASocketLostWithNoCloseFrameIsEngineLostWhenItCannotGoOn(): void
    {
        $restore = self::quickWaits();
        try {
            foreach ([['gone', 'served'], ['again', 'again', 'again', 'again', 'served']] as $then) {
                $server = self::lost($then);
                $session = RealtimeSession::connect('s', $server->url(), readyDeadline: 2.0);
                try {
                    iterator_to_array($session->events());
                    $this->fail('no exception');
                } catch (VocalStackException $e) {
                    $this->assertSame('engine_lost', $e->errorCode, implode(',', $then));
                }
                $this->assertTrue($session->ended());
            }
        } finally {
            $restore();
        }
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
