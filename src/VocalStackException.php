<?php

declare(strict_types=1);

namespace VocalStack;

/**
 * Any failure surfaced by this SDK: an HTTP error envelope, a socket
 * `error` frame, or one the transport synthesizes (`capacity_exceeded`).
 *
 * `errorCode` is the contract's closed error-code enum (on
 * `$vs->connect->token()`, OAuth's `error`). `details` is the envelope's
 * `data.details` (for example `metric`, `balance`, `required`, `plan`,
 * `topUpUrl` on `insufficient_credit`); `headers` are the response headers
 * (`Retry-After`, `RateLimit-*`, `WWW-Authenticate`), lowercased, when the
 * error came from an HTTP response. The message is `getMessage()`.
 */
class VocalStackException extends \RuntimeException
{
    public readonly string $errorCode;

    /**
     * @param array<string, string>|null $headers
     */
    public function __construct(
        string $code,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $status = null,
        public readonly mixed $details = null,
        public readonly ?array $headers = null,
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $code;
    }

    /** The contract's error code (`rate_limited`, `insufficient_credit`, ...). */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** The HTTP status, when the error came from an HTTP response. */
    public function status(): ?int
    {
        return $this->status;
    }

    /** The request's id (the envelope's `requestId`, else `X-Request-Id`): quote it when reporting a failure. */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /** Seconds the server asked the caller to wait (`Retry-After`), if it said. */
    public function retryAfter(): ?float
    {
        $v = $this->headers['retry-after'] ?? null;
        if ($v === null || trim($v) === '') {
            return null;
        }
        $v = trim($v);
        if (is_numeric($v)) {
            return (float) $v;
        }
        $at = strtotime($v);
        if ($at === false) {
            return null;
        }
        return max(0.0, $at - microtime(true));
    }

    public function __toString(): string
    {
        return sprintf(
            'VocalStackException(code=%s, message=%s, retryable=%s, status=%s, details=%s)',
            var_export($this->errorCode, true),
            var_export($this->getMessage(), true),
            $this->retryable ? 'true' : 'false',
            $this->status === null ? 'null' : (string) $this->status,
            json_encode($this->details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null',
        );
    }

    /**
     * Synthesized by the transport: a saturated fleet queues the socket
     * upgrade at the proxy (it hangs) or answers a bare 503, and the wire
     * never carries the envelope.
     */
    public static function capacityExceeded(string $message): self
    {
        return new self('capacity_exceeded', $message, retryable: true);
    }

    /**
     * The API's error envelope `{ code, message, data: { retryable, details } }`
     * as an exception; anything else is `internal`.
     *
     * @param array<string, string>|null $headers
     */
    public static function fromEnvelope(mixed $body, ?int $status = null, ?array $headers = null): self
    {
        $requestId = null;
        if (is_array($body) && is_string($body['requestId'] ?? null)) {
            $requestId = $body['requestId'];
        } elseif (isset($headers['x-request-id'])) {
            $requestId = $headers['x-request-id'];
        }
        if (is_array($body) && is_string($body['code'] ?? null)) {
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            $message = $body['message'] ?? $body['code'];
            return new self(
                $body['code'],
                is_string($message) ? $message : $body['code'],
                retryable: (bool) ($data['retryable'] ?? false),
                status: $status,
                details: $data['details'] ?? null,
                headers: $headers,
                requestId: $requestId,
            );
        }
        return new self(
            'internal',
            sprintf('unexpected error (status %s)', $status === null ? 'None' : (string) $status),
            status: $status,
            headers: $headers,
            requestId: $requestId,
        );
    }

    /**
     * A token endpoint's `400 { error, error_description }` (RFC 6749
     * §5.2) as an exception whose code is the `error` (`invalid_request`,
     * `invalid_grant`, `unsupported_grant_type`); any other answer goes
     * through the envelope.
     *
     * @param array<string, string>|null $headers
     */
    public static function fromOAuth(mixed $body, ?int $status = null, ?array $headers = null): self
    {
        if ($status === 400 && is_array($body) && is_string($body['error'] ?? null)) {
            $description = $body['error_description'] ?? null;
            return new self(
                $body['error'],
                is_string($description) && $description !== '' ? $description : $body['error'],
                status: $status,
                headers: $headers,
                requestId: $headers['x-request-id'] ?? null,
            );
        }
        return self::fromEnvelope($body, $status, $headers);
    }
}
