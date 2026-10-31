<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Me extends Group
{
    public function get(): array
    {
        return $this->t->json('GET', '/v1/me');
    }
}
