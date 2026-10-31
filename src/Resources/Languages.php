<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Languages extends Group
{
    public function list(): array
    {
        return $this->t->json('GET', '/v1/languages');
    }
}
