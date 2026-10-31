<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\MultipartBody;
use VocalStack\Http\MultipartPart;
use VocalStack\Http\Transport;
use VocalStack\Upload;
use VocalStack\VocalStackException;

final class Batches extends Group
{
    private static function path(string $id, string $rest = ''): string
    {
        return '/v1/batches/' . self::p($id) . $rest;
    }

    public function create(array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', '/v1/batches', json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    public function list(): array
    {
        return self::allPages(fn (?string $c) => $this->t->json('GET', '/v1/batches', query: ['cursor' => $c]), 'batches');
    }

    public function listPage(?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', '/v1/batches', query: ['cursor' => $cursor, 'limit' => $limit]);
    }

    public function get(string $id): array
    {
        return $this->t->json('GET', self::path($id));
    }

    public function delete(string $id): void
    {
        $this->t->json('DELETE', self::path($id));
    }

    public function addFile(string $id, array $fields = [], mixed $file = null, ?string $fileName = null, ?string $idempotencyKey = null): array
    {
        $options = self::body($fields);
        if ($file === null) {
            return $this->t->json('POST', self::path($id, '/files'), json: $options, idempotencyKey: $idempotencyKey);
        }
        $upload = Upload::from($file);
        $body = new MultipartBody([
            MultipartPart::field('options', Transport::encodeJson($options)),
            MultipartPart::file('file', $upload, $upload->fileName($fileName, 'upload')),
        ]);
        return $this->t->json('POST', self::path($id, '/files'), multipart: $body, idempotencyKey: $idempotencyKey);
    }

    public function addUrl(string $id, string $url, array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->addFile($id, ['url' => $url] + $fields, idempotencyKey: $idempotencyKey);
    }

    public function start(string $id, ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', self::path($id, '/start'), idempotencyKey: $idempotencyKey);
    }

    public function export(string $id, string $format): string
    {
        return $this->t->raw('GET', self::path($id, '/export'), query: ['format' => $format], headers: ['accept' => '*/*'])->body;
    }

    public function stop(string $id): array
    {
        return $this->t->json('POST', self::path($id, '/stop'));
    }

    public function wait(string $id, float $intervalS = 5.0, ?float $timeoutS = null, ?callable $onProgress = null): array
    {
        $deadline = $timeoutS === null ? null : microtime(true) + $timeoutS;
        while (true) {
            $batch = $this->get($id);
            if ($onProgress !== null) {
                $onProgress($batch);
            }
            $state = $batch['state'] ?? null;
            if (($state === 'done' || $state === 'stopped') && !empty($batch['finishedAt'])) {
                return $batch;
            }
            if ($state === 'open') {
                throw new VocalStackException('invalid_state', 'the batch is open: start it before waiting for it');
            }
            if ($deadline !== null && microtime(true) + $intervalS > $deadline) {
                throw new VocalStackException('timeout', "batch still {$state} after {$timeoutS}s", retryable: true);
            }
            if ($intervalS > 0) {
                usleep((int) round($intervalS * 1_000_000));
            }
        }
    }
}
