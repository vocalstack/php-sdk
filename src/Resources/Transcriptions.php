<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\MultipartBody;
use VocalStack\Http\MultipartPart;
use VocalStack\Http\Transport;
use VocalStack\Upload;

/** `$vs->transcriptions`: a file, a link, a microphone or a stream, transcribed and stored. */
final class Transcriptions extends ParentResource
{
    protected const BASE = '/v1/transcriptions';
    protected const KEY = 'transcriptions';

    /**
     * One page of transcriptions, newest first (`limit` 1 to 200, 100 by
     * default); `cursor` is the previous page's `nextCursor`, and the last
     * page has none.
     *
     * @return array<string, mixed>
     */
    public function listPage(string $lifecycle = 'active', ?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', self::BASE, query: ['lifecycle' => $lifecycle, 'cursor' => $cursor, 'limit' => $limit]);
    }

    /**
     * A file or link recording in this transcription: no live phase,
     * transcribed at full speed. It has no job: open the grant's socket
     * with `$vs->recordings->publish($grant)` (with `url` the server
     * fetches the link) and read it until the session is done.
     *
     * @param array<string, mixed> $fields UploadRecording
     * @return array<string, mixed> a PublishGrant
     */
    public function uploadRecording(string $id, array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', $this->path($id, '/recordings/upload'), json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    /**
     * From a file (`file`: a path, `Upload::bytes(...)` or a stream,
     * streamed as multipart) or a public link (`['url' => ...]`). The
     * answer's `job.recordingId` is the job: `$vs->jobs->wait($recordingId)`,
     * then `$vs->recordings->transcript($recordingId)`.
     *
     * @param array<string, mixed> $fields CreateTranscription
     * @param string|Upload|resource|null $file
     * @return array<string, mixed>
     */
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

    /**
     * `create(['url' => $url, ...])`.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function createFromUrl(string $url, array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->create(['url' => $url] + $fields, idempotencyKey: $idempotencyKey);
    }

    /**
     * Captured as it is spoken (a microphone, or a stream the server
     * pulls). Created idle: `startRecording($id)` starts it.
     *
     * @param array<string, mixed> $fields CreateLiveTranscription
     * @return array<string, mixed>
     */
    public function createLive(array $fields = [], ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', self::BASE . '/live', json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    /**
     * Whether a link is live (a live transcription) or a file.
     *
     * @return array<string, mixed>
     */
    public function probe(string $url): array
    {
        return $this->t->json('GET', self::BASE . '/probe', query: ['url' => $url]);
    }

    /** @return array<string, mixed> */
    public function attachBroadcast(string $id, string $broadcastId): array
    {
        return $this->t->json('PUT', $this->path($id, '/broadcasts/' . self::p($broadcastId)));
    }

    /** @return array<string, mixed> */
    public function detachBroadcast(string $id, string $broadcastId): array
    {
        return $this->t->json('DELETE', $this->path($id, '/broadcasts/' . self::p($broadcastId)));
    }
}
