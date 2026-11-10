<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\MultipartBody;
use VocalStack\Http\MultipartPart;
use VocalStack\Http\Transport;
use VocalStack\Upload;

final class Speakers extends Group
{
    public function list(?string $lifecycle = null): array
    {
        return self::many($this->t->json('GET', '/v1/speakers', query: ['lifecycle' => $lifecycle]), 'speakers');
    }

    public function get(string $id): array
    {
        return $this->t->json('GET', '/v1/speakers/' . self::p($id));
    }

    public function archive(string $id): array
    {
        return $this->t->json('POST', '/v1/speakers/' . self::p($id) . '/archive');
    }

    public function restore(string $id): array
    {
        return $this->t->json('POST', '/v1/speakers/' . self::p($id) . '/restore');
    }

    public function archiveMany(array $ids): array
    {
        return $this->t->json('POST', '/v1/speakers' . '/archive', json: ['ids' => array_values($ids)]);
    }

    public function restoreMany(array $ids): array
    {
        return $this->t->json('POST', '/v1/speakers' . '/restore', json: ['ids' => array_values($ids)]);
    }

    public function deleteMany(array $ids): array
    {
        return $this->t->json('POST', '/v1/speakers' . '/delete', json: ['ids' => array_values($ids)]);
    }

    public function create(
        string $name,
        mixed $sample = null,
        ?string $sampleUrl = null,
        ?string $fileName = null,
        ?string $gender = null,
        ?string $color = null,
        bool $draft = false,
        ?bool $consent = null,
        ?string $idempotencyKey = null,
    ): array {
        $meta = self::body([
            'name' => $name,
            'sampleUrl' => $sampleUrl,
            'gender' => $gender,
            'color' => $color,
            'draft' => $draft ?: null,
            'consent' => $consent,
        ]);
        $parts = [MultipartPart::field('meta', Transport::encodeJson($meta), 'application/json')];
        if ($sample !== null) {
            $upload = Upload::from($sample);
            $parts[] = MultipartPart::file('sample', $upload, $upload->fileName($fileName, 'sample.webm'));
        }
        return $this->t->json('POST', '/v1/speakers', multipart: new MultipartBody($parts), idempotencyKey: $idempotencyKey);
    }

    public function createFromAppearance(
        string $transcriptionId,
        int $appearance,
        string $name,
        ?string $recordingId = null,
        ?bool $consent = null,
        ?string $color = null,
        ?array $spans = null,
        ?bool $draft = null,
        ?bool $library = null,
        ?string $idempotencyKey = null,
    ): array {
        $body = self::body([
            'transcriptionId' => $transcriptionId,
            'recordingId' => $recordingId,
            'appearance' => $appearance,
            'name' => $name,
            'consent' => $consent,
            'color' => $color,
            'spans' => $spans === null ? null : array_values($spans),
            'draft' => $draft,
            'library' => $library,
        ]);
        return $this->t->json('POST', '/v1/speakers/from-appearance', json: $body, idempotencyKey: $idempotencyKey);
    }

    public function appearanceSamples(string $transcriptionId, int $appearance, ?string $recordingId = null, ?array $spans = null): array
    {
        $picked = $spans ? implode(',', array_map(fn (array $s): string => $s['start'] . '-' . $s['end'], array_values($spans))) : null;
        return $this->t->json('GET', '/v1/speakers/from-appearance/samples', query: ['transcriptionId' => $transcriptionId, 'recordingId' => $recordingId, 'appearance' => $appearance, 'spans' => $picked]);
    }

    public function setConsent(string $id, string $consent, ?string $name = null): array
    {
        return $this->t->json('POST', '/v1/speakers/' . self::p($id) . '/consent', json: self::body(['consent' => $consent, 'name' => $name]));
    }

    public function update(string $id, array $fields = []): array
    {
        return $this->t->json('PATCH', '/v1/speakers/' . self::p($id), json: self::body($fields));
    }

    public function delete(string $id): void
    {
        $this->t->json('DELETE', '/v1/speakers/' . self::p($id));
    }

    public function voicePreview(
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
            '/v1/speakers/' . self::p($id) . '/preview',
            query: $query,
            headers: ['accept' => '*/*'],
            anonymous: !$asOwner,
        )->body;
    }

    public function sample(string $id): string
    {
        return $this->t->raw('GET', '/v1/speakers/' . self::p($id) . '/sample', headers: ['accept' => '*/*'])->body;
    }
}
