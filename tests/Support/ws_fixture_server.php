<?php

// A one-connection WebSocket server for the tests, the twin of
// conformance/replay-server.mjs: it plays a scenario's frames
// ({"$waitEof": true} waits for the client's eof, {"$close": true} closes,
// {"$delayMs": n} sleeps), or refuses the upgrade (rejectUpgrade).
//
// Usage: php ws_fixture_server.php <scenario.json>; prints "LISTENING <port>".

declare(strict_types=1);

$scenario = json_decode((string) file_get_contents($argv[1]), true);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen: {$errstr}\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
echo 'LISTENING ' . substr($name, strrpos($name, ':') + 1) . "\n";
fflush(STDOUT);

$conn = @stream_socket_accept($server, 30);
if ($conn === false) {
    exit(0);
}
$head = '';
while (!str_contains($head, "\r\n\r\n")) {
    $chunk = fread($conn, 4096);
    if ($chunk === '' || $chunk === false) {
        exit(0);
    }
    $head .= $chunk;
}
$headers = [];
foreach (array_slice(explode("\r\n", substr($head, 0, strpos($head, "\r\n\r\n"))), 1) as $line) {
    if (str_contains($line, ':')) {
        [$k, $v] = explode(':', $line, 2);
        $headers[strtolower(trim($k))] = trim($v);
    }
}
file_put_contents($argv[1] . '.request', json_encode(['head' => $head, 'headers' => $headers]));

if (isset($scenario['rejectUpgrade'])) {
    $status = $scenario['rejectUpgrade']['status'];
    $body = array_key_exists('body', $scenario['rejectUpgrade'])
        ? (is_string($scenario['rejectUpgrade']['body']) ? $scenario['rejectUpgrade']['body'] : json_encode($scenario['rejectUpgrade']['body']))
        : '';
    fwrite($conn, "HTTP/1.1 {$status} Rejected\r\ncontent-type: application/json\r\ncontent-length: " . strlen($body) . "\r\nconnection: close\r\n\r\n{$body}");
    fclose($conn);
    exit(0);
}
if (!empty($scenario['hang'])) {
    sleep((int) $scenario['hang']);
    exit(0);
}

$accept = base64_encode(sha1($headers['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
fwrite($conn, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");

$buf = '';
$eofSeen = false;
$received = [];

function send_text($conn, string $text): void
{
    $len = strlen($text);
    if ($len < 126) {
        $h = chr(0x81) . chr($len);
    } elseif ($len < 65536) {
        $h = chr(0x81) . chr(126) . pack('n', $len);
    } else {
        $h = chr(0x81) . chr(127) . pack('J', $len);
    }
    fwrite($conn, $h . $text);
}

function read_frames($conn, string &$buf, bool &$eofSeen, array &$received, float $timeout): bool
{
    $r = [$conn];
    $w = $e = null;
    $sec = (int) floor($timeout);
    if (@stream_select($r, $w, $e, $sec, (int) (($timeout - $sec) * 1e6)) < 1) {
        return true;
    }
    $chunk = fread($conn, 65536);
    if ($chunk === '' || $chunk === false) {
        return false;
    }
    $buf .= $chunk;
    while (strlen($buf) >= 2) {
        $op = ord($buf[0]) & 0x0f;
        $masked = (ord($buf[1]) & 0x80) !== 0;
        $len = ord($buf[1]) & 0x7f;
        $off = 2;
        if ($len === 126) {
            if (strlen($buf) < 4) {
                return true;
            }
            $len = unpack('n', substr($buf, 2, 2))[1];
            $off = 4;
        } elseif ($len === 127) {
            if (strlen($buf) < 10) {
                return true;
            }
            $len = unpack('J', substr($buf, 2, 8))[1];
            $off = 10;
        }
        $mask = '';
        if ($masked) {
            $mask = substr($buf, $off, 4);
            $off += 4;
        }
        if (strlen($buf) < $off + $len) {
            return true;
        }
        $payload = substr($buf, $off, $len);
        if ($masked) {
            for ($i = 0; $i < $len; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }
        $buf = substr($buf, $off + $len);
        $received[] = ['op' => $op, 'payload' => $op === 1 ? $payload : strlen($payload)];
        if ($op === 8) {
            return false;
        }
        if ($op === 1 && (json_decode($payload, true)['type'] ?? null) === 'eof') {
            $eofSeen = true;
        }
    }
    return true;
}

$open = true;
foreach ($scenario['frames'] ?? [] as $entry) {
    if (!empty($entry['$waitEof'])) {
        $until = microtime(true) + 20;
        while ($open && !$eofSeen && microtime(true) < $until) {
            $open = read_frames($conn, $buf, $eofSeen, $received, 0.05);
        }
        continue;
    }
    if (!empty($entry['$close'])) {
        break;
    }
    if (isset($entry['$delayMs'])) {
        $until = microtime(true) + $entry['$delayMs'] / 1000;
        while ($open && microtime(true) < $until) {
            $open = read_frames($conn, $buf, $eofSeen, $received, max(0.0, $until - microtime(true)));
        }
        continue;
    }
    send_text($conn, json_encode($entry));
}
@fwrite($conn, chr(0x88) . chr(0));
$until = microtime(true) + 1;
while ($open && microtime(true) < $until) {
    $open = read_frames($conn, $buf, $eofSeen, $received, 0.05);
}
file_put_contents($argv[1] . '.received', json_encode($received));
fclose($conn);
