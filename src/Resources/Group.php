<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\ConnectionException;
use VocalStack\Http\Transport;
use VocalStack\Http\WebSocket;
use VocalStack\Http\WebSocketRefused;
use VocalStack\Http\WebSocketTimeout;
use VocalStack\VocalStackException;

/** What every resource group shares: the transport and a few helpers. */
abstract class Group
{
    public function __construct(protected readonly Transport $t)
    {
    }

    /**
     * A request body from wire-named (camelCase) fields: top-level nulls
     * are left out, as the Python SDK's models drop unset fields.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected static function body(array $fields): array
    {
        return array_filter($fields, static fn ($v) => $v !== null);
    }

    /**
     * Every item of a paged list: each page asked for with the previous
     * page's `nextCursor` until one comes back without it.
     *
     * @param callable(?string): mixed $page
     * @return list<mixed>
     */
    protected static function allPages(callable $page, string $key): array
    {
        $out = [];
        $cursor = null;
        while (true) {
            $data = $page($cursor) ?? [];
            foreach ($data[$key] ?? [] as $item) {
                $out[] = $item;
            }
            $cursor = $data['nextCursor'] ?? null;
            if ($cursor === null || $cursor === '') {
                return $out;
            }
        }
    }

    /**
     * A list held under `$key` in the answer.
     *
     * @return list<mixed>
     */
    protected static function many(mixed $data, string $key): array
    {
        return array_values(is_array($data) ? ($data[$key] ?? []) : []);
    }

    /** A path segment, percent-encoded. */
    protected static function p(string|int $segment): string
    {
        return rawurlencode((string) $segment);
    }

    /**
     * Open an API socket (10 s for the upgrade, as Python's websockets
     * waits): a refused upgrade raises its envelope as VocalStackException.
     *
     * @param array<string, string> $headers
     */
    protected static function socket(string $url, array $headers): WebSocket
    {
        try {
            return WebSocket::connect($url, $headers, 10.0);
        } catch (WebSocketRefused $e) {
            $body = null;
            try {
                $body = $e->body === '' ? null : json_decode($e->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
            }
            throw VocalStackException::fromEnvelope($body, $e->status);
        } catch (WebSocketTimeout $e) {
            throw new ConnectionException($e->getMessage(), 0, $e);
        }
    }
}
