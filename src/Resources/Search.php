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
        ?string $site = null,
        ?string $language = null,
        ?string $kind = null,
        ?string $origin = null,
        ?string $tag = null,
        string|\DateTimeInterface|null $createdAfter = null,
        string|\DateTimeInterface|null $createdBefore = null,
        ?bool $shared = null,
        ?string $transcription = null,
    ): array {
        $at = static fn (string|\DateTimeInterface|null $d) => $d instanceof \DateTimeInterface ? $d->format(\DateTimeInterface::RFC3339) : $d;
        $query = [
            'q' => $q,
            'broadcasts' => $broadcasts ? implode(',', $broadcasts) : null,
            'listenerTokens' => $listenerTokens ? implode(',', $listenerTokens) : null,
            'recording' => $recording,
            'transcription' => $transcription,
            'lang' => $lang,
            'limit' => $limit,
            'site' => $site,
            'language' => $language,
            'kind' => $kind,
            'origin' => $origin,
            'tag' => $tag,
            'createdAfter' => $at($createdAfter),
            'createdBefore' => $at($createdBefore),
            'shared' => $shared,
        ];
        return $this->t->json('GET', '/v1/search', query: $query);
    }
}
