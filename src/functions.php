<?php

declare(strict_types=1);

namespace VocalStack;

/**
 * Feed a whole PCM buffer (f32le, 16 kHz, mono) at `$speed`x realtime,
 * then `eof()`, yielding the session's transcript events as they arrive
 * in between sends, then the rest until the session ends. Convenience for
 * file/batch sources against the realtime API:
 *
 *     foreach (paceRealtime($session, $pcm) as $event) { ... }
 *
 * @return \Generator<int, array<string, mixed>>
 */
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
