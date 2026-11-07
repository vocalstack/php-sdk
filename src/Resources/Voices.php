<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\Transport;

final class Voices extends Group
{
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

    public function preview(string $voice, string $lang, ?string $format = null): string
    {
        $query = ['voice' => $voice, 'lang' => $lang, 'format' => $format];
        return $this->t->raw('GET', '/v1/voices/preview', query: $query, headers: ['accept' => '*/*'])->body;
    }
}
