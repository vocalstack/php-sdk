<?php

declare(strict_types=1);

namespace VocalStack\Http;

/**
 * A multipart/form-data body that streams: field parts are strings, file
 * parts are read from their path or stream as they are sent. An
 * HttpClient either streams `chunks()` (CurlHttpClient does) or rebuilds
 * the body from `parts()` with its own multipart support.
 */
final class MultipartBody
{
    public readonly string $boundary;

    /** @param list<MultipartPart> $parts */
    public function __construct(private readonly array $parts, ?string $boundary = null)
    {
        $this->boundary = $boundary ?? bin2hex(random_bytes(16));
    }

    /** @return list<MultipartPart> */
    public function parts(): array
    {
        return $this->parts;
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    /** The whole body's size, or null when a stream's size is unknown (send it chunked). */
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

    /**
     * The encoded body, a piece at a time; each call starts over.
     *
     * @return \Generator<int, string>
     */
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

    /** The encoded body in memory (for tests and small bodies; the SDK streams). */
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
