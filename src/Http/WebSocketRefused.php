<?php

declare(strict_types=1);

namespace VocalStack\Http;

/** @internal The server answered a socket upgrade with something other than 101. */
final class WebSocketRefused extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $body, ?\Throwable $previous = null)
    {
        parent::__construct("the upgrade was refused ({$status})", 0, $previous);
    }
}
