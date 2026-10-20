<?php

// The router for `php -S`: answers what it received, for CurlHttpClientTest.
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/redirect') {
    header('Location: https://media.test/signed', true, 302);
    exit;
}
if ($path === '/error') {
    http_response_code(429);
    header('Retry-After: 7');
    header('Content-Type: application/json');
    echo json_encode(['code' => 'rate_limited', 'message' => 'slow down', 'data' => ['retryable' => true]]);
    exit;
}
$files = [];
foreach ($_FILES as $name => $f) {
    $files[$name] = [
        'name' => $f['name'],
        'type' => $f['type'],
        'size' => $f['size'],
        'sha1' => $f['error'] === UPLOAD_ERR_OK ? sha1_file($f['tmp_name']) : null,
        'error' => $f['error'],
    ];
}
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
    'contentLength' => $_SERVER['CONTENT_LENGTH'] ?? null,
    'headers' => $headers,
    'post' => $_POST,
    'files' => $files,
    'raw' => str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/') ? null : file_get_contents('php://input'),
]);
