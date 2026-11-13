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

    public function archiveMany(array $ids): array
    {
        return $this->t->json('POST', static::BASE . '/archive', json: ['ids' => array_values($ids)]);
    }

    public function restoreMany(array $ids): array
    {
        return $this->t->json('POST', static::BASE . '/restore', json: ['ids' => array_values($ids)]);
    }

    public function purgeMany(array $ids): array
    {
        return $this->t->json('POST', static::BASE . '/purge', json: ['ids' => array_values($ids)]);
    }

    public function listShareLinks(string $id): array
    {
        return self::many($this->t->json('GET', $this->path($id, '/share-links')), 'links');
    }

    public function listScripts(string $id): array
    {
        return self::many($this->t->json('GET', $this->path($id, '/scripts')), 'scripts');
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
