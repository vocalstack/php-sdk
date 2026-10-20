<?php

declare(strict_types=1);

namespace VocalStack;

/**
 * A file to upload: a path, bytes, or an open stream. A path or a stream
 * is streamed, never read whole (uploads run to hundreds of megabytes).
 *
 * Wherever the SDK takes a file, a plain string is a path, a stream
 * resource is read from its current position, and raw bytes are
 * `Upload::bytes($data, 'name.mp3')`.
 */
final class Upload
{
    /** @param resource|null $stream */
    private function __construct(
        public readonly ?string $path,
        public readonly ?string $data,
        public readonly mixed $stream,
        public readonly ?string $name,
    ) {
    }

    /** A file on disk. */
    public static function path(string $path, ?string $name = null): self
    {
        return new self($path, null, null, $name);
    }

    /** Bytes already in memory. */
    public static function bytes(string $data, ?string $name = null): self
    {
        return new self(null, $data, null, $name);
    }

    /**
     * An open stream, read from where it stands (rewound to that point
     * when a retried request sends it again, if it can seek).
     *
     * @param resource $stream
     */
    public static function stream(mixed $stream, ?string $name = null): self
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Upload::stream: not a stream resource');
        }
        return new self(null, null, $stream, $name);
    }

    /**
     * What the SDK's methods accept, as an Upload: a path string, an
     * Upload, or a stream resource.
     *
     * @param string|Upload|resource $file
     */
    public static function from(mixed $file): self
    {
        if ($file instanceof self) {
            return $file;
        }
        if (is_string($file)) {
            return self::path($file);
        }
        if (is_resource($file)) {
            return self::stream($file);
        }
        throw new \InvalidArgumentException('a file is a path, an Upload, or a stream resource');
    }

    /** The file name sent: the explicit one, else the path's or stream's base name, else `$default`. */
    public function fileName(?string $override = null, string $default = 'upload'): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }
        if ($this->path !== null) {
            return basename($this->path);
        }
        if ($this->stream !== null) {
            $uri = stream_get_meta_data($this->stream)['uri'] ?? '';
            $base = is_string($uri) ? basename($uri) : '';
            if ($base !== '' && !str_starts_with((string) $uri, 'php://')) {
                return $base;
            }
        }
        return $default;
    }

    /** The size in bytes, when it is known without reading. */
    public function size(): ?int
    {
        if ($this->data !== null) {
            return strlen($this->data);
        }
        if ($this->path !== null) {
            clearstatcache(true, $this->path);
            $size = @filesize($this->path);
            return $size === false ? null : $size;
        }
        $meta = stream_get_meta_data($this->stream);
        if (!($meta['seekable'] ?? false)) {
            return null;
        }
        $stat = @fstat($this->stream);
        $start = $this->start ?? @ftell($this->stream);
        if ($stat === false || !isset($stat['size']) || $start === false) {
            return null;
        }
        return max(0, (int) $stat['size'] - $start);
    }

    /**
     * The bytes, `$chunkBytes` at a time. Each call starts over: a path is
     * opened again, a seekable stream is rewound to where it first stood.
     *
     * @return \Generator<int, string>
     */
    public function chunks(int $chunkBytes = 262144): \Generator
    {
        if ($this->data !== null) {
            for ($off = 0, $n = strlen($this->data); $off < $n; $off += $chunkBytes) {
                yield substr($this->data, $off, $chunkBytes);
            }
            return;
        }
        if ($this->path !== null) {
            $fh = @fopen($this->path, 'rb');
            if ($fh === false) {
                throw new \RuntimeException("cannot open {$this->path}");
            }
            try {
                while (!feof($fh)) {
                    $chunk = fread($fh, $chunkBytes);
                    if ($chunk === false) {
                        throw new \RuntimeException("cannot read {$this->path}");
                    }
                    if ($chunk !== '') {
                        yield $chunk;
                    }
                }
            } finally {
                fclose($fh);
            }
            return;
        }
        $this->rewind();
        while (!feof($this->stream)) {
            $chunk = fread($this->stream, $chunkBytes);
            if ($chunk === false) {
                throw new \RuntimeException('cannot read the upload stream');
            }
            if ($chunk === '') {
                if (feof($this->stream)) {
                    break;
                }
                continue;
            }
            yield $chunk;
        }
    }

    /** Every byte, in memory (for small uploads and tests; the SDK itself streams). */
    public function contents(): string
    {
        $out = '';
        foreach ($this->chunks() as $chunk) {
            $out .= $chunk;
        }
        return $out;
    }

    private ?int $start = null;

    private function rewind(): void
    {
        $meta = stream_get_meta_data($this->stream);
        if (!($meta['seekable'] ?? false)) {
            return;
        }
        if ($this->start === null) {
            $pos = ftell($this->stream);
            $this->start = $pos === false ? 0 : $pos;
        } else {
            fseek($this->stream, $this->start);
        }
    }
}
