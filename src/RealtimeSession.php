<?php

declare(strict_types=1);

namespace VocalStack;

use VocalStack\Http\ConnectionException;
use VocalStack\Http\WebSocket;
use VocalStack\Http\WebSocketRefused;
use VocalStack\Http\WebSocketTimeout;

final class RealtimeSession implements \IteratorAggregate
{
    public const DEFAULT_READY_DEADLINE_S = 30.0;

    public const KEEPALIVE_S = 20.0;

    public const SERVER_FRAME_TYPES = [
        'session.loading', 'session.ready', 'transcript.committed', 'transcript.draft', 'transcript.words',
        'transcript.amended', 'transcript.latency', 'transcript.speaker', 'transcript.speakerGender',
        'transcript.speakerIdentified', 'transcript.sentence', 'transcript.translated', 'transcript.voice',
        'transcript.voiceStatus', 'transcript.progress', 'stream.pull', 'source.progress', 'source.prepared',
        'session.done', 'error',
    ];

    private const TIMEOUT = 0;
    private const SKIP = 1;
    private const END = 2;

    public ?string $doneReason = null;

    public ?array $stats = null;

    public const RESTART_CLOSE = 1012;
    private const RESTART_TRIES = 3;
    private const MAX_PENDING_BYTES = 4 * 1024 * 1024;

    private ?VocalStackException $error = null;
    private bool $closed = false;
    private bool $pinged = false;
    private int $reconnects = 0;
    private array $pending = [];
    private int $pendingBytes = 0;

    private ?\Closure $onFrame;

    private function __construct(
        public readonly string $sessionId,
        private WebSocket $ws,
        ?callable $onFrame = null,
        private readonly ?string $streamUrl = null,
        private readonly float $readyDeadline = self::DEFAULT_READY_DEADLINE_S,
    ) {
        $this->onFrame = $onFrame === null ? null : \Closure::fromCallable($onFrame);
    }

    public static function connect(
        string $sessionId,
        string $streamUrl,
        float $readyDeadline = self::DEFAULT_READY_DEADLINE_S,
        ?callable $onFrame = null,
    ): self {
        $hand = $onFrame === null ? null : \Closure::fromCallable($onFrame);
        $ws = self::openReady($streamUrl, $readyDeadline, function (array $f) use ($hand): void {
            if ($hand !== null) {
                try {
                    $hand($f);
                } catch (\Throwable) {
                }
            }
        });
        return new self($sessionId, $ws, $onFrame, $streamUrl, $readyDeadline);
    }

    private static function openReady(string $streamUrl, float $readyDeadline, \Closure $hand): WebSocket
    {
        try {
            $ws = WebSocket::connect($streamUrl, [], $readyDeadline);
        } catch (WebSocketRefused $e) {
            throw self::upgradeRefused($e->status, $e->body);
        } catch (WebSocketTimeout $e) {
            throw new VocalStackException(
                'capacity_exceeded',
                "the upgrade was not answered within {$readyDeadline}s",
                retryable: true,
                previous: $e,
            );
        } catch (ConnectionException $e) {
            throw new VocalStackException('engine_unavailable', 'connect failed: ' . $e->getMessage(), retryable: true, previous: $e);
        }

        $sawFrame = false;
        $deadline = microtime(true) + $readyDeadline;
        while (true) {
            $frame = $ws->receive(max(0.0, $deadline - microtime(true)));
            if ($frame === null) {
                $ws->close();
                if ($sawFrame) {
                    throw new VocalStackException(
                        'engine_unavailable',
                        "engine did not become ready within {$readyDeadline}s",
                        retryable: true,
                    );
                }
                throw VocalStackException::capacityExceeded(
                    "no response from stream host within {$readyDeadline}s (fleet may be saturated)"
                );
            }
            [$kind, $payload] = $frame;
            if ($kind === WebSocket::KIND_CLOSE) {
                throw new VocalStackException('engine_unavailable', 'connection closed before ready', retryable: true);
            }
            if ($kind === WebSocket::KIND_PING || $kind === WebSocket::KIND_PONG) {
                continue;
            }
            $deadline = microtime(true) + $readyDeadline;
            if ($kind !== WebSocket::KIND_TEXT) {
                continue;
            }
            $sawFrame = true;
            $parsed = self::parseServerFrame($payload);
            if ($parsed === null) {
                continue;
            }
            $hand($parsed);
            if ($parsed['type'] === 'session.ready') {
                return $ws;
            }
            if ($parsed['type'] === 'error') {
                $ws->close();
                throw new VocalStackException(
                    (string) ($parsed['code'] ?? 'internal'),
                    (string) ($parsed['message'] ?? ''),
                    retryable: (bool) ($parsed['retryable'] ?? false),
                );
            }
        }
    }

    public static function upgradeRefused(int $status, ?string $body): VocalStackException
    {
        $parsed = null;
        if ($body !== null && $body !== '') {
            try {
                $parsed = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $parsed = null;
            }
        }
        if ($status === 503) {
            if (is_array($parsed) && ($parsed['code'] ?? null) === 'unavailable') {
                return VocalStackException::fromEnvelope($parsed, $status);
            }
            return VocalStackException::capacityExceeded('stream host answered 503 (fleet saturated)');
        }
        $ours = is_array($parsed) && is_string($parsed['code'] ?? null);
        if (($status === 502 || $status === 504) && !$ours) {
            return VocalStackException::capacityExceeded("stream host answered {$status} (no engine answered in time)");
        }
        return VocalStackException::fromEnvelope($parsed, $status);
    }

    public static function parseServerFrame(string $text): ?array
    {
        try {
            $raw = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($raw) || !in_array($raw['type'] ?? null, self::SERVER_FRAME_TYPES, true)) {
            return null;
        }
        return $raw;
    }

    public function sendAudio(string $pcm): void
    {
        $this->send(true, $pcm);
    }

    private function send(bool $binary, string $data): void
    {
        $ok = $binary ? $this->ws->sendBinary($data) : $this->ws->sendText($data);
        if ($ok || $this->closed || $this->streamUrl === null) {
            return;
        }
        if ($this->pendingBytes + strlen($data) <= self::MAX_PENDING_BYTES) {
            $this->pending[] = [$binary, $data];
            $this->pendingBytes += strlen($data);
        }
    }

    private function reconnect(): bool
    {
        while ($this->reconnects < self::RESTART_TRIES && !$this->closed && $this->streamUrl !== null) {
            usleep((int) (1_000_000 * (2 ** $this->reconnects)));
            $this->reconnects++;
            try {
                $this->ws = self::openReady($this->streamUrl, $this->readyDeadline, fn (array $f) => $this->hand($f));
            } catch (VocalStackException $e) {
                if (!$e->retryable) {
                    throw $e;
                }
                continue;
            }
            $waiting = $this->pending;
            $this->pending = [];
            $this->pendingBytes = 0;
            foreach ($waiting as [$binary, $data]) {
                $this->send($binary, $data);
            }
            return true;
        }
        return false;
    }

    public function sendFile(mixed $source, int $chunkBytes = 256 * 1024): void
    {
        foreach (Upload::from($source)->chunks($chunkBytes) as $chunk) {
            $this->sendAudio($chunk);
        }
        $this->eof();
    }

    public function eof(): void
    {
        $this->send(false, '{"type":"eof"}');
    }

    public function update(
        ?string $latencyMode = null,
        ?string $spokenLanguage = null,
        ?string $speakerLabels = null,
        ?array $translate = null,
    ): void {
        $frame = ['type' => 'session.update'];
        if ($latencyMode !== null) {
            $frame['latencyMode'] = $latencyMode;
        }
        if ($spokenLanguage !== null) {
            $frame['spokenLanguage'] = $spokenLanguage;
        }
        if ($speakerLabels !== null) {
            $frame['speakerLabels'] = $speakerLabels;
        }
        if ($translate !== null) {
            $frame['translate'] = array_values($translate);
        }
        $this->send(false, (string) json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function cancel(): void
    {
        $this->send(false, '{"type":"cancel"}');
    }

    public function close(): void
    {
        $this->closed = true;
        $this->ws->close();
    }

    public function events(): \Generator
    {
        while (true) {
            $step = $this->step(self::KEEPALIVE_S);
            if ($step === self::END) {
                return;
            }
            if ($step === self::TIMEOUT) {
                if ($this->pinged) {
                    $this->closed = true;
                    $this->ws->close(1011);
                    $this->error = self::engineLost();
                    throw $this->error;
                }
                $this->pinged = $this->ws->ping();
                continue;
            }
            if (is_array($step)) {
                yield $step;
            }
        }
    }

    public function getIterator(): \Generator
    {
        return $this->events();
    }

    public function poll(float $timeout = 0.0): array
    {
        $out = [];
        $deadline = microtime(true) + $timeout;
        while (true) {
            $step = $this->step(max(0.0, $deadline - microtime(true)));
            if ($step === self::END || $step === self::TIMEOUT) {
                return $out;
            }
            if (is_array($step)) {
                $out[] = $step;
            }
        }
    }

    public function ended(): bool
    {
        return $this->doneReason !== null || $this->error !== null || $this->closed;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->ws->close();
        }
    }

    private function step(float $timeout): int|array
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        if ($this->doneReason !== null || $this->closed) {
            return self::END;
        }
        $frame = $this->ws->receive($timeout);
        if ($frame === null) {
            return self::TIMEOUT;
        }
        $this->pinged = false;
        [$kind, $payload] = $frame;
        if ($kind === WebSocket::KIND_CLOSE) {
            if ($payload === (string) self::RESTART_CLOSE) {
                try {
                    if ($this->reconnect()) {
                        return self::SKIP;
                    }
                } catch (VocalStackException $e) {
                    $this->closed = true;
                    $this->error = $e;
                    throw $e;
                }
                $this->closed = true;
                $this->pending = [];
                $this->error = self::engineLost(self::RESTART_CLOSE);
                throw $this->error;
            }
            $this->closed = true;
            $this->error = self::engineLost($payload === '' ? null : (int) $payload);
            throw $this->error;
        }
        if ($kind !== WebSocket::KIND_TEXT) {
            return self::SKIP;
        }
        $parsed = self::parseServerFrame($payload);
        if ($parsed === null) {
            return self::SKIP;
        }
        $this->hand($parsed);
        $type = (string) $parsed['type'];
        if ($type === 'session.done') {
            $this->doneReason = (string) ($parsed['reason'] ?? '');
            $this->stats = is_array($parsed['stats'] ?? null) ? $parsed['stats'] : null;
            return self::END;
        }
        if ($type === 'error') {
            $this->error = new VocalStackException(
                (string) ($parsed['code'] ?? 'internal'),
                (string) ($parsed['message'] ?? ''),
                retryable: (bool) ($parsed['retryable'] ?? false),
            );
            throw $this->error;
        }
        if (str_starts_with($type, 'transcript.')) {
            return $parsed;
        }
        return self::SKIP;
    }

    private static function engineLost(?int $closeCode = null): VocalStackException
    {
        $how = $closeCode ? " (close {$closeCode})" : '';
        return new VocalStackException(
            'engine_lost',
            "The connection to the transcription engine closed before the realtime session finished{$how}; open a new realtime session to continue.",
            retryable: true,
        );
    }

    private function hand(array $frame): void
    {
        if ($this->onFrame === null) {
            return;
        }
        try {
            ($this->onFrame)($frame);
        } catch (\Throwable) {
        }
    }
}
