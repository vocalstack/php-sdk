<?php

declare(strict_types=1);

namespace VocalStack\Http;

use VocalStack\VocalStack;
use VocalStack\VocalStackException;

/** The one HTTP path every resource method goes through. */
final class Transport
{
    public const DEFAULT_BASE_URL = 'https://api.vocalstack.com';

    /** The wait before retrying `503 unavailable` when it names none. */
    public const UNAVAILABLE_WAIT_S = 2.0;

    public readonly string $baseUrl;

    /** @var string|\Closure|null */
    private mixed $token;

    /**
     * @param string|callable|null $token a token, or a callable returning one (read per request)
     */
    public function __construct(
        ?string $baseUrl,
        private readonly ?string $apiKey,
        mixed $token,
        public readonly HttpClient $http,
        public readonly ?string $actingPrincipal = null,
        public readonly ?string $deviceId = null,
        public readonly int $maxRetries = 2,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        if ($apiKey && $token) {
            throw new \InvalidArgumentException('VocalStack: pass apiKey (a server) or token (a browser), not both');
        }
        if ($token !== null && !is_string($token)) {
            if (!is_callable($token)) {
                throw new \InvalidArgumentException('VocalStack: token is a string or a callable returning one');
            }
            $token = \Closure::fromCallable($token);
        }
        $this->token = $token;
    }

    /** What goes in `Authorization: Bearer`: the API key, else the token. */
    public function bearer(): ?string
    {
        if ($this->apiKey) {
            return $this->apiKey;
        }
        $t = $this->token;
        return $t instanceof \Closure ? $t() : $t;
    }

    /**
     * The credential as a header (sockets too: a key is refused in a URL).
     *
     * @return array<string, string>
     */
    public function authHeaders(): array
    {
        $key = $this->bearer();
        return $key ? ['authorization' => "Bearer {$key}"] : [];
    }

    public function url(string $path): string
    {
        return $this->baseUrl . $path;
    }

    /** @param array<string, mixed>|null $query */
    public function wsUrl(string $path, ?array $query = null): string
    {
        $base = str_starts_with($this->baseUrl, 'http') ? 'ws' . substr($this->baseUrl, 4) : $this->baseUrl;
        $q = self::clean($query);
        return $base . $path . ($q ? '?' . self::encodeQuery($q) : '');
    }

    /**
     * Drops null and empty values; booleans go out as `true`/`false`.
     *
     * @param array<string, mixed>|null $q
     * @return array<string, string>
     */
    public static function clean(?array $q): array
    {
        $out = [];
        foreach ($q ?? [] as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $out[$k] = self::scalar($v);
        }
        return $out;
    }

    /** A query value as Python's `str()` writes it (`1.0`, not `1`). */
    public static function scalar(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_float($v)) {
            return json_encode($v, JSON_PRESERVE_ZERO_FRACTION) ?: (string) $v;
        }
        return (string) $v;
    }

    /** @param array<string, string> $q */
    public static function encodeQuery(array $q): string
    {
        return http_build_query($q, '', '&', PHP_QUERY_RFC1738);
    }

    /**
     * The query of a listener-side read (a private broadcast's listener
     * token, or a share link's code), named as on the wire.
     *
     * @return array<string, ?string>
     */
    public static function listenerQuery(?string $listenerToken, ?string $shareCode): array
    {
        return ['listenerToken' => $listenerToken, 'shareCode' => $shareCode];
    }

    /**
     * Whether a failed call is retried after its `Retry-After` (up to
     * `maxRetries` times): `429 rate_limited` always (nothing ran), and
     * `503 unavailable` (the database is waking) only when repeating the
     * call cannot do the work twice: GET, HEAD and DELETE, or a call
     * carrying an `Idempotency-Key`.
     */
    public static function retries(int $status, string $code, string $method, ?string $idempotencyKey = null): bool
    {
        if ($status === 429) {
            return true;
        }
        if ($status !== 503 || $code !== 'unavailable') {
            return false;
        }
        return in_array(strtoupper($method), ['GET', 'HEAD', 'DELETE'], true) || (bool) $idempotencyKey;
    }

    /**
     * One call; an error answer raises VocalStackException (after the retries).
     *
     * @param array<string, mixed>|null $query
     * @param array<mixed>|null $json the JSON body (an empty array is `{}`)
     * @param array<string, string>|null $form a form-encoded body
     * @param array<string, string> $headers
     */
    public function raw(
        string $method,
        string $path,
        ?array $query = null,
        ?array $json = null,
        ?MultipartBody $multipart = null,
        ?array $form = null,
        array $headers = [],
        ?string $idempotencyKey = null,
        bool $anonymous = false,
        bool $noRetry = false,
        bool $oauthErrors = false,
    ): HttpResponse {
        $h = ['accept' => 'application/json', 'user-agent' => 'vocalstack-php/' . VocalStack::VERSION];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }
        $key = $anonymous ? null : $this->bearer();
        if ($key) {
            $h['authorization'] = "Bearer {$key}";
        }
        if ($this->actingPrincipal) {
            $h['x-acting-principal'] = $this->actingPrincipal;
        }
        if ($this->deviceId) {
            $h['x-device-id'] = $this->deviceId;
        }
        if ($idempotencyKey) {
            $h['idempotency-key'] = $idempotencyKey;
        }
        $body = null;
        if ($multipart !== null) {
            $h['content-type'] = $multipart->contentType();
            $body = $multipart;
        } elseif ($form !== null) {
            $h['content-type'] = 'application/x-www-form-urlencoded';
            $body = http_build_query($form, '', '&', PHP_QUERY_RFC1738);
        } elseif ($json !== null) {
            $h['content-type'] = 'application/json';
            $body = self::encodeJson($json);
        }
        $q = self::clean($query);
        $url = $this->url($path) . ($q ? '?' . self::encodeQuery($q) : '');
        $attempt = 0;
        while (true) {
            $resp = $this->http->send($method, $url, $h, $body);
            if ($resp->status < 400) {
                return $resp;
            }
            $parsed = null;
            if ($resp->body !== '') {
                try {
                    $parsed = json_decode($resp->body, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $parsed = null;
                }
            }
            $err = $oauthErrors
                ? VocalStackException::fromOAuth($parsed, $resp->status, $resp->headers)
                : VocalStackException::fromEnvelope($parsed, $resp->status, $resp->headers);
            if (!$noRetry && self::retries($resp->status, $err->errorCode, $method, $idempotencyKey) && $attempt < $this->maxRetries) {
                $wait = $err->retryAfter();
                if ($wait === null && $err->errorCode === 'unavailable') {
                    $wait = self::UNAVAILABLE_WAIT_S;
                }
                if ($wait !== null && $wait <= 60) {
                    $attempt++;
                    if ($wait > 0) {
                        usleep((int) round($wait * 1_000_000));
                    }
                    continue;
                }
            }
            throw $err;
        }
    }

    /**
     * A call answered with JSON (decoded to arrays); a 3xx answers
     * `['location' => ...]`, an empty body null.
     *
     * @param array<string, mixed>|null $query
     * @param array<mixed>|null $json
     * @param array<string, string>|null $form
     * @param array<string, string> $headers
     */
    public function json(
        string $method,
        string $path,
        ?array $query = null,
        ?array $json = null,
        ?MultipartBody $multipart = null,
        ?array $form = null,
        array $headers = [],
        ?string $idempotencyKey = null,
        bool $anonymous = false,
        bool $noRetry = false,
        bool $oauthErrors = false,
    ): mixed {
        $resp = $this->raw($method, $path, $query, $json, $multipart, $form, $headers, $idempotencyKey, $anonymous, $noRetry, $oauthErrors);
        if ($resp->status >= 300 && $resp->status < 400) {
            return ['location' => $resp->header('location')];
        }
        return $resp->body === '' ? null : json_decode($resp->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /** JSON as the wire wants it: an empty top-level array is `{}`. */
    public static function encodeJson(mixed $value): string
    {
        if ($value === []) {
            return '{}';
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
