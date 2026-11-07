<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\Transport;
use VocalStack\Http\WebSocket;
use VocalStack\RealtimeSession;
use VocalStack\VocalStackException;

final class Recordings extends Group
{
    public function __construct(Transport $t, private readonly float $readyDeadline)
    {
        parent::__construct($t);
    }

    private static function path(string $id, string $rest = ''): string
    {
        return '/v1/recordings/' . self::p($id) . $rest;
    }

    public function get(string $id, ?string $listenerToken = null, ?string $shareCode = null): array
    {
        return $this->t->json('GET', self::path($id), query: Transport::listenerQuery($listenerToken, $shareCode));
    }

    public function pause(string $id): array
    {
        return $this->t->json('POST', self::path($id, '/pause'));
    }

    public function resume(string $id): array
    {
        return $this->t->json('POST', self::path($id, '/resume'));
    }

    public function stop(string $id, ?string $reason = null): array
    {
        return $this->t->json('POST', self::path($id, '/stop'), json: ['reason' => $reason]);
    }

    public function settings(string $id, array $fields = []): array
    {
        return $this->t->json('PATCH', self::path($id, '/settings'), json: self::body($fields));
    }

    public function publishToken(string $id, bool $takeover = false): array
    {
        return $this->t->json('POST', self::path($id, '/publish-token'), json: ['takeover' => $takeover]);
    }

    public function publish(array $grant, ?float $readyDeadline = null, ?callable $onFrame = null): RealtimeSession
    {
        if (empty($grant['streamUrl'])) {
            throw new VocalStackException('invalid_state', 'this grant has no streamUrl (an upload has no publisher socket)');
        }
        WebSocket::requireLibrary();
        return RealtimeSession::connect(
            (string) (($grant['sessionId'] ?? null) ?: ($grant['recording']['id'] ?? '')),
            (string) $grant['streamUrl'],
            readyDeadline: $readyDeadline ?: $this->readyDeadline,
            onFrame: $onFrame,
        );
    }

    public function archive(string $id): array
    {
        return $this->t->json('POST', self::path($id, '/archive'));
    }

    public function restore(string $id): array
    {
        return $this->t->json('POST', self::path($id, '/restore'));
    }

    public function purge(string $id): void
    {
        $this->t->json('POST', self::path($id, '/purge'));
    }

    public function archiveMany(array $ids): array
    {
        return $this->t->json('POST', '/v1/recordings' . '/archive', json: ['ids' => array_values($ids)]);
    }

    public function restoreMany(array $ids): array
    {
        return $this->t->json('POST', '/v1/recordings' . '/restore', json: ['ids' => array_values($ids)]);
    }

    public function purgeMany(array $ids): array
    {
        return $this->t->json('POST', '/v1/recordings' . '/purge', json: ['ids' => array_values($ids)]);
    }

    public function transcript(
        string $id,
        ?int $after = null,
        ?string $lang = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
    ): array {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['after' => $after, 'lang' => $lang];
        return $this->t->json('GET', self::path($id, '/transcript'), query: $query);
    }

    public function translation(
        string $id,
        string $lang,
        ?array $sentences = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
    ): array {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + [
            'lang' => $lang,
            'sentences' => $sentences ? implode(',', array_map('strval', $sentences)) : null,
        ];
        return $this->t->json('GET', self::path($id, '/translation'), query: $query);
    }

    public function summarize(string $id, ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', self::path($id, '/summarize'), idempotencyKey: $idempotencyKey);
    }

    public function summary(string $id, ?string $lang = null, ?string $listenerToken = null, ?string $shareCode = null): array
    {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['lang' => $lang];
        return $this->t->json('GET', self::path($id, '/summary'), query: $query);
    }

    public function export(
        string $id,
        string $format,
        ?string $lang = null,
        ?string $shareCode = null,
        ?string $listenerToken = null,
    ): string {
        $query = ['format' => $format, 'lang' => $lang, 'shareCode' => $shareCode, 'listenerToken' => $listenerToken];
        return $this->t->raw('GET', self::path($id, '/export'), query: $query, headers: ['accept' => '*/*'])->body;
    }

    public function audioUrl(
        string $id,
        ?string $kind = null,
        ?string $lang = null,
        ?string $format = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
    ): string {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['kind' => $kind, 'lang' => $lang, 'format' => $format];
        $resp = $this->t->raw('GET', self::path($id, '/audio'), query: $query);
        $loc = $resp->header('location');
        if (!$loc) {
            throw new VocalStackException('not_found', 'no signed URL in the redirect', status: $resp->status);
        }
        return $loc;
    }

    public function clip(string $id, float $startS, float $endS, ?string $listenerToken = null, ?string $shareCode = null): string
    {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['startS' => $startS, 'endS' => $endS];
        return $this->t->raw('GET', self::path($id, '/audio/clip'), query: $query, headers: ['accept' => '*/*'])->body;
    }

    public function voice(string $id, ?string $lang = null, ?string $listenerToken = null, ?string $shareCode = null): array
    {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['lang' => $lang];
        return array_values($this->t->json('GET', self::path($id, '/voice'), query: $query) ?? []);
    }

    public function renderVoice(
        string $id,
        array $langs,
        ?string $idempotencyKey = null,
        ?string $voice = null,
        ?array $voiceBySpeaker = null,
        ?int $near = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
        ?bool $consent = null,
        ?string $voices = null,
    ): array {
        $body = ['langs' => array_values($langs)];
        if ($consent !== null) {
            $body['consent'] = $consent;
        }
        if ($voices !== null) {
            $body['voices'] = $voices;
        }
        if ($voice !== null) {
            $body['voice'] = $voice;
        }
        if ($voiceBySpeaker !== null) {
            $body['voiceBySpeaker'] = (object) array_combine(array_map('strval', array_keys($voiceBySpeaker)), array_values($voiceBySpeaker));
        }
        if ($near !== null) {
            $body['near'] = $near;
        }
        $query = Transport::listenerQuery($listenerToken, $shareCode);
        $data = $this->t->json('POST', self::path($id, '/voice'), query: $query, json: $body, idempotencyKey: $idempotencyKey);
        return array_values($data ?? []);
    }

    public function voiceClip(
        string $id,
        int $sentence,
        string $lang,
        ?string $format = null,
        ?string $voice = null,
        ?int $part = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
        ?string $voices = null,
    ): string {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['lang' => $lang, 'voices' => $voices, 'voice' => $voice, 'format' => $format, 'part' => $part ?: null];
        return $this->t->raw('GET', self::path($id, "/voice/{$sentence}"), query: $query, headers: ['accept' => '*/*'])->body;
    }

    public function mediaToken(string $id): array
    {
        return $this->t->json('GET', self::path($id, '/media-token'));
    }

    public function events(string $id, ?int $after = null, ?int $limit = null): array
    {
        return self::allPages(function (?string $cursor) use ($id, $after, $limit) {
            $q = $cursor ? ['cursor' => $cursor, 'limit' => $limit] : ['after' => $after, 'limit' => $limit];
            return $this->t->json('GET', self::path($id, '/events'), query: $q);
        }, 'events');
    }

    public function eventsPage(string $id, ?string $cursor = null, ?int $after = null, ?int $limit = null): array
    {
        return $this->t->json('GET', self::path($id, '/events'), query: ['cursor' => $cursor, 'after' => $after, 'limit' => $limit]);
    }

    public function subscribe(
        string $id,
        ?string $lang = null,
        ?string $voice = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
        bool $asOwner = true,
    ): WebSocket {
        $url = $this->t->wsUrl(self::path($id, '/subscribe'), [
            'lang' => $lang,
            'voice' => $voice,
            ...Transport::listenerQuery($listenerToken, $shareCode),
            'role' => $asOwner ? null : 'listener',
            'device' => $this->t->deviceId,
        ]);
        return self::socket($url, $asOwner ? $this->t->authHeaders() : []);
    }
}
