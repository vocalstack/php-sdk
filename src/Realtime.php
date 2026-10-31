<?php

declare(strict_types=1);

namespace VocalStack;

use VocalStack\Http\Transport;
use VocalStack\Resources\Group;

final class Realtime extends Group
{
    public function __construct(Transport $t, private readonly float $readyDeadline)
    {
        parent::__construct($t);
    }

    public function createSession(array $options = []): array
    {
        return $this->t->json('POST', '/v1/realtime/sessions', json: self::body($options));
    }

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

    public function capabilities(): array
    {
        return $this->t->json('GET', '/v1/realtime/capabilities');
    }

    public function warm(string $clientKind, bool $canStart, ?string $spokenLanguage = null): void
    {
        $body = ['clientKind' => $clientKind, 'canStart' => $canStart];
        if ($spokenLanguage !== null) {
            $body['spokenLanguage'] = $spokenLanguage;
        }
        $this->t->json('POST', '/v1/realtime/warm', json: $body);
    }

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
