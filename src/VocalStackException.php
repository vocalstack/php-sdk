<?php

declare(strict_types=1);

namespace VocalStack;

class VocalStackException extends \RuntimeException
{
    public readonly string $errorCode;

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

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

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

    public static function capacityExceeded(string $message): self
    {
        return new self('capacity_exceeded', $message, retryable: true);
    }

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
