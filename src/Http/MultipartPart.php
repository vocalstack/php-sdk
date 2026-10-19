<?php

declare(strict_types=1);

namespace VocalStack\Http;

use VocalStack\Upload;

/**
 * One part of a multipart/form-data body: a field (`value`) or a file
 * (`upload`, streamed from its path or stream).
 */
final class MultipartPart
{
    private function __construct(
        public readonly string $name,
        public readonly ?string $value,
        public readonly ?Upload $upload,
        public readonly ?string $filename,
        public readonly ?string $contentType,
    ) {
    }

    /** A form field; `contentType` set gives it its own header (a JSON part). */
    public static function field(string $name, string $value, ?string $contentType = null): self
    {
        return new self($name, $value, null, null, $contentType);
    }

    /** A file part. */
    public static function file(string $name, Upload $upload, string $filename, ?string $contentType = null): self
    {
        return new self($name, null, $upload, $filename, $contentType ?? self::guessType($filename));
    }

    /** The part's headers, CRLF-terminated, blank line included. */
    public function head(): string
    {
        $disposition = 'Content-Disposition: form-data; name="' . self::quote($this->name) . '"';
        if ($this->filename !== null) {
            $disposition .= '; filename="' . self::quote($this->filename) . '"';
        }
        $head = $disposition . "\r\n";
        if ($this->contentType !== null) {
            $head .= 'Content-Type: ' . $this->contentType . "\r\n";
        }
        return $head . "\r\n";
    }

    /** The content's size, when it is known without reading. */
    public function size(): ?int
    {
        return $this->upload === null ? strlen((string) $this->value) : $this->upload->size();
    }

    private static function quote(string $s): string
    {
        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '%22', '%0D', '%0A'], $s);
    }

    /** A file's media type from its extension (as Python's `mimetypes` would), else `application/octet-stream`. */
    public static function guessType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/x-wav',
            'flac' => 'audio/flac',
            'ogg', 'oga', 'opus' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'webm' => 'video/webm',
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'json' => 'application/json',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}
