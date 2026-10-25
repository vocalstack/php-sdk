<?php

declare(strict_types=1);

namespace VocalStack\Http;

use WebSocket\Client;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Exception\HandshakeException;
use WebSocket\Message\Binary;
use WebSocket\Message\Close;
use WebSocket\Message\Ping;
use WebSocket\Message\Pong;
use WebSocket\Message\Text;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

/**
 * One WebSocket, over phrity/websocket (loaded only when a socket is
 * opened). What `$vs->recordings->subscribe()`, `->events()` and
 * `$vs->events->account()` return, and what a RealtimeSession reads.
 *
 * `receive()` reads the next frame with a timeout that never splits a
 * frame: it waits until the socket is readable, then reads one whole
 * frame. Pings are answered and close handshakes completed for you.
 */
final class WebSocket
{
    public const KIND_TEXT = 'text';
    public const KIND_BINARY = 'binary';
    public const KIND_PING = 'ping';
    public const KIND_PONG = 'pong';
    public const KIND_CLOSE = 'close';

    private bool $closed = false;

    private function __construct(private readonly Client $client, private readonly WebSocketTap $tap)
    {
    }

    /** Fails clearly when phrity/websocket is not installed. */
    public static function requireLibrary(): void
    {
        if (!class_exists(Client::class)) {
            throw new \RuntimeException(
                'VocalStack: realtime and event sockets need phrity/websocket: composer require phrity/websocket:^3'
            );
        }
    }

    /**
     * Open the socket. `$timeout` covers the TCP connect and the upgrade.
     *
     * @param array<string, string> $headers
     * @throws WebSocketRefused the server answered the upgrade with another status
     * @throws ConnectionException no socket (refused, DNS, closed before an answer)
     * @throws WebSocketTimeout the upgrade was not answered in time
     */
    public static function connect(string $url, array $headers = [], float $timeout = 30.0): self
    {
        self::requireLibrary();
        $tap = new WebSocketTap();
        $client = new Client($url);
        $client->setTimeout($timeout);
        $client->setFrameSize(4 * 1024 * 1024);
        $client->addMiddleware(new CloseHandler());
        $client->addMiddleware(new PingResponder());
        $client->addMiddleware($tap);
        foreach ($headers as $name => $value) {
            $client->addHeader($name, $value);
        }
        $client->addHeader('User-Agent', 'vocalstack-php/' . \VocalStack\VocalStack::VERSION);
        try {
            $client->connect();
        } catch (HandshakeException $e) {
            $resp = $e->getResponse();
            self::shut($client);
            throw new WebSocketRefused($resp->getStatusCode(), $tap->body, $e);
        } catch (ConnectionTimeoutException $e) {
            self::shut($client);
            throw new WebSocketTimeout("the upgrade was not answered within {$timeout}s", 0, $e);
        } catch (\Throwable $e) {
            self::shut($client);
            if ($tap->timedOut()) {
                throw new WebSocketTimeout("the upgrade was not answered within {$timeout}s", 0, $e);
            }
            throw new ConnectionException($e->getMessage(), 0, $e);
        }
        return new self($client, $tap);
    }

    /** The phrity client underneath, for anything this wrapper does not cover. */
    public function client(): Client
    {
        return $this->client;
    }

    public function isOpen(): bool
    {
        return !$this->closed && $this->client->isConnected();
    }

    /** Send a text frame (false: the socket is closed). */
    public function sendText(string $text): bool
    {
        return $this->push(fn () => $this->client->text($text));
    }

    /** Send a binary frame (false: the socket is closed). */
    public function sendBinary(string $data): bool
    {
        return $this->push(fn () => $this->client->binary($data));
    }

    /** Send a ping (false: the socket is closed). */
    public function ping(): bool
    {
        return $this->push(fn () => $this->client->ping());
    }

    /**
     * The next frame as `[kind, payload]`, or null when none came within
     * `$timeout` seconds (null: wait for ever). A closed socket answers
     * `['close', '']` from then on; the peer's close frame itself carries its
     * status code as the payload (`'1012'`), once.
     *
     * @return array{0: string, 1: string}|null
     */
    public function receive(?float $timeout = null): ?array
    {
        if (!$this->isOpen()) {
            $this->closed = true;
            return [self::KIND_CLOSE, ''];
        }
        if (!$this->readable($timeout)) {
            return null;
        }
        try {
            // A frame has begun: give the rest of it time to arrive.
            $this->client->setTimeout(max(60.0, (float) $timeout));
            $message = $this->client->receive();
        } catch (ConnectionTimeoutException) {
            return null;
        } catch (\Throwable) {
            $this->closed = true;
            self::shut($this->client);
            return [self::KIND_CLOSE, ''];
        }
        return match (true) {
            $message instanceof Text => [self::KIND_TEXT, $message->getContent()],
            $message instanceof Binary => [self::KIND_BINARY, $message->getContent()],
            $message instanceof Ping => [self::KIND_PING, $message->getContent()],
            $message instanceof Pong => [self::KIND_PONG, $message->getContent()],
            $message instanceof Close => $this->closedByPeer($message->getCloseStatus()),
            default => [self::KIND_BINARY, ''],
        };
    }

    /**
     * Text frames until the socket closes (binary and control frames are
     * skipped), each JSON frame decoded to an array.
     *
     * @return \Generator<int, mixed>
     */
    public function messages(): \Generator
    {
        while (true) {
            $frame = $this->receive();
            if ($frame === null) {
                continue;
            }
            [$kind, $payload] = $frame;
            if ($kind === self::KIND_CLOSE) {
                return;
            }
            if ($kind !== self::KIND_TEXT) {
                continue;
            }
            try {
                yield json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                yield $payload;
            }
        }
    }

    /** Close (1000), waiting briefly for the peer's acknowledgement. */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            if ($this->client->isConnected() && $this->client->isWritable()) {
                $this->client->close($code, $reason);
                $deadline = microtime(true) + 2.0;
                while ($this->client->isConnected() && ($left = $deadline - microtime(true)) > 0) {
                    if (!$this->readable($left)) {
                        break;
                    }
                    $this->client->setTimeout(max(0.1, $left));
                    if ($this->client->receive() instanceof Close) {
                        break;
                    }
                }
            }
        } catch (\Throwable) {
        }
        self::shut($this->client);
    }

    public function __destruct()
    {
        if (!$this->closed) {
            self::shut($this->client);
        }
    }

    /** @return array{0: string, 1: string} */
    private function closedByPeer(?int $status = null): array
    {
        $this->closed = true;
        self::shut($this->client);
        return [self::KIND_CLOSE, $status === null ? '' : (string) $status];
    }

    private function readable(?float $timeout): bool
    {
        $res = $this->tap->resource();
        if ($res === null || !is_resource($res)) {
            return true;
        }
        $meta = stream_get_meta_data($res);
        if (($meta['unread_bytes'] ?? 0) > 0) {
            return true;
        }
        $r = [$res];
        $w = null;
        $e = null;
        if ($timeout === null) {
            $n = @stream_select($r, $w, $e, null);
        } else {
            $t = max(0.0, $timeout);
            $sec = (int) floor($t);
            $n = @stream_select($r, $w, $e, $sec, (int) round(($t - $sec) * 1_000_000));
        }
        return $n === false || $n > 0;
    }

    private function push(callable $send): bool
    {
        if ($this->closed || !$this->client->isConnected() || !$this->client->isWritable()) {
            return false;
        }
        try {
            $send();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function shut(Client $client): void
    {
        try {
            $client->disconnect();
        } catch (\Throwable) {
        }
    }
}
