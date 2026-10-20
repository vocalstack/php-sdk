<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\VocalStackException;

/**
 * `$vs->jobs`: a file or link recording's transcription job. States:
 * queued, fetching, transcribing, done, failed, and cropping (waiting for `start`).
 */
final class Jobs extends Group
{
    private const TERMINAL = ['done', 'failed', 'stopped', 'cropping'];

    private static function path(string $recordingId, string $rest = ''): string
    {
        return '/v1/recordings/' . self::p($recordingId) . '/job' . $rest;
    }

    /** @return array<string, mixed> a TranscriptionJob */
    public function get(string $recordingId): array
    {
        return $this->t->json('GET', self::path($recordingId));
    }

    /**
     * Release a job waiting in `cropping`: the window to transcribe, in seconds.
     *
     * @return array<string, mixed>
     */
    public function start(string $recordingId, ?float $startS = null, ?float $endS = null): array
    {
        return $this->t->json('POST', self::path($recordingId, '/start'), json: self::body(['startS' => $startS, 'endS' => $endS]));
    }

    /**
     * Re-queue a failed job from its stored source.
     *
     * @return array<string, mixed>
     */
    public function retry(string $recordingId): array
    {
        return $this->t->json('POST', self::path($recordingId, '/retry'));
    }

    /** The file the server fetched for a link job waiting in `cropping`; its bytes. */
    public function preview(string $recordingId): string
    {
        return $this->t->raw('GET', self::path($recordingId, '/preview'), headers: ['accept' => '*/*'])->body;
    }

    /**
     * Poll until the job is `done`, `failed` or `cropping` and return it.
     * It does not throw on `failed`: check `$job['state']` and `$job['error']`.
     * `onProgress` sees every job read, the last one included: its `state`
     * and `progress` (`pct`, `processedS`, the fetch phase).
     *
     * @param (callable(array<string, mixed>): void)|null $onProgress
     * @return array<string, mixed>
     */
    public function wait(string $recordingId, float $intervalS = 2.0, ?float $timeoutS = null, ?callable $onProgress = null): array
    {
        $deadline = $timeoutS === null ? null : microtime(true) + $timeoutS;
        while (true) {
            $job = $this->get($recordingId);
            if ($onProgress !== null) {
                $onProgress($job);
            }
            if (in_array($job['state'] ?? null, self::TERMINAL, true)) {
                return $job;
            }
            if ($deadline !== null && microtime(true) + $intervalS > $deadline) {
                throw new VocalStackException('timeout', "job still {$job['state']} after {$timeoutS}s", retryable: true);
            }
            if ($intervalS > 0) {
                usleep((int) round($intervalS * 1_000_000));
            }
        }
    }
}
