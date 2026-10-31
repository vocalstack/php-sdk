<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\WebSocket;

final class Events extends Group
{
    public function account(): WebSocket
    {
        return self::socket($this->t->wsUrl('/v1/events'), $this->t->authHeaders());
    }
}
