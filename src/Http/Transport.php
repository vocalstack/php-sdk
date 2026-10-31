<?php

declare(strict_types=1);

namespace VocalStack\Http;

use VocalStack\VocalStack;
use VocalStack\VocalStackException;

final class Transport
{
    public const DEFAULT_BASE_URL = 'https://api.vocalstack.com';

    public const UNAVAILABLE_WAIT_S = 2.0;

    public readonly string $baseUrl;

    private mixed $token;

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

    public function bearer(): ?string
    {
        if ($this->apiKey) {
            return $this->apiKey;
        }
        $t = $this->token;
        return $t instanceof \Closure ? $t() : $t;
    }

    public function authHeaders(): array
    {
        $key = $this->bearer();
        return $key ? ['authorization' => "Bearer {$key}"] : [];
    }

    public function url(string $path): string
    {
        return $this->baseUrl . $path;
    }

    public function wsUrl(string $path, ?array $query = null): string
    {
        $base = str_starts_with($this->baseUrl, 'http') ? 'ws' . substr($this->baseUrl, 4) : $this->baseUrl;
        $q = self::clean($query);
        return $base . $path . ($q ? '?' . self::encodeQuery($q) : '');
    }

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

    public static function encodeQuery(array $q): string
    {
        return http_build_query($q, '', '&', PHP_QUERY_RFC1738);
    }

    public static function listenerQuery(?string $listenerToken, ?string $shareCode): array
    {
        return ['listenerToken' => $listenerToken, 'shareCode' => $shareCode];
    }

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

    public static function encodeJson(mixed $value): string
    {
        if ($value === []) {
            return '{}';
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
