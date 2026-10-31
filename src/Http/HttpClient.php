<?php

declare(strict_types=1);

namespace VocalStack\Http;

interface HttpClient
{
    public function send(string $method, string $url, array $headers, string|MultipartBody|null $body): HttpResponse;
}
