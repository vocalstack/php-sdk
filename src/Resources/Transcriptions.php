<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\MultipartBody;
use VocalStack\Http\MultipartPart;
use VocalStack\Http\Transport;
use VocalStack\Http\WebSocket;
use VocalStack\RealtimeSession;
use VocalStack\Upload;
use VocalStack\VocalStackException;

final class Transcriptions extends ParentResource
{
    protected const BASE = '/v1/transcriptions';
    protected const KEY = 'transcriptions';

    private const JOB_TERMINAL = ['done', 'failed', 'stopped', 'cropping'];

    public function __construct(Transport $t, private readonly float $readyDeadline = RealtimeSession::DEFAULT_READY_DEADLINE_S)
    {
        parent::__construct($t);
    }

    public function get(string $id, ?string $listenerToken = null, ?string $shareCode = null): array
    {
        return $this->t->json('GET', $this->path($id), query: Transport::listenerQuery($listenerToken, $shareCode));
    }

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

    public function nameAppearance(string $id, int $appearance, ?string $name): void
    {
        $this->t->json('PUT', $this->path($id, '/appearances/' . self::p($appearance)), json: ['name' => $name]);
    }

    public function start(string $id, array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', $this->path($id, '/start'), json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    public function pause(string $id): array
    {
        return $this->t->json('POST', $this->path($id, '/pause'));
    }

    public function resume(string $id): array
    {
        return $this->t->json('POST', $this->path($id, '/resume'));
    }

    public function stop(string $id, ?string $reason = null): array
    {
        return $this->t->json('POST', $this->path($id, '/stop'), json: ['reason' => $reason]);
    }

    public function publishToken(string $id, bool $takeover = false): array
    {
        return $this->t->json('POST', $this->path($id, '/publish-token'), json: ['takeover' => $takeover]);
    }

    public function publish(array $grant, ?float $readyDeadline = null, ?callable $onFrame = null): RealtimeSession
    {
        if (empty($grant['streamUrl'])) {
            throw new VocalStackException('invalid_state', 'this grant has no streamUrl (an upload has no publisher socket)');
        }
        WebSocket::requireLibrary();
        return RealtimeSession::connect(
            (string) (($grant['sessionId'] ?? null) ?: ($grant['transcription']['id'] ?? '')),
            (string) $grant['streamUrl'],
            readyDeadline: $readyDeadline ?: $this->readyDeadline,
            onFrame: $onFrame,
        );
    }

    public function job(string $id): array
    {
        return $this->t->json('GET', $this->path($id, '/job'));
    }

    public function jobPreview(string $id): string
    {
        return $this->t->raw('GET', $this->path($id, '/job/preview'), headers: ['accept' => '*/*'])->body;
    }

    public function retryJob(string $id): array
    {
        return $this->t->json('POST', $this->path($id, '/job/retry'));
    }

    public function startJob(string $id, ?float $startS = null, ?float $endS = null): array
    {
        return $this->t->json('POST', $this->path($id, '/job/start'), json: self::body(['startS' => $startS, 'endS' => $endS]));
    }

    public function waitForJob(string $id, float $intervalS = 2.0, ?float $timeoutS = null, ?callable $onProgress = null): array
    {
        $deadline = $timeoutS === null ? null : microtime(true) + $timeoutS;
        while (true) {
            $job = $this->job($id);
            if ($onProgress !== null) {
                $onProgress($job);
            }
            if (in_array($job['state'] ?? null, self::JOB_TERMINAL, true)) {
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

    public function summarize(string $id, ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', $this->path($id, '/summarize'), idempotencyKey: $idempotencyKey);
    }

    public function summary(string $id, ?string $lang = null, ?string $listenerToken = null, ?string $shareCode = null): array
    {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['lang' => $lang];
        return $this->t->json('GET', $this->path($id, '/summary'), query: $query);
    }

    public function export(
        string $id,
        string $format,
        ?string $lang = null,
        ?string $shareCode = null,
        ?string $listenerToken = null,
    ): string {
        $query = ['format' => $format, 'lang' => $lang, 'shareCode' => $shareCode, 'listenerToken' => $listenerToken];
        return $this->t->raw('GET', $this->path($id, '/export'), query: $query, headers: ['accept' => '*/*'])->body;
    }

    public function transcript(
        string $id,
        ?int $after = null,
        ?string $lang = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
    ): array {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['after' => $after, 'lang' => $lang];
        return $this->t->json('GET', $this->path($id, '/transcript'), query: $query);
    }

    public function translation(
        string $id,
        string $lang,
        ?array $sentences = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
    ): array {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + [
            'lang' => $lang,
            'sentences' => $sentences ? implode(',', array_map('strval', $sentences)) : null,
        ];
        return $this->t->json('GET', $this->path($id, '/translation'), query: $query);
    }

    public function subscribe(
        string $id,
        ?string $lang = null,
        ?string $voice = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
        bool $asOwner = true,
    ): WebSocket {
        $url = $this->t->wsUrl($this->path($id, '/subscribe'), [
            'lang' => $lang,
            'voice' => $voice,
            ...Transport::listenerQuery($listenerToken, $shareCode),
            'role' => $asOwner ? null : 'listener',
            'device' => $this->t->deviceId,
        ]);
        return self::socket($url, $asOwner ? $this->t->authHeaders() : []);
    }

    public function corrections(string $id): array
    {
        return self::allPages(fn (?string $c) => $this->t->json('GET', $this->path($id, '/corrections'), query: ['cursor' => $c]), 'corrections');
    }

    public function correctionsPage(string $id, ?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', $this->path($id, '/corrections'), query: ['cursor' => $cursor, 'limit' => $limit]);
    }

    public function applyCorrections(string $id, array $fields): array
    {
        return self::many($this->t->json('POST', $this->path($id, '/corrections'), json: self::body($fields)), 'corrections');
    }

    public function retranscribe(string $id, array $seqs, string $lang): array
    {
        $data = $this->t->json('POST', $this->path($id, '/retranscribe'), json: ['seqs' => array_values($seqs), 'lang' => $lang]);
        return self::many($data, 'corrections');
    }

    public function audio(
        string $id,
        ?string $kind = null,
        ?string $lang = null,
        ?string $format = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
    ): string {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['kind' => $kind, 'lang' => $lang, 'format' => $format];
        $resp = $this->t->raw('GET', $this->path($id, '/audio'), query: $query);
        $loc = $resp->header('location');
        if (!$loc) {
            throw new VocalStackException('not_found', 'no signed URL in the redirect', status: $resp->status);
        }
        return $loc;
    }

    public function audioClip(string $id, float $startS, float $endS, ?string $listenerToken = null, ?string $shareCode = null): string
    {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['startS' => $startS, 'endS' => $endS];
        return $this->t->raw('GET', $this->path($id, '/audio/clip'), query: $query, headers: ['accept' => '*/*'])->body;
    }

    public function mediaToken(string $id): array
    {
        return $this->t->json('GET', $this->path($id, '/media-token'));
    }

    public function voice(string $id, ?string $lang = null, ?string $listenerToken = null, ?string $shareCode = null): array
    {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['lang' => $lang];
        return array_values($this->t->json('GET', $this->path($id, '/voice'), query: $query) ?? []);
    }

    public function requestVoice(
        string $id,
        array $langs,
        ?string $idempotencyKey = null,
        ?string $voice = null,
        ?array $voiceBySpeaker = null,
        ?int $near = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
        ?bool $consent = null,
        ?string $voices = null,
    ): array {
        $body = ['langs' => array_values($langs)];
        if ($consent !== null) {
            $body['consent'] = $consent;
        }
        if ($voices !== null) {
            $body['voices'] = $voices;
        }
        if ($voice !== null) {
            $body['voice'] = $voice;
        }
        if ($voiceBySpeaker !== null) {
            $body['voiceBySpeaker'] = (object) array_combine(array_map('strval', array_keys($voiceBySpeaker)), array_values($voiceBySpeaker));
        }
        if ($near !== null) {
            $body['near'] = $near;
        }
        $query = Transport::listenerQuery($listenerToken, $shareCode);
        $data = $this->t->json('POST', $this->path($id, '/voice'), query: $query, json: $body, idempotencyKey: $idempotencyKey);
        return array_values($data ?? []);
    }

    public function voiceClip(
        string $id,
        int $sentence,
        string $lang,
        ?string $format = null,
        ?string $voice = null,
        ?int $part = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
        ?string $voices = null,
    ): string {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['lang' => $lang, 'voices' => $voices, 'voice' => $voice, 'format' => $format, 'part' => $part ?: null];
        return $this->t->raw('GET', $this->path($id, "/voice/{$sentence}"), query: $query, headers: ['accept' => '*/*'])->body;
    }

    public function history(string $id, ?int $after = null, ?int $limit = null): array
    {
        return self::allPages(function (?string $cursor) use ($id, $after, $limit) {
            $q = $cursor ? ['cursor' => $cursor, 'limit' => $limit] : ['after' => $after, 'limit' => $limit];
            return $this->t->json('GET', $this->path($id, '/history'), query: $q);
        }, 'events');
    }

    public function historyPage(string $id, ?string $cursor = null, ?int $after = null, ?int $limit = null): array
    {
        return $this->t->json('GET', $this->path($id, '/history'), query: ['cursor' => $cursor, 'after' => $after, 'limit' => $limit]);
    }
}
