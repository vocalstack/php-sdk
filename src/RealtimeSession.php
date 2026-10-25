<?php

declare(strict_types=1);

namespace VocalStack;

use VocalStack\Http\ConnectionException;
use VocalStack\Http\WebSocket;
use VocalStack\Http\WebSocketRefused;
use VocalStack\Http\WebSocketTimeout;

/**
 * A realtime session's socket: a stream of transcript events.
 *
 * `events()` (or `foreach ($session as $event)`) yields the
 * `transcript.*` frames as arrays (`transcript.committed`,
 * `transcript.draft`, `transcript.words`, ...); lifecycle frames are
 * handled internally (`session.loading`/`ready` before iteration begins,
 * `session.done`/`error` ending it). Unknown frame types are ignored.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class RealtimeSession implements \IteratorAggregate
{
    public const DEFAULT_READY_DEADLINE_S = 30.0;

    /** An idle socket is pinged this often; a ping unanswered this long ends it (as Python's websockets does). */
    public const KEEPALIVE_S = 20.0;

    /** Every frame type the contract defines; any other is ignored (forward compatibility). */
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

    /** How the session ended (`eof`, `max_duration`, `cancelled`), once `session.done` came. */
    public ?string $doneReason = null;

    /** `session.done`'s stats (`audioS`, `processingS`, `realtimeFactor`). */
    public ?array $stats = null;

    /**
     * The relay's restart close (a draining relay, a blip of the proxy in
     * front of it): the client reconnects with the same stream URL and the
     * session goes on. The only close the SDK reconnects on.
     */
    public const RESTART_CLOSE = 1012;
    /** Reconnects after a restart close, 1, 2, 4 s apart. */
    private const RESTART_TRIES = 3;
    /** What failed to send while the socket was down is kept, up to this much, for the next one. */
    private const MAX_PENDING_BYTES = 4 * 1024 * 1024;

    private ?VocalStackException $error = null;
    private bool $closed = false;
    private bool $pinged = false;
    private int $reconnects = 0;
    /** @var list<array{0: bool, 1: string}> (binary?, data) sends the closed socket refused */
    private array $pending = [];
    private int $pendingBytes = 0;

    /** @var \Closure|null */
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

    /**
     * Open the WS and wait for `session.ready`.
     *
     * `onFrame` sees every server text frame as it arrives, parsed (an
     * array): the lifecycle frames the iterator keeps to itself
     * (`session.loading`, `session.ready`, `session.done`, `error`) and the
     * transcript frames alike. For logs and protocol views; iterate the
     * session to read the transcript.
     *
     * The deadline covers the gap since the LAST server frame: expiry
     * with no frames seen synthesizes `capacity_exceeded` (the upgrade
     * hung in the proxy's queue); once `session.loading` heartbeats flow,
     * expiry maps to `engine_unavailable` instead.
     */
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

    /** Open `$streamUrl` and wait for `session.ready`: the socket, or the error the connect is refused with. */
    private static function openReady(string $streamUrl, float $readyDeadline, \Closure $hand): WebSocket
    {
        try {
            // The upgrade waits in the proxy's queue while every engine is
            // busy: the ready deadline covers it.
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
            // Any data frame re-arms the deadline.
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

    /**
     * A refused socket upgrade as an error. A 503 carrying the error body
     * with `unavailable` (the database is waking; retry shortly) says so;
     * any other 503 (a bare one from a saturated proxy, or no body at all)
     * is `capacity_exceeded`. So is a 502 or 504 without the API's error
     * body: the proxy in front of the engines found none free in time.
     * Every other status is its own envelope.
     */
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

    /**
     * Parse one WS text frame. Null for unknown frame types (forward
     * compatibility) and for anything that is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
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

    /**
     * Raw PCM per the session's negotiated format (f32le 16 kHz mono).
     *
     * Sends racing the end of the session are silently dropped: the event
     * iterator is the authority on how the session ended.
     */
    public function sendAudio(string $pcm): void
    {
        $this->send(true, $pcm);
    }

    /** Send, or keep it (bounded) when the socket is down: a restart close reconnects and sends it. */
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

    /**
     * The relay restarting: open the same stream URL again (1, 2, 4 s
     * apart), send what waited, and go on as the same session. False when
     * out of tries.
     */
    private function reconnect(): bool
    {
        while ($this->reconnects < self::RESTART_TRIES && !$this->closed && $this->streamUrl !== null) {
            usleep((int) (1_000_000 * (2 ** $this->reconnects)));
            $this->reconnects++;
            try {
                $this->ws = self::openReady($this->streamUrl, $this->readyDeadline, fn (array $f) => $this->hand($f));
            } catch (VocalStackException $e) {
                // A refusal that will not pass (a bad or used token) ends it now.
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

    /**
     * Upload an encoded audio file (file sessions only), then `eof()`.
     *
     * A path or a stream is streamed rather than read whole: uploads run
     * to hundreds of megabytes.
     *
     * @param string|Upload|resource $source a path, `Upload::bytes(...)`, or a stream
     */
    public function sendFile(mixed $source, int $chunkBytes = 256 * 1024): void
    {
        foreach (Upload::from($source)->chunks($chunkBytes) as $chunk) {
            $this->sendAudio($chunk);
        }
        $this->eof();
    }

    /**
     * End of source: the server flushes, emits final commits, then
     * `session.done`, after which iteration completes.
     */
    public function eof(): void
    {
        $this->send(false, '{"type":"eof"}');
    }

    /**
     * Options update during the realtime session; omitted fields are unchanged.
     *
     * `latencyMode` (`auto` | `fast` | `accuracy`) switches the decode
     * model policy from the next engine step; `spokenLanguage` changes the
     * transcription language from the next engine step (`""` or `auto` =
     * auto-detect); `speakerLabels` turns speaker labelling on (`on` or
     * `auto`) or off (`off`) from the next engine step; `translate`
     * replaces the whole target-language list (`[]` turns translation
     * off). Applies to subsequent transcript: already-delivered segments
     * are not re-transcribed, re-attributed or re-translated.
     *
     * @param list<string>|null $translate
     */
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

    /**
     * Stop now, abandoning the rest of the audio.
     *
     * The server ends the session with `session.done` / `cancelled` and
     * frees the machine immediately; everything committed before the stop
     * stays valid. Prefer this to `close()` for a deliberate stop: it is a
     * clean end that still reports stats.
     */
    public function cancel(): void
    {
        $this->send(false, '{"type":"cancel"}');
    }

    /** Hard-close (aborts the run server-side; no resume). Prefer `eof()` to get final commits. */
    public function close(): void
    {
        $this->closed = true;
        $this->ws->close();
    }

    /**
     * The transcript events until the session ends: `session.done` (see
     * `doneReason`, `stats`) or `close()` end it; an `error` frame throws
     * VocalStackException, and so does the socket closing before either
     * (`engine_lost`).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function events(): \Generator
    {
        while (true) {
            $step = $this->step(self::KEEPALIVE_S);
            if ($step === self::END) {
                return;
            }
            if ($step === self::TIMEOUT) {
                if ($this->pinged) {
                    // Keepalive unanswered: the connection is gone, and
                    // the engine with it.
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

    /** @return \Generator<int, array<string, mixed>> */
    public function getIterator(): \Generator
    {
        return $this->events();
    }

    /**
     * The transcript events that arrive within `$timeout` seconds (it
     * waits the whole time unless the session ends): how a synchronous
     * caller reads while it sends (`paceRealtime()` uses it). An `error`
     * frame throws.
     *
     * @return list<array<string, mixed>>
     */
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

    /** Whether the session has ended (`session.done`, an error, or the socket closed). */
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

    /**
     * One frame's worth of reading: an event, or what happened instead.
     *
     * @return int|array<string, mixed>
     */
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
            // The relay restarting: reconnect and go on (`RESTART_CLOSE`).
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
            // Closed under us (a `close()` of ours ends before the read)
            // before the session said how it ended: not a quiet end of the
            // transcript, the engine was lost.
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

    /**
     * A ready socket that closed with neither `session.done` nor an `error`
     * frame, and not by `close()`: the engine went away mid-stream (its
     * machine stopped or crashed) or the connection to it dropped. What was
     * committed before stays; nothing after it was transcribed. Retryable:
     * open a new realtime session to go on. (The relay's restart close,
     * 1012, is not this: the session reconnects and goes on, `RESTART_CLOSE`.)
     */
    private static function engineLost(?int $closeCode = null): VocalStackException
    {
        $how = $closeCode ? " (close {$closeCode})" : '';
        return new VocalStackException(
            'engine_lost',
            "The connection to the transcription engine closed before the realtime session finished{$how}; open a new realtime session to continue.",
            retryable: true,
        );
    }

    /** Hands a frame to onFrame; a throwing callback never breaks the socket. */
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
