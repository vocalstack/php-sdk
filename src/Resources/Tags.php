<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Tags extends Group
{
    public function list(): array
    {
        return $this->t->json('GET', '/v1/tags')['tags'] ?? [];
    }

    public function create(array $fields): array
    {
        return $this->t->json('POST', '/v1/tags', json: self::body($fields));
    }

    public function update(string $id, array $fields = []): array
    {
        return $this->t->json('PATCH', '/v1/tags/' . self::p($id), json: self::body($fields));
    }

    public function delete(string $id): void
    {
        $this->t->json('DELETE', '/v1/tags/' . self::p($id));
    }

    public function tagTranscriptions(string $id, array $add = [], array $remove = []): array
    {
        return $this->t->json('POST', '/v1/tags/' . self::p($id) . '/transcriptions', json: ['add' => $add, 'remove' => $remove]);
    }
}
