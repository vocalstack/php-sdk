<?php

declare(strict_types=1);

namespace VocalStack;

/** Result of `$vs->realtime->transcribeFile()` / `->transcribeUrl()`. */
final class RealtimeTranscript
{
    /**
     * @param list<array<string, mixed>> $segments the committed segments, in order
     * @param array<string, mixed>|null $stats `session.done`'s stats
     */
    public function __construct(
        public readonly array $segments,
        public readonly ?string $doneReason,
        public readonly ?array $stats = null,
    ) {
    }

    /** All committed text, space-joined. */
    public function text(): string
    {
        return implode(' ', array_map(static fn (array $s): string => (string) ($s['text'] ?? ''), $this->segments));
    }

    public function __toString(): string
    {
        $rt = $this->stats !== null && isset($this->stats['realtimeFactor']) ? ", realtime={$this->stats['realtimeFactor']}x" : '';
        return sprintf('RealtimeTranscript(segments=%d, doneReason=%s%s)', count($this->segments), var_export($this->doneReason, true), $rt);
    }
}
