<?php

declare(strict_types=1);

namespace VocalStack;

final class RealtimeTranscript
{
    public function __construct(
        public readonly array $segments,
        public readonly ?string $doneReason,
        public readonly ?array $stats = null,
    ) {
    }

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
