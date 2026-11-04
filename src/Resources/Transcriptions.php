<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\MultipartBody;
use VocalStack\Http\MultipartPart;
use VocalStack\Http\Transport;
use VocalStack\Upload;

final class Transcriptions extends ParentResource
{
    protected const BASE = '/v1/transcriptions';
    protected const KEY = 'transcriptions';

    public function list(
        string $lifecycle = 'active',
        ?string $site = null,
        ?string $q = null,
        ?string $language = null,
        ?string $kind = null,
        ?string $origin = null,
        ?string $tag = null,
        ?string $broadcast = null,
        string|\DateTimeInterface|null $createdAfter = null,
        string|\DateTimeInterface|null $createdBefore = null,
        ?bool $shared = null,
    ): array {
        $filters = self::filters($site, $q, $language, $kind, $origin, $tag, $broadcast, $createdAfter, $createdBefore, $shared);
        return self::allPages(
            fn (?string $c) => $this->t->json('GET', self::BASE, query: ['lifecycle' => $lifecycle, 'cursor' => $c] + $filters),
            self::KEY,
        );
    }

    public function listPage(
        string $lifecycle = 'active',
        ?string $cursor = null,
        ?int $limit = null,
        ?string $site = null,
        ?string $q = null,
        ?string $language = null,
        ?string $kind = null,
        ?string $origin = null,
        ?string $tag = null,
        ?string $broadcast = null,
        string|\DateTimeInterface|null $createdAfter = null,
        string|\DateTimeInterface|null $createdBefore = null,
        ?bool $shared = null,
    ): array {
        $filters = self::filters($site, $q, $language, $kind, $origin, $tag, $broadcast, $createdAfter, $createdBefore, $shared);
        return $this->t->json('GET', self::BASE, query: ['lifecycle' => $lifecycle, 'cursor' => $cursor, 'limit' => $limit] + $filters);
    }

    private static function filters(
        ?string $site,
        ?string $q,
        ?string $language,
        ?string $kind,
        ?string $origin,
        ?string $tag,
        ?string $broadcast,
        string|\DateTimeInterface|null $createdAfter,
        string|\DateTimeInterface|null $createdBefore,
        ?bool $shared,
    ): array {
        $at = static fn (string|\DateTimeInterface|null $d) => $d instanceof \DateTimeInterface ? $d->format(\DateTimeInterface::RFC3339) : $d;
        return [
            'site' => $site, 'q' => $q, 'language' => $language, 'kind' => $kind, 'origin' => $origin, 'tag' => $tag,
            'broadcast' => $broadcast, 'createdAfter' => $at($createdAfter), 'createdBefore' => $at($createdBefore), 'shared' => $shared,
        ];
    }

    public function uploadRecording(string $id, array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', $this->path($id, '/recordings/upload'), json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    public function create(array $fields = [], mixed $file = null, ?string $fileName = null, ?string $idempotencyKey = null): array
    {
        $options = self::body($fields);
        if ($file === null) {
            return $this->t->json('POST', self::BASE, json: $options, idempotencyKey: $idempotencyKey);
        }
        $upload = Upload::from($file);
        $body = new MultipartBody([
            MultipartPart::field('options', Transport::encodeJson($options)),
            MultipartPart::file('file', $upload, $upload->fileName($fileName, 'upload')),
        ]);
        return $this->t->json('POST', self::BASE, multipart: $body, idempotencyKey: $idempotencyKey);
    }

    public function createFromUrl(string $url, array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->create(['url' => $url] + $fields, idempotencyKey: $idempotencyKey);
    }

    public function createLive(array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', self::BASE . '/live', json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    public function lookup(string $url): ?array
    {
        return $this->t->json('GET', self::BASE . '/lookup', query: ['url' => $url])['transcription'] ?? null;
    }

    public function probe(string $url): array
    {
        return $this->t->json('GET', self::BASE . '/probe', query: ['url' => $url]);
    }

    public function attachBroadcast(string $id, string $broadcastId): array
    {
        return $this->t->json('PUT', $this->path($id, '/broadcasts/' . self::p($broadcastId)));
    }

    public function detachBroadcast(string $id, string $broadcastId): array
    {
        return $this->t->json('DELETE', $this->path($id, '/broadcasts/' . self::p($broadcastId)));
    }
}
