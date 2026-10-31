<?php

declare(strict_types=1);

namespace VocalStack\Tests\Support;

use VocalStack\Http\HttpClient;
use VocalStack\Http\HttpResponse;
use VocalStack\Http\MultipartBody;

final class FakeHttpClient implements HttpClient
{
    public array $calls = [];

    public function __construct(private readonly array $answers)
    {
    }

    public function send(string $method, string $url, array $headers, string|MultipartBody|null $body): HttpResponse
    {
        $call = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'content' => $body instanceof MultipartBody ? $body->contents() : (string) $body,
        ];
        $this->calls[] = $call;
        $answer = $this->answers[min(count($this->calls) - 1, count($this->answers) - 1)];
        if (is_callable($answer)) {
            return $answer($call);
        }
        [$status, $json] = $answer;
        $h = ($answer[2] ?? []) + ['content-type' => 'application/json'];
        return new HttpResponse($status, $h, $json === null ? '' : (string) json_encode($json));
    }

    public function query(int $i): array
    {
        parse_str((string) parse_url($this->calls[$i]['url'], PHP_URL_QUERY), $q);
        return $q;
    }
}
