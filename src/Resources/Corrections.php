<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Corrections extends Group
{
    private static function path(string $recordingId): string
    {
        return '/v1/recordings/' . self::p($recordingId) . '/corrections';
    }

    public function list(string $recordingId): array
    {
        return self::allPages(fn (?string $c) => $this->t->json('GET', self::path($recordingId), query: ['cursor' => $c]), 'corrections');
    }

    public function listPage(string $recordingId, ?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', self::path($recordingId), query: ['cursor' => $cursor, 'limit' => $limit]);
    }

    public function apply(string $recordingId, array $fields): array
    {
        return self::many($this->t->json('POST', self::path($recordingId), json: self::body($fields)), 'corrections');
    }

    public function retranscribe(string $recordingId, array $seqs, string $lang): array
    {
        $data = $this->t->json(
            'POST',
            '/v1/recordings/' . self::p($recordingId) . '/retranscribe',
            json: ['seqs' => array_values($seqs), 'lang' => $lang],
        );
        return self::many($data, 'corrections');
    }

    public function revert(string $correctionId): array
    {
        return $this->t->json('POST', '/v1/corrections/' . self::p($correctionId) . '/revert');
    }
}
