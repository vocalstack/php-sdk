<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/** `$vs->languages`. */
final class Languages extends Group
{
    /**
     * Spoken and translation languages.
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return $this->t->json('GET', '/v1/languages');
    }
}
