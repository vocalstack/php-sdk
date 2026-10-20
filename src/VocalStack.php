<?php

declare(strict_types=1);

namespace VocalStack;

use VocalStack\Http\CurlHttpClient;
use VocalStack\Http\HttpClient;
use VocalStack\Http\Transport;
use VocalStack\Resources\Billing;
use VocalStack\Resources\Broadcasts;
use VocalStack\Resources\Connect;
use VocalStack\Resources\Corrections;
use VocalStack\Resources\Events;
use VocalStack\Resources\Jobs;
use VocalStack\Resources\Languages;
use VocalStack\Resources\Me;
use VocalStack\Resources\Meta;
use VocalStack\Resources\Recordings;
use VocalStack\Resources\Rtmp;
use VocalStack\Resources\Search;
use VocalStack\Resources\ShareLinks;
use VocalStack\Resources\Transcriptions;
use VocalStack\Resources\Voices;

/**
 * The VocalStack client: one class, grouped by resource.
 *
 *     $vs = new VocalStack(apiKey: getenv('VOCALSTACK_API_KEY'));
 *     $tx = $vs->transcriptions->create(['url' => 'https://example.com/talk.mp3']);
 *     $job = $vs->jobs->wait($tx['job']['recordingId']);
 *     $snap = $vs->recordings->transcript($job['recordingId']);
 *     echo implode(' ', array_column($snap['sentences'], 'text'));
 *
 * What is what: a realtime session is a signed token plus one socket,
 * nothing stored (`$vs->realtime`); a broadcast is the link listeners
 * open; a transcription is a file, link, microphone or stream transcribed
 * and stored; a recording is one take inside either; a job is a file or
 * link recording's transcription work.
 */
final class VocalStack
{
    public const VERSION = '1.0.0';

    public const DEFAULT_BASE_URL = Transport::DEFAULT_BASE_URL;

    public readonly string $baseUrl;
    public readonly Realtime $realtime;
    public readonly Broadcasts $broadcasts;
    public readonly Transcriptions $transcriptions;
    public readonly Recordings $recordings;
    public readonly Jobs $jobs;
    public readonly Corrections $corrections;
    public readonly ShareLinks $shareLinks;
    public readonly Voices $voices;
    public readonly Search $search;
    public readonly Me $me;
    public readonly Connect $connect;
    public readonly Billing $billing;
    public readonly Languages $languages;
    public readonly Rtmp $rtmp;
    public readonly Events $events;
    public readonly Meta $meta;

    /**
     * `apiKey`: an API key (`vs_key_`), for a server. `token` instead: a
     * signed-in person's access token or a browser token, or a callable
     * returning one (read per request); not with `apiKey`. `baseUrl`
     * defaults to `https://api.vocalstack.com`. A `429` is retried after
     * its `Retry-After` (at most 60 s) up to `maxRetries` times, and so is
     * a `503 unavailable` on a call that is safe to repeat. `httpClient`
     * replaces the default cURL one (it then owns `timeout`).
     *
     * @param string|callable|null $token
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        mixed $token = null,
        ?string $actingPrincipal = null,
        ?string $deviceId = null,
        int $maxRetries = 2,
        float $timeout = 300.0,
        float $readyDeadline = RealtimeSession::DEFAULT_READY_DEADLINE_S,
        ?HttpClient $httpClient = null,
    ) {
        $t = new Transport(
            $baseUrl,
            $apiKey === '' ? null : $apiKey,
            $token,
            $httpClient ?? new CurlHttpClient($timeout),
            actingPrincipal: $actingPrincipal,
            deviceId: $deviceId,
            maxRetries: $maxRetries,
        );
        $this->baseUrl = $t->baseUrl;
        $this->realtime = new Realtime($t, $readyDeadline);
        $this->broadcasts = new Broadcasts($t);
        $this->transcriptions = new Transcriptions($t);
        $this->recordings = new Recordings($t, $readyDeadline);
        $this->jobs = new Jobs($t);
        $this->corrections = new Corrections($t);
        $this->shareLinks = new ShareLinks($t);
        $this->voices = new Voices($t);
        $this->search = new Search($t);
        $this->me = new Me($t);
        $this->connect = new Connect($t);
        $this->billing = new Billing($t);
        $this->languages = new Languages($t);
        $this->rtmp = new Rtmp($t);
        $this->events = new Events($t);
        $this->meta = new Meta($t);
    }
}
