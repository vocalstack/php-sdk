<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\MultipartBody;
use VocalStack\Http\MultipartPart;
use VocalStack\Http\Transport;
use VocalStack\Upload;
use VocalStack\VocalStackException;

/**
 * `$vs->batches`: several files transcribed together, for less than a
 * transcription each (one create, one minimum charge). Create a batch, add
 * each file or link with `addFile`, then `start` it; `get` shows its files
 * (each a transcription with its job) and their counts. A batch's files
 * get no summary, title, share link or spoken translations.
 */
final class Batches extends Group
{
    private static function path(string $id, string $rest = ''): string
    {
        return '/v1/batches/' . self::p($id) . $rest;
    }

    /**
     * An open batch, ready for files (`name` defaults to "Batch of <date>";
     * `settings`: `spokenLanguage`, `speakerLabels`, `latencyMode` for every file).
     *
     * @param array<string, mixed> $fields CreateBatch
     * @return array<string, mixed> a Batch
     */
    public function create(array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', '/v1/batches', json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    /**
     * Every batch, newest first (every page of `listPage`); each with its
     * counts, not its files.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return self::allPages(fn (?string $c) => $this->t->json('GET', '/v1/batches', query: ['cursor' => $c]), 'batches');
    }

    /**
     * One page of batches, newest first; `cursor` is the previous page's `nextCursor`.
     *
     * @return array<string, mixed> a BatchPage
     */
    public function listPage(?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', '/v1/batches', query: ['cursor' => $cursor, 'limit' => $limit]);
    }

    /**
     * A batch with its files (`files`, each a transcription with its job),
     * in the order they were added.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->t->json('GET', self::path($id));
    }

    /** Discard a batch that was never started, with its files (nothing was charged). */
    public function delete(string $id): void
    {
        $this->t->json('DELETE', self::path($id));
    }

    /**
     * Add one file (`file`: a path, `Upload::bytes(...)` or a stream,
     * streamed as multipart) or public link (`['url' => ...]`) to an open
     * batch: a transcription of its own (`batchId`), waiting for the
     * batch's `start`. Of the transcription options it takes `name`,
     * `startS`, `endS` and `durationS` (the settings are the batch's).
     *
     * @param array<string, mixed> $fields
     * @param string|Upload|resource|null $file
     * @return array<string, mixed> a Transcription
     */
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

    /**
     * `addFile($id, ['url' => $url, ...])`.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function addUrl(string $id, string $url, array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->addFile($id, ['url' => $url] + $fields, idempotencyKey: $idempotencyKey);
    }

    /**
     * Seal the batch and queue its files, longest first; `wait` follows it to the end.
     *
     * @return array<string, mixed>
     */
    public function start(string $id, ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', self::path($id, '/start'), idempotencyKey: $idempotencyKey);
    }

    /**
     * Every finished file's transcript in one `format` (`json`, `srt`,
     * `xlsx` or `pdf`, as `recordings->export` makes one), in one zip:
     * its bytes. Files still running or failed are left out.
     */
    public function export(string $id, string $format): string
    {
        return $this->t->raw('GET', self::path($id, '/export'), query: ['format' => $format], headers: ['accept' => '*/*'])->body;
    }

    /**
     * Stop every file not finished yet: queued files never start, a
     * running one is cancelled and billed for what it transcribed.
     *
     * @return array<string, mixed>
     */
    public function stop(string $id): array
    {
        return $this->t->json('POST', self::path($id, '/stop'));
    }

    /**
     * Poll a started batch until it is `done` (every file finished or
     * failed) or `stopped`, and return it with its files. It does not
     * throw on a failed file: check `$batch['counts']['failed']` and each
     * file's `job.state`. `onProgress` sees every read, the last one included.
     *
     * @param (callable(array<string, mixed>): void)|null $onProgress
     * @return array<string, mixed>
     */
    public function wait(string $id, float $intervalS = 5.0, ?float $timeoutS = null, ?callable $onProgress = null): array
    {
        $deadline = $timeoutS === null ? null : microtime(true) + $timeoutS;
        while (true) {
            $batch = $this->get($id);
            if ($onProgress !== null) {
                $onProgress($batch);
            }
            $state = $batch['state'] ?? null;
            // Closed: every file over (a stopped batch's running file too).
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
