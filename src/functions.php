<?php

declare(strict_types=1);

namespace VocalStack;

function paceRealtime(RealtimeSession $session, string $pcm, float $chunkSeconds = 0.25, float $speed = 1.0): \Generator
{
    $bytesPerSecond = 16000 * 4;
    $chunk = max(4, ((int) ($bytesPerSecond * $chunkSeconds)) & ~3);
    $start = microtime(true);
    $sentS = 0.0;
    for ($off = 0, $n = strlen($pcm); $off < $n; $off += $chunk) {
        if ($session->ended()) {
            break;
        }
        $piece = substr($pcm, $off, $chunk);
        $session->sendAudio($piece);
        $sentS += strlen($piece) / $bytesPerSecond;
        $delay = $start + $sentS / $speed - microtime(true);
        if ($delay > 0) {
            foreach ($session->poll($delay) as $event) {
                yield $event;
            }
        }
    }
    $session->eof();
    foreach ($session->events() as $event) {
        yield $event;
    }
}
