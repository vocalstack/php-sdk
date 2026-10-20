<?php

declare(strict_types=1);

namespace VocalStack\Http;

/**
 * What sends one HTTP request: the SDK's only way out to the network.
 * `CurlHttpClient` is the default; a host (WordPress, a framework, a
 * test) passes its own as `new VocalStack(httpClient: ...)`.
 *
 * An implementation must not follow redirects (the SDK reads a 3xx's
 * `Location` itself) and must not throw on 4xx/5xx: it answers them.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers lowercased names; for a
     *   MultipartBody the `content-type` (with its boundary) is set already
     * @param string|MultipartBody|null $body null: no body; a string: sent
     *   as is; a MultipartBody: stream it (`$body->chunks()`, or its
     *   `parts()` with each file part's path or stream)
     * @throws ConnectionException when no answer came (DNS, connect, timeout)
     */
    public function send(string $method, string $url, array $headers, string|MultipartBody|null $body): HttpResponse;
}
