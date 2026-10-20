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

    private ?VocalStackException $error = null;
    private bool $closed = false;
    private bool $pinged = false;

    /** @var \Closure|null */
    private ?\Closure $onFrame;

    private function __construct(public readonly string $sessionId, private readonly WebSocket $ws, ?callable $onFrame = null)
    {
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

        $session = new self($sessionId, $ws, $onFrame);
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
            $session->hand($parsed);
            if ($parsed['type'] === 'session.ready') {
                return $session;
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
        $this->ws->sendBinary($pcm);
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
        $this->ws->sendText('{"type":"eof"}');
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
        $this->ws->sendText((string) json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
        $this->ws->sendText('{"type":"cancel"}');
    }

    /** Hard-close (aborts the run server-side; no resume). Prefer `eof()` to get final commits. */
    public function close(): void
    {
        $this->closed = true;
        $this->ws->close();
    }

    /**
     * The transcript events until the session ends: `session.done` (see
     * `doneReason`, `stats`) or the socket closing end it; an `error` frame
     * throws VocalStackException.
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
                    // Keepalive unanswered: the connection is gone.
                    $this->closed = true;
                    $this->ws->close(1011);
                    return;
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
            $this->closed = true;
            return self::END;
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
