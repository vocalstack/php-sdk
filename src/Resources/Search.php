<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Search extends Group
{
    public function query(
        string $q,
        ?array $broadcasts = null,
        ?array $listenerTokens = null,
        ?string $recording = null,
        ?string $lang = null,
        ?int $limit = null,
    ): array {
        $query = [
            'q' => $q,
            'broadcasts' => $broadcasts ? implode(',', $broadcasts) : null,
            'listenerTokens' => $listenerTokens ? implode(',', $listenerTokens) : null,
            'recording' => $recording,
            'lang' => $lang,
            'limit' => $limit,
        ];
        return $this->t->json('GET', '/v1/search', query: $query);
    }
}
