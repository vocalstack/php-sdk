<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/** `$vs->me`: who the credential is. */
final class Me extends Group
{
    /** @return array<string, mixed> */
    public function get(): array
    {
        return $this->t->json('GET', '/v1/me');
    }
}
