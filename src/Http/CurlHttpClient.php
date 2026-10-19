<?php

declare(strict_types=1);

namespace VocalStack\Http;

/**
 * The default HttpClient, over ext-curl. Multipart bodies are streamed
 * through CURLOPT_READFUNCTION: a file is never read whole into memory.
 */
final class CurlHttpClient implements HttpClient
{
    /** @param float $timeout seconds for the whole request (0: none) */
    public function __construct(private readonly float $timeout = 300.0)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException(
                'VocalStack: the default HTTP client needs ext-curl; install it, or pass your own '
                . 'VocalStack\Http\HttpClient as new VocalStack(httpClient: ...)'
            );
        }
    }

    public function send(string $method, string $url, array $headers, string|MultipartBody|null $body): HttpResponse
    {
        $ch = curl_init();
        $responseHeaders = [];
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => (int) round($this->timeout * 1000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $len = strlen($line);
                if (preg_match('#^HTTP/\S+\s+\d+#', $line)) {
                    $responseHeaders = []; // a new response (after a 100 Continue)
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))][] = trim($value);
                }
                return $len;
            },
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        $headers['expect'] = '';
        if ($body instanceof MultipartBody) {
            $length = $body->length();
            if ($length === null) {
                $headers['transfer-encoding'] = 'chunked';
            } else {
                $headers['content-length'] = (string) $length;
            }
            $chunks = $body->chunks();
            $buffer = '';
            $started = false;
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_READFUNCTION] = static function ($ch, $fd, int $want) use ($chunks, &$buffer, &$started): string {
                while (strlen($buffer) < $want) {
                    if ($started) {
                        $chunks->next();
                    }
                    $started = true;
                    if (!$chunks->valid()) {
                        break;
                    }
                    $buffer .= $chunks->current();
                }
                $out = substr($buffer, 0, $want);
                $buffer = (string) substr($buffer, $want);
                return $out;
            };
        } elseif ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $value === '' ? "{$name}:" : "{$name}: {$value}";
        }
        $opts[CURLOPT_HTTPHEADER] = $lines;
        curl_setopt_array($ch, $opts);
        $content = curl_exec($ch);
        if ($content === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            throw new ConnectionException("{$method} {$url}: {$error}", $errno);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return new HttpResponse($status, $responseHeaders, is_string($content) ? $content : '');
    }
}
