<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\Transport;
use VocalStack\Http\WebSocket;

abstract class ParentResource extends Group
{
    protected const BASE = '';

    protected const KEY = '';

    protected function path(string $id, string $rest = ''): string
    {
        return static::BASE . '/' . self::p($id) . $rest;
    }

    public function list(string $lifecycle = 'active'): array
    {
        return self::allPages(
            fn (?string $c) => $this->t->json('GET', static::BASE, query: ['lifecycle' => $lifecycle, 'cursor' => $c]),
            static::KEY,
        );
    }

    public function get(string $id): array
    {
        return $this->t->json('GET', $this->path($id));
    }

    public function update(string $id, array $fields = []): array
    {
        return $this->t->json('PATCH', $this->path($id), json: self::body($fields));
    }

    public function archive(string $id): array
    {
        return $this->t->json('POST', $this->path($id, '/archive'));
    }

    public function restore(string $id): array
    {
        return $this->t->json('POST', $this->path($id, '/restore'));
    }

    public function purge(string $id): void
    {
        $this->t->json('POST', $this->path($id, '/purge'));
    }

    public function listRecordings(string $id, string $lifecycle = 'active'): array
    {
        return self::allPages(
            fn (?string $c) => $this->t->json('GET', $this->path($id, '/recordings'), query: ['lifecycle' => $lifecycle, 'cursor' => $c]),
            'recordings',
        );
    }

    public function listRecordingsPage(string $id, string $lifecycle = 'active', ?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', $this->path($id, '/recordings'), query: ['lifecycle' => $lifecycle, 'cursor' => $cursor, 'limit' => $limit]);
    }

    public function startRecording(string $id, array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', $this->path($id, '/recordings'), json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    public function listShareLinks(string $id): array
    {
        return self::many($this->t->json('GET', $this->path($id, '/share-links')), 'links');
    }

    public function createShareLink(string $id, array $fields = []): array
    {
        return $this->t->json('POST', $this->path($id, '/share-links'), json: self::body($fields));
    }

    public function events(string $id, ?string $listenerToken = null, ?string $shareCode = null): WebSocket
    {
        $url = $this->t->wsUrl($this->path($id, '/events'), Transport::listenerQuery($listenerToken, $shareCode));
        return self::socket($url, $this->t->authHeaders());
    }
}
