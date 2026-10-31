<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\Transport;
use VocalStack\Http\WebSocket;
use VocalStack\RealtimeSession;
use VocalStack\VocalStackException;

/** `$vs->recordings`: one take, live or uploaded. */
final class Recordings extends Group
{
    public function __construct(Transport $t, private readonly float $readyDeadline)
    {
        parent::__construct($t);
    }

    private static function path(string $id, string $rest = ''): string
    {
        return '/v1/recordings/' . self::p($id) . $rest;
    }

    /** @return array<string, mixed> */
    public function get(string $id, ?string $listenerToken = null, ?string $shareCode = null): array
    {
        return $this->t->json('GET', self::path($id), query: Transport::listenerQuery($listenerToken, $shareCode));
    }

    /** @return array<string, mixed> */
    public function pause(string $id): array
    {
        return $this->t->json('POST', self::path($id, '/pause'));
    }

    /** @return array<string, mixed> */
    public function resume(string $id): array
    {
        return $this->t->json('POST', self::path($id, '/resume'));
    }

    /**
     * Only from paused.
     *
     * @return array<string, mixed>
     */
    public function stop(string $id, ?string $reason = null): array
    {
        return $this->t->json('POST', self::path($id, '/stop'), json: ['reason' => $reason]);
    }

    /**
     * @param array<string, mixed> $fields SettingsPatch
     * @return array<string, mixed>
     */
    public function settings(string $id, array $fields = []): array
    {
        return $this->t->json('PATCH', self::path($id, '/settings'), json: self::body($fields));
    }

    /** @return array<string, mixed> a PublishGrant */
    public function publishToken(string $id, bool $takeover = false): array
    {
        return $this->t->json('POST', self::path($id, '/publish-token'), json: ['takeover' => $takeover]);
    }

    /**
     * Open the publisher socket a grant names and wait until it is ready:
     * `sendAudio()` raw PCM (f32le, 16 kHz, mono), end with `eof()`.
     * `onFrame` sees every server frame, lifecycle included.
     *
     * @param array<string, mixed> $grant a PublishGrant
     */
    public function publish(array $grant, ?float $readyDeadline = null, ?callable $onFrame = null): RealtimeSession
    {
        if (empty($grant['streamUrl'])) {
            throw new VocalStackException('invalid_state', 'this grant has no streamUrl (an upload has no publisher socket)');
        }
        WebSocket::requireLibrary();
        return RealtimeSession::connect(
            (string) (($grant['sessionId'] ?? null) ?: ($grant['recording']['id'] ?? '')),
            (string) $grant['streamUrl'],
            readyDeadline: $readyDeadline ?: $this->readyDeadline,
            onFrame: $onFrame,
        );
    }

    /** @return array<string, mixed> */
    public function archive(string $id): array
    {
        return $this->t->json('POST', self::path($id, '/archive'));
    }

    /** @return array<string, mixed> */
    public function restore(string $id): array
    {
        return $this->t->json('POST', self::path($id, '/restore'));
    }

    public function purge(string $id): void
    {
        $this->t->json('POST', self::path($id, '/purge'));
    }

    /**
     * Segments (with word timings), sentences and speakers; `lang` reads it translated.
     *
     * @return array<string, mixed> a TranscriptSnapshot
     */
    public function transcript(
        string $id,
        ?int $after = null,
        ?string $lang = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
    ): array {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['after' => $after, 'lang' => $lang];
        return $this->t->json('GET', self::path($id, '/transcript'), query: $query);
    }

    /**
     * Translate the given sentence ids (at most 40): `['lang' => ..., 'translations' => ...]`.
     *
     * @param list<int>|null $sentences
     * @return array<string, mixed>
     */
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
        return $this->t->json('GET', self::path($id, '/translation'), query: $query);
    }

    /** @return array<string, mixed> a SummaryStatus */
    public function summarize(string $id, ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', self::path($id, '/summarize'), idempotencyKey: $idempotencyKey);
    }

    /** @return array<string, mixed> a SummaryStatus */
    public function summary(string $id, ?string $lang = null, ?string $listenerToken = null, ?string $shareCode = null): array
    {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['lang' => $lang];
        return $this->t->json('GET', self::path($id, '/summary'), query: $query);
    }

    /**
     * An export file: `json`, `srt`, `xlsx` or `pdf`; `lang` makes it read
     * in that language. `listenerToken`: a broadcast listener's, once the
     * recording has ended. Returns the file's bytes.
     */
    public function export(
        string $id,
        string $format,
        ?string $lang = null,
        ?string $shareCode = null,
        ?string $listenerToken = null,
    ): string {
        $query = ['format' => $format, 'lang' => $lang, 'shareCode' => $shareCode, 'listenerToken' => $listenerToken];
        return $this->t->raw('GET', self::path($id, '/export'), query: $query, headers: ['accept' => '*/*'])->body;
    }

    /** The signed media URL the server redirects to (`kind`: delivery, master, source). */
    public function audioUrl(
        string $id,
        ?string $kind = null,
        ?string $lang = null,
        ?string $format = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
    ): string {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['kind' => $kind, 'lang' => $lang, 'format' => $format];
        $resp = $this->t->raw('GET', self::path($id, '/audio'), query: $query);
        $loc = $resp->header('location');
        if (!$loc) {
            throw new VocalStackException('not_found', 'no signed URL in the redirect', status: $resp->status);
        }
        return $loc;
    }

    /** A stretch of the recording's audio, `startS` to `endS` seconds (at most 120 s); its bytes. */
    public function clip(string $id, float $startS, float $endS, ?string $listenerToken = null, ?string $shareCode = null): string
    {
        $query = Transport::listenerQuery($listenerToken, $shareCode) + ['startS' => $startS, 'endS' => $endS];
        return $this->t->raw('GET', self::path($id, '/audio/clip'), query: $query, headers: ['accept' => '*/*'])->body;
    }

    /** @return list<array<string, mixed>> VoiceTracks */
    public function voice(string $id, ?string $lang = null): array
    {
        return array_values($this->t->json('GET', self::path($id, '/voice'), query: ['lang' => $lang]) ?? []);
    }

    /**
     * @param list<string> $langs
     * @return list<array<string, mixed>> VoiceTracks
     */
    public function renderVoice(string $id, array $langs, ?string $idempotencyKey = null): array
    {
        $data = $this->t->json('POST', self::path($id, '/voice'), json: ['langs' => array_values($langs)], idempotencyKey: $idempotencyKey);
        return array_values($data ?? []);
    }

    /**
     * One sentence spoken in `lang`; the clip's bytes. `$sentence` is a
     * transcript sentence's `id`, not a segment's position. In the
     * recording's default reading: each speaker in their granted clone,
     * else a stock voice of their gender.
     */
    public function voiceClip(string $id, int $sentence, string $lang, ?string $format = null): string
    {
        $query = ['lang' => $lang, 'format' => $format];
        return $this->t->raw('GET', self::path($id, "/voice/{$sentence}"), query: $query, headers: ['accept' => '*/*'])->body;
    }

    /** @return array<string, mixed> `{ token, expiresAt }` */
    public function mediaToken(string $id): array
    {
        return $this->t->json('GET', self::path($id, '/media-token'));
    }

    public function nameSpeaker(string $id, int $speaker, ?string $name): void
    {
        $this->t->json('PUT', self::path($id, "/speakers/{$speaker}"), json: ['name' => $name]);
    }

    /**
     * The durable event log with wall times (owner only): every event
     * after `after` (a `seq`; the whole log without it), read `limit` a page.
     *
     * @return list<array<string, mixed>> RecordingEvents
     */
    public function events(string $id, ?int $after = null, ?int $limit = null): array
    {
        return self::allPages(function (?string $cursor) use ($id, $after, $limit) {
            $q = $cursor ? ['cursor' => $cursor, 'limit' => $limit] : ['after' => $after, 'limit' => $limit];
            return $this->t->json('GET', self::path($id, '/events'), query: $q);
        }, 'events');
    }

    /**
     * One page of the event log (500 by default, 1000 at most): from
     * `after` a `seq`, or the previous page's `nextCursor` as `cursor`.
     *
     * @return array<string, mixed>
     */
    public function eventsPage(string $id, ?string $cursor = null, ?int $after = null, ?int $limit = null): array
    {
        return $this->t->json('GET', self::path($id, '/events'), query: ['cursor' => $cursor, 'after' => $after, 'limit' => $limit]);
    }

    /**
     * The recording's live fan-out socket: a WebSocket whose first frame
     * is `subscribe.ready`; read the backfill with `transcript($id, after: ...)`.
     */
    public function subscribe(
        string $id,
        ?string $lang = null,
        ?string $voice = null,
        ?string $listenerToken = null,
        ?string $shareCode = null,
        bool $asOwner = true,
    ): WebSocket {
        $url = $this->t->wsUrl(self::path($id, '/subscribe'), [
            'lang' => $lang,
            'voice' => $voice,
            ...Transport::listenerQuery($listenerToken, $shareCode),
            'role' => $asOwner ? null : 'listener',
            'device' => $this->t->deviceId,
        ]);
        return self::socket($url, $asOwner ? $this->t->authHeaders() : []);
    }
}
