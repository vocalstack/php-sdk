<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/** `$vs->search`: full-text search over the caller's transcripts. */
final class Search extends Group
{
    /**
     * `q` is 2 or more characters; `broadcasts` narrows to those links (codes).
     *
     * @param list<string>|null $broadcasts
     * @param list<string>|null $listenerTokens
     * @return array<string, mixed> SearchResults
     */
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
