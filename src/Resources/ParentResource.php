<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\Transport;
use VocalStack\Http\WebSocket;

/** What broadcasts and transcriptions share: recordings, share links. */
abstract class ParentResource extends Group
{
    /** The collection's path. */
    protected const BASE = '';

    /** The list's key: what it holds (`broadcasts` or `transcriptions`). */
    protected const KEY = '';

    protected function path(string $id, string $rest = ''): string
    {
        return static::BASE . '/' . self::p($id) . $rest;
    }

    /**
     * Every one, newest first: `lifecycle` is `active`, `archived`, or
     * `all` (both in one list). A paged list is read to its last page
     * (`transcriptions->listPage()` reads one).
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $lifecycle = 'active'): array
    {
        return self::allPages(
            fn (?string $c) => $this->t->json('GET', static::BASE, query: ['lifecycle' => $lifecycle, 'cursor' => $c]),
            static::KEY,
        );
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->t->json('GET', $this->path($id));
    }

    /**
     * Rename, or change the default settings (a broadcast: also its
     * access, password or link, `code`).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function update(string $id, array $fields = []): array
    {
        return $this->t->json('PATCH', $this->path($id), json: self::body($fields));
    }

    /** @return array<string, mixed> */
    public function archive(string $id): array
    {
        return $this->t->json('POST', $this->path($id, '/archive'));
    }

    /** @return array<string, mixed> */
    public function restore(string $id): array
    {
        return $this->t->json('POST', $this->path($id, '/restore'));
    }

    /** Delete an archived one and everything in it, for good. */
    public function purge(string $id): void
    {
        $this->t->json('POST', $this->path($id, '/purge'));
    }

    /**
     * Every recording, newest first (every page of `listRecordingsPage`).
     *
     * @return list<array<string, mixed>>
     */
    public function listRecordings(string $id, string $lifecycle = 'active'): array
    {
        return self::allPages(
            fn (?string $c) => $this->t->json('GET', $this->path($id, '/recordings'), query: ['lifecycle' => $lifecycle, 'cursor' => $c]),
            'recordings',
        );
    }

    /**
     * One page of recordings, newest first; `cursor` is the previous page's `nextCursor`.
     *
     * @return array<string, mixed>
     */
    public function listRecordingsPage(string $id, string $lifecycle = 'active', ?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', $this->path($id, '/recordings'), query: ['lifecycle' => $lifecycle, 'cursor' => $cursor, 'limit' => $limit]);
    }

    /**
     * Start a live recording. The grant's `streamUrl` is the publisher
     * socket (`$vs->recordings->publish($grant)`). A 409 `conflict` with
     * `details['takeover']` means another device is recording: pass
     * `'takeover' => true` to record here instead.
     *
     * @param array<string, mixed> $fields StartRecording
     * @return array<string, mixed> a PublishGrant
     */
    public function startRecording(string $id, array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', $this->path($id, '/recordings'), json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    /** @return list<array<string, mixed>> */
    public function listShareLinks(string $id): array
    {
        return self::many($this->t->json('GET', $this->path($id, '/share-links')), 'links');
    }

    /**
     * @param array<string, mixed> $fields CreateShareLink
     * @return array<string, mixed>
     */
    public function createShareLink(string $id, array $fields = []): array
    {
        return $this->t->json('POST', $this->path($id, '/share-links'), json: self::body($fields));
    }

    /**
     * The events socket a waiting screen needs (`recording.started`: it
     * is live, `recording.ended`, `job.progress`, `stream.ingest`; each
     * names the transcription the recording is made in,
     * `transcriptionId`): a WebSocket whose `messages()` yields the
     * decoded JSON frames.
     */
    public function events(string $id, ?string $listenerToken = null, ?string $shareCode = null): WebSocket
    {
        $url = $this->t->wsUrl($this->path($id, '/events'), Transport::listenerQuery($listenerToken, $shareCode));
        return self::socket($url, $this->t->authHeaders());
    }
}
