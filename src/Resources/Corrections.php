<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/** `$vs->corrections`: edits to a finished transcript, each revertible. */
final class Corrections extends Group
{
    private static function path(string $recordingId): string
    {
        return '/v1/recordings/' . self::p($recordingId) . '/corrections';
    }

    /**
     * Every correction, newest first (every page of `listPage`).
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $recordingId): array
    {
        return self::allPages(fn (?string $c) => $this->t->json('GET', self::path($recordingId), query: ['cursor' => $c]), 'corrections');
    }

    /**
     * One page of corrections, newest first; `cursor` is the previous page's `nextCursor`.
     *
     * @return array<string, mixed>
     */
    public function listPage(string $recordingId, ?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', self::path($recordingId), query: ['cursor' => $cursor, 'limit' => $limit]);
    }

    /**
     * Apply a staged batch atomically (CorrectionBatch: `edits`, `lines`).
     *
     * @param array<string, mixed> $fields
     * @return list<array<string, mixed>>
     */
    public function apply(string $recordingId, array $fields): array
    {
        return self::many($this->t->json('POST', self::path($recordingId), json: self::body($fields)), 'corrections');
    }

    /**
     * Read lines again with the language forced.
     *
     * @param list<int> $seqs
     * @return list<array<string, mixed>>
     */
    public function retranscribe(string $recordingId, array $seqs, string $lang): array
    {
        $data = $this->t->json(
            'POST',
            '/v1/recordings/' . self::p($recordingId) . '/retranscribe',
            json: ['seqs' => array_values($seqs), 'lang' => $lang],
        );
        return self::many($data, 'corrections');
    }

    /** @return array<string, mixed> */
    public function revert(string $correctionId): array
    {
        return $this->t->json('POST', '/v1/corrections/' . self::p($correctionId) . '/revert');
    }
}
