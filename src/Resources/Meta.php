<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/** `$vs->meta`. */
final class Meta extends Group
{
    /** @return array<string, mixed> */
    public function health(): array
    {
        return $this->t->json('GET', '/v1/health');
    }

    /**
     * The OpenAPI document the API is described by.
     *
     * @return array<string, mixed>
     */
    public function openapi(): array
    {
        return $this->t->json('GET', '/v1/openapi.json');
    }
}
