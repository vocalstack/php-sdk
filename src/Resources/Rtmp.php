<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Rtmp extends Group
{
    public function status(): array
    {
        return $this->t->json('GET', '/v1/rtmp');
    }

    public function createKey(array $fields): array
    {
        return $this->t->json('POST', '/v1/rtmp/keys', json: self::body($fields));
    }

    public function listKeys(): array
    {
        return self::many($this->t->json('GET', '/v1/rtmp/keys'), 'keys');
    }

    public function deleteKey(string $id): void
    {
        $this->t->json('DELETE', '/v1/rtmp/keys/' . self::p($id));
    }
}
