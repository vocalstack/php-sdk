<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class ShareLinks extends Group
{
    public function update(string $id, array $fields = []): array
    {
        return $this->t->json('PATCH', '/v1/share-links/' . self::p($id), json: self::body($fields));
    }

    public function revoke(string $id): array
    {
        return $this->t->json('DELETE', '/v1/share-links/' . self::p($id));
    }
}
