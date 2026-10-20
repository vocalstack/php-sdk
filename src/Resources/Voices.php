<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\MultipartBody;
use VocalStack\Http\MultipartPart;
use VocalStack\Http\Transport;
use VocalStack\Upload;

/** `$vs->voices`: the account's cloned voices, and what a language offers. */
final class Voices extends Group
{
    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return self::many($this->t->json('GET', '/v1/voices'), 'voices');
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->t->json('GET', '/v1/voices/' . self::p($id));
    }

    /**
     * Build a voice from a sample or a link. Calling it asserts you have
     * the speaker's permission, unless `draft`.
     *
     * @param string|Upload|resource|null $sample
     * @return array<string, mixed>
     */
    public function create(
        string $name,
        mixed $sample = null,
        ?string $sampleUrl = null,
        ?string $fileName = null,
        ?string $gender = null,
        ?string $color = null,
        bool $draft = false,
        ?string $idempotencyKey = null,
    ): array {
        $meta = self::body([
            'name' => $name,
            'sampleUrl' => $sampleUrl,
            'gender' => $gender,
            'color' => $color,
            'draft' => $draft ?: null,
            'consent' => !$draft,
        ]);
        // Multipart even without a sample: the meta part rides as a field.
        $parts = [MultipartPart::field('meta', Transport::encodeJson($meta), 'application/json')];
        if ($sample !== null) {
            $upload = Upload::from($sample);
            $parts[] = MultipartPart::file('sample', $upload, $upload->fileName($fileName, 'sample.webm'));
        }
        return $this->t->json('POST', '/v1/voices', multipart: new MultipartBody($parts), idempotencyKey: $idempotencyKey);
    }

    /**
     * Build a voice from one speaker of a recording (asserts permission).
     *
     * @return array<string, mixed>
     */
    public function createFromSpeaker(
        string $recordingId,
        int $speaker,
        string $name,
        ?string $color = null,
        ?string $idempotencyKey = null,
    ): array {
        $body = self::body(['recordingId' => $recordingId, 'speaker' => $speaker, 'name' => $name, 'color' => $color, 'consent' => true]);
        return $this->t->json('POST', '/v1/voices/from-speaker', json: $body, idempotencyKey: $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $fields PatchVoice
     * @return array<string, mixed>
     */
    public function update(string $id, array $fields = []): array
    {
        return $this->t->json('PATCH', '/v1/voices/' . self::p($id), json: self::body($fields));
    }

    public function delete(string $id): void
    {
        $this->t->json('DELETE', '/v1/voices/' . self::p($id));
    }

    /**
     * The voices that can speak `lang`, best first (with a token, also the
     * account's ready clones). `recording` also offers the cloned voices
     * that recording uses, to whoever may read it: its owner, or a
     * listener with `listenerToken` or `shareCode`.
     *
     * @return array<string, mixed> a VoiceCatalogue
     */
    public function available(
        string $lang,
        ?string $recording = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
        bool $asOwner = true,
    ): array {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['lang' => $lang, 'recording' => $recording];
        return $this->t->json('GET', '/v1/voices/available', query: $query, anonymous: !$asOwner);
    }

    /** A catalogue voice speaking `lang`; the clip's bytes. */
    public function preview(string $voice, string $lang, ?string $format = null): string
    {
        $query = ['voice' => $voice, 'lang' => $lang, 'format' => $format];
        return $this->t->raw('GET', '/v1/voices/preview', query: $query, headers: ['accept' => '*/*'])->body;
    }

    /**
     * A cloned voice speaking `lang`; the clip's bytes. With `recording`,
     * as it speaks in that recording, for whoever may read it (its owner,
     * or a listener with `listenerToken` or `shareCode`).
     */
    public function clonePreview(
        string $id,
        ?string $lang = null,
        ?string $format = null,
        ?string $recording = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
        bool $asOwner = true,
    ): string {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['lang' => $lang, 'format' => $format, 'recording' => $recording];
        return $this->t->raw(
            'GET',
            '/v1/voices/' . self::p($id) . '/preview',
            query: $query,
            headers: ['accept' => '*/*'],
            anonymous: !$asOwner,
        )->body;
    }

    /** The voice's sample; its bytes. */
    public function sample(string $id): string
    {
        return $this->t->raw('GET', '/v1/voices/' . self::p($id) . '/sample', headers: ['accept' => '*/*'])->body;
    }
}
