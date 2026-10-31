<?php

declare(strict_types=1);

namespace VocalStack\Tests\Support;

final class WsServer
{
    private $proc;
    private string $file;
    public readonly int $port;

    public function __construct(array $scenario)
    {
        $this->file = tempnam(sys_get_temp_dir(), 'vsws');
        file_put_contents($this->file, json_encode($scenario));
        $this->proc = proc_open(
            [PHP_BINARY, __DIR__ . '/ws_fixture_server.php', $this->file],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
        );
        $line = (string) fgets($pipes[1]);
        if (!preg_match('/LISTENING (\d+)/', $line, $m)) {
            throw new \RuntimeException("fixture server did not start: {$line}");
        }
        $this->port = (int) $m[1];
    }

    public function url(string $path = '/v1/realtime/sessions/t/socket'): string
    {
        return "ws://127.0.0.1:{$this->port}{$path}";
    }

    public function request(): array
    {
        $this->wait();
        return json_decode((string) @file_get_contents($this->file . '.request'), true) ?? [];
    }

    public function received(): array
    {
        $this->wait();
        return json_decode((string) @file_get_contents($this->file . '.received'), true) ?? [];
    }

    public function wait(float $seconds = 5.0): void
    {
        $until = microtime(true) + $seconds;
        while (proc_get_status($this->proc)['running'] && microtime(true) < $until) {
            usleep(20_000);
        }
    }

    public function __destruct()
    {
        if (proc_get_status($this->proc)['running']) {
            proc_terminate($this->proc);
        }
        proc_close($this->proc);
        foreach (['', '.request', '.received'] as $ext) {
            @unlink($this->file . $ext);
        }
    }
}
