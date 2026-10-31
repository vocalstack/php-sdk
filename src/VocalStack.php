<?php

declare(strict_types=1);

namespace VocalStack;

use VocalStack\Http\CurlHttpClient;
use VocalStack\Http\HttpClient;
use VocalStack\Http\Transport;
use VocalStack\Resources\Batches;
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
use VocalStack\Resources\Tags;
use VocalStack\Resources\Transcriptions;
use VocalStack\Resources\Voices;

final class VocalStack
{
    public const VERSION = '1.0.0';

    public const DEFAULT_BASE_URL = Transport::DEFAULT_BASE_URL;

    public readonly string $baseUrl;
    public readonly Realtime $realtime;
    public readonly Broadcasts $broadcasts;
    public readonly Transcriptions $transcriptions;
    public readonly Batches $batches;
    public readonly Recordings $recordings;
    public readonly Jobs $jobs;
    public readonly Corrections $corrections;
    public readonly ShareLinks $shareLinks;
    public readonly Tags $tags;
    public readonly Voices $voices;
    public readonly Search $search;
    public readonly Me $me;
    public readonly Connect $connect;
    public readonly Billing $billing;
    public readonly Languages $languages;
    public readonly Rtmp $rtmp;
    public readonly Events $events;
    public readonly Meta $meta;

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
        $this->batches = new Batches($t);
        $this->recordings = new Recordings($t, $readyDeadline);
        $this->jobs = new Jobs($t);
        $this->corrections = new Corrections($t);
        $this->shareLinks = new ShareLinks($t);
        $this->tags = new Tags($t);
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
