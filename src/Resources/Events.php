<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\WebSocket;

/** `$vs->events`. */
final class Events extends Group
{
    /**
     * The account socket: `events.ready`, then `lists.changed` whenever a
     * list of broadcasts or transcriptions is out of date.
     */
    public function account(): WebSocket
    {
        return self::socket($this->t->wsUrl('/v1/events'), $this->t->authHeaders());
    }
}
