<?php

declare(strict_types=1);

namespace VocalStack\Http;

final class MultipartBody
{
    public readonly string $boundary;

    public function __construct(private readonly array $parts, ?string $boundary = null)
    {
        $this->boundary = $boundary ?? bin2hex(random_bytes(16));
    }

    public function parts(): array
    {
        return $this->parts;
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    public function length(): ?int
    {
        $n = strlen($this->tail());
        foreach ($this->parts as $part) {
            $size = $part->size();
            if ($size === null) {
                return null;
            }
            $n += strlen($this->open($part)) + $size + 2;
        }
        return $n;
    }

    public function chunks(int $chunkBytes = 262144): \Generator
    {
        foreach ($this->parts as $part) {
            yield $this->open($part);
            if ($part->upload === null) {
                yield (string) $part->value;
            } else {
                yield from $part->upload->chunks($chunkBytes);
            }
            yield "\r\n";
        }
        yield $this->tail();
    }

    public function contents(): string
    {
        return implode('', iterator_to_array($this->chunks(), false));
    }

    private function open(MultipartPart $part): string
    {
        return '--' . $this->boundary . "\r\n" . $part->head();
    }

    private function tail(): string
    {
        return '--' . $this->boundary . "--\r\n";
    }
}
