<?php

declare(strict_types=1);

namespace VocalStack;

use VocalStack\Http\Transport;
use VocalStack\Resources\Group;

/**
 * `$vs->realtime`: a realtime session is a signed token plus one socket
 * (RealtimeSession, over the public WS frame protocol; a recording's
 * publisher stream is one too). Nothing is stored: the transcript is what
 * the socket sends.
 */
final class Realtime extends Group
{
    public function __construct(Transport $t, private readonly float $readyDeadline)
    {
        parent::__construct($t);
    }

    /**
     * `POST /v1/realtime/sessions`: validate the options, get a signed
     * `streamUrl`. The options are SessionOptions, as on the wire
     * (`spokenLanguage`, `speakerLabels`, `latencyMode`, `events`, `input`, ...).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> `{ sessionId, streamUrl, expiresAt }`
     */
    public function createSession(array $options = []): array
    {
        return $this->t->json('POST', '/v1/realtime/sessions', json: self::body($options));
    }

    /**
     * `createSession`, then open the socket and wait for `session.ready`.
     * `onFrame` sees every server frame, lifecycle included (see
     * RealtimeSession::connect()).
     *
     * @param array<string, mixed> $options
     */
    public function connect(array $options = [], ?float $readyDeadline = null, ?callable $onFrame = null): RealtimeSession
    {
        Http\WebSocket::requireLibrary();
        $created = $this->createSession($options);
        return RealtimeSession::connect(
            (string) $created['sessionId'],
            (string) $created['streamUrl'],
            readyDeadline: $readyDeadline ?: $this->readyDeadline,
            onFrame: $onFrame,
        );
    }

    /**
     * Languages, audio formats and limits.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return $this->t->json('GET', '/v1/realtime/capabilities');
    }

    /**
     * Stream an encoded file (mp3, wav, flac, ogg, m4a...; a path, an
     * `Upload::bytes(...)` or a stream) through a realtime session and
     * collect the transcript. Nothing is stored; for a stored, shareable
     * transcription use `$vs->transcriptions->create(file: ...)`.
     *
     * @param string|Upload|resource $source
     * @param array<string, mixed> $options SessionOptions besides `input`
     */
    public function transcribeFile(
        mixed $source,
        array $options = [],
        ?callable $onProgress = null,
        ?callable $onCommit = null,
        ?float $readyDeadline = null,
    ): RealtimeTranscript {
        $session = $this->connect(['input' => ['kind' => 'file']] + $options, $readyDeadline);
        try {
            $session->sendFile($source);
            return self::collect($session, $onProgress, $onCommit);
        } finally {
            $session->close();
        }
    }

    /**
     * The same for a link the engine fetches itself.
     *
     * @param array<string, mixed> $options SessionOptions besides `input`
     */
    public function transcribeUrl(
        string $url,
        array $options = [],
        ?callable $onProgress = null,
        ?callable $onCommit = null,
        ?float $readyDeadline = null,
    ): RealtimeTranscript {
        $session = $this->connect(['input' => ['kind' => 'url', 'url' => $url]] + $options, $readyDeadline);
        try {
            return self::collect($session, $onProgress, $onCommit);
        } finally {
            $session->close();
        }
    }

    private static function collect(RealtimeSession $session, ?callable $onProgress, ?callable $onCommit): RealtimeTranscript
    {
        $segments = [];
        foreach ($session->events() as $event) {
            if ($event['type'] === 'transcript.committed') {
                $segments[] = $event['segment'];
                if ($onCommit !== null) {
                    $onCommit($event['segment']);
                }
            } elseif ($event['type'] === 'transcript.progress' && $onProgress !== null) {
                $onProgress($event);
            }
        }
        return new RealtimeTranscript($segments, $session->doneReason, $session->stats);
    }
}
