<?php

declare(strict_types=1);

namespace VocalStack\Http;

final class HttpResponse
{
    public readonly array $headers;

    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $out = [];
        foreach ($headers as $name => $value) {
            $value = is_array($value) ? implode(', ', $value) : (string) $value;
            $key = strtolower((string) $name);
            $out[$key] = isset($out[$key]) ? $out[$key] . ', ' . $value : $value;
        }
        $this->headers = $out;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
