<?php

declare(strict_types=1);

namespace VocalStack\Http;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use WebSocket\Connection;
use WebSocket\Middleware\ProcessHttpIncomingInterface;
use WebSocket\Middleware\ProcessHttpStack;

final class WebSocketTap implements ProcessHttpIncomingInterface
{
    public string $body = '';

    private ?Connection $connection = null;

    public function __toString(): string
    {
        return self::class;
    }

    public function processHttpIncoming(ProcessHttpStack $stack, Connection $connection): MessageInterface
    {
        $this->connection = $connection;
        $message = $stack->handleHttpIncoming();
        if ($message instanceof ResponseInterface && $message->getStatusCode() !== 101) {
            $this->body = $this->readBody($connection, $message);
        }
        return $message;
    }

    public function resource(): mixed
    {
        try {
            return $this->connection?->getStream()->getResource();
        } catch (\Throwable) {
            return null;
        }
    }

    public function timedOut(): bool
    {
        try {
            return (bool) ($this->connection?->getStream()->getMetadata('timed_out') ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    private function readBody(Connection $connection, ResponseInterface $response): string
    {
        $length = $response->getHeaderLine('Content-Length');
        $want = $length !== '' ? (int) $length : 65536;
        $out = '';
        try {
            $stream = $connection->getStream();
            $stream->setTimeout(2);
            while (strlen($out) < $want) {
                $chunk = $stream->read($want - strlen($out));
                if ($chunk === '') {
                    break;
                }
                $out .= $chunk;
            }
        } catch (\Throwable) {
        }
        return $out;
    }
}
