<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/** `$vs->broadcasts`: the links listeners open. */
final class Broadcasts extends ParentResource
{
    protected const BASE = '/v1/broadcasts';
    protected const KEY = 'broadcasts';

    /**
     * @param array<string, mixed> $fields CreateBroadcast (`name` required)
     * @return array<string, mixed>
     */
    public function create(array $fields, ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', self::BASE, json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    /**
     * A stream the broadcast can record from (a YouTube live, a radio address).
     *
     * @param array<string, mixed> $fields CreateAudioSource
     * @return array<string, mixed>
     */
    public function addAudioSource(string $id, array $fields): array
    {
        return $this->t->json('POST', $this->path($id, '/audio-sources'), json: self::body($fields));
    }

    /** @return array<string, mixed> */
    public function removeAudioSource(string $id, string $sourceId): array
    {
        return $this->t->json('DELETE', $this->path($id, '/audio-sources/' . self::p($sourceId)));
    }

    /** @return array<string, mixed> */
    public function checkAudioSource(string $id, string $sourceId, bool $fresh = false): array
    {
        return $this->t->json(
            'GET',
            $this->path($id, '/audio-sources/' . self::p($sourceId) . '/check'),
            query: ['fresh' => $fresh ? true : null],
        );
    }
}
