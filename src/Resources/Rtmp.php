<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/** `$vs->rtmp`. */
final class Rtmp extends Group
{
    /**
     * The account's RTMP ingest: server URL (RTMPS where available), the
     * stream key (in full only in the answer that made it,
     * `maskedStreamKey` after), whether an encoder is sending.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->t->json('GET', '/v1/rtmp');
    }

    /**
     * A stream key of the app's own, beside the account's (`name`):
     * `streamKey` is in full in this answer only.  It works while the API
     * key that made it does.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function createKey(array $fields): array
    {
        return $this->t->json('POST', '/v1/rtmp/keys', json: self::body($fields));
    }

    /**
     * The account's app stream keys, oldest first: masked, each with
     * whether an encoder is sending on it (`live`, `audio`).
     *
     * @return list<array<string, mixed>>
     */
    public function listKeys(): array
    {
        return self::many($this->t->json('GET', '/v1/rtmp/keys'), 'keys');
    }

    /** Delete an app stream key: an encoder publishing on it is dropped within seconds. */
    public function deleteKey(string $id): void
    {
        $this->t->json('DELETE', '/v1/rtmp/keys/' . self::p($id));
    }
}
