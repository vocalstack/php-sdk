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

    public function listPage(string $lifecycle = 'active', ?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', self::BASE, query: ['lifecycle' => $lifecycle, 'cursor' => $cursor, 'limit' => $limit]);
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
