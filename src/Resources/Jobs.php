<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\VocalStackException;

final class Jobs extends Group
{
    private const TERMINAL = ['done', 'failed', 'stopped', 'cropping'];

    private static function path(string $recordingId, string $rest = ''): string
    {
        return '/v1/recordings/' . self::p($recordingId) . '/job' . $rest;
    }

    public function get(string $recordingId): array
    {
        return $this->t->json('GET', self::path($recordingId));
    }

    public function start(string $recordingId, ?float $startS = null, ?float $endS = null): array
    {
        return $this->t->json('POST', self::path($recordingId, '/start'), json: self::body(['startS' => $startS, 'endS' => $endS]));
    }

    public function retry(string $recordingId): array
    {
        return $this->t->json('POST', self::path($recordingId, '/retry'));
    }

    public function preview(string $recordingId): string
    {
        return $this->t->raw('GET', self::path($recordingId, '/preview'), headers: ['accept' => '*/*'])->body;
    }

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
