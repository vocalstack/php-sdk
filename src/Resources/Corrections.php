<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Corrections extends Group
{
    public function revert(string $correctionId): array
    {
        return $this->t->json('POST', '/v1/corrections/' . self::p($correctionId) . '/revert');
    }
}
