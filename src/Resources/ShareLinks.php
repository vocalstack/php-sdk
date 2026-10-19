<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/** `$vs->shareLinks`: named public links (create and list them on their owner). */
final class ShareLinks extends Group
{
    /**
     * @param array<string, mixed> $fields UpdateShareLink
     * @return array<string, mixed>
     */
    public function update(string $id, array $fields = []): array
    {
        return $this->t->json('PATCH', '/v1/share-links/' . self::p($id), json: self::body($fields));
    }

    /** @return array<string, mixed> */
    public function revoke(string $id): array
    {
        return $this->t->json('DELETE', '/v1/share-links/' . self::p($id));
    }
}
