<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/** `$vs->rtmp`. */
final class Rtmp extends Group
{
    /**
     * The account's RTMP ingest: server URL (RTMPS where available), the
     * stream key (in full only in the answer that made it,
     * `maskedStreamKey` after), whether an encoder is sending.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->t->json('GET', '/v1/rtmp');
    }
}
