<?php

declare(strict_types=1);

namespace VocalStack\Http;

/** @internal A socket upgrade that was not answered in time. */
final class WebSocketTimeout extends \RuntimeException
{
}
