<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/** `$vs->tags`: the account's own labels for its transcriptions; each transcription lists its own in `tags`. */
final class Tags extends Group
{
    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->t->json('GET', '/v1/tags')['tags'] ?? [];
    }

    /**
     * @param array<string, mixed> $fields CreateTag: `name`, optional `color`
     * @return array<string, mixed>
     */
    public function create(array $fields): array
    {
        return $this->t->json('POST', '/v1/tags', json: self::body($fields));
    }

    /**
     * @param array<string, mixed> $fields UpdateTag
     * @return array<string, mixed>
     */
    public function update(string $id, array $fields = []): array
    {
        return $this->t->json('PATCH', '/v1/tags/' . self::p($id), json: self::body($fields));
    }

    /** Delete a tag: it comes off every transcription it is on. */
    public function delete(string $id): void
    {
        $this->t->json('DELETE', '/v1/tags/' . self::p($id));
    }

    /**
     * Put the tag on the transcriptions in `add` and take it off those in `remove`.
     *
     * @param list<string> $add
     * @param list<string> $remove
     * @return array<string, mixed>
     */
    public function tagTranscriptions(string $id, array $add = [], array $remove = []): array
    {
        return $this->t->json('POST', '/v1/tags/' . self::p($id) . '/transcriptions', json: ['add' => $add, 'remove' => $remove]);
    }
}
