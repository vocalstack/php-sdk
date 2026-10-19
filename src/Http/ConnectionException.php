<?php

declare(strict_types=1);

namespace VocalStack\Http;

/** No HTTP answer came: DNS, connect, TLS or a timeout (not a VocalStackException: the API never spoke). */
class ConnectionException extends \RuntimeException
{
}
