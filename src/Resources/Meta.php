<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Meta extends Group
{
    public function health(): array
    {
        return $this->t->json('GET', '/v1/health');
    }

    public function openapi(): array
    {
        return $this->t->json('GET', '/v1/openapi.json');
    }

    public function clientPolicy(string $client): array
    {
        return $this->t->json('GET', '/v1/clients/' . rawurlencode($client));
    }
}
