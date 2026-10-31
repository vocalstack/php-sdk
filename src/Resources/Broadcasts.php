<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Broadcasts extends ParentResource
{
    protected const BASE = '/v1/broadcasts';
    protected const KEY = 'broadcasts';

    public function create(array $fields, ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', self::BASE, json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    public function addAudioSource(string $id, array $fields): array
    {
        return $this->t->json('POST', $this->path($id, '/audio-sources'), json: self::body($fields));
    }

    public function removeAudioSource(string $id, string $sourceId): array
    {
        return $this->t->json('DELETE', $this->path($id, '/audio-sources/' . self::p($sourceId)));
    }

    public function checkAudioSource(string $id, string $sourceId, bool $fresh = false): array
    {
        return $this->t->json(
            'GET',
            $this->path($id, '/audio-sources/' . self::p($sourceId) . '/check'),
            query: ['fresh' => $fresh ? true : null],
        );
    }
}
