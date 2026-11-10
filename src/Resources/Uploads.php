<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\ConnectionException;
use VocalStack\Upload;
use VocalStack\VocalStackException;

final class Uploads extends Group
{
    public function create(array $fields): array
    {
        return $this->t->json('POST', '/v1/uploads', json: self::body($fields));
    }

    public function complete(string $id, array $fields): array
    {
        return $this->t->json('POST', '/v1/uploads/' . self::p($id) . '/complete', json: self::body($fields));
    }

    public function send(mixed $file, ?string $fileName = null, ?string $mime = null, ?callable $onProgress = null): array
    {
        $upload = Upload::from($file);
        $size = $upload->size();
        if ($size === null) {
            $upload = Upload::bytes($upload->contents(), $upload->fileName($fileName));
            $size = $upload->size();
        }
        $up = $this->create(['name' => $upload->fileName($fileName), 'bytes' => $size, 'mime' => $mime]);
        $parts = $up['parts'] ?? [];
        $etags = [];
        $sent = 0;
        $buffer = '';
        $chunks = $upload->chunks(1 << 20);
        foreach ($parts as $part) {
            while (strlen($buffer) < $part['bytes'] && $chunks->valid()) {
                $buffer .= $chunks->current();
                $chunks->next();
            }
            $body = substr($buffer, 0, $part['bytes']);
            $buffer = (string) substr($buffer, $part['bytes']);
            $etags[] = ['number' => $part['number'], 'etag' => $this->put($part['url'], $body, (int) $part['number'])];
            $sent += strlen($body);
            if ($onProgress !== null) {
                $onProgress($sent, $size);
            }
        }
        return $this->complete($up['id'], ['parts' => $etags]);
    }

    private function put(string $url, string $body, int $number): string
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $res = $this->t->http->send('PUT', $url, [], $body);
            } catch (ConnectionException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
                continue;
            }
            if ($res->status < 300) {
                $etag = $res->header('etag');
                if ($etag === null || $etag === '') {
                    throw new VocalStackException('unavailable', "part {$number} was sent but storage gave no ETag", status: $res->status);
                }
                return $etag;
            }
            if ($res->status < 500 || $attempt >= 2) {
                throw new VocalStackException('invalid_state', "storage refused part {$number} ({$res->status})", status: $res->status);
            }
        }
    }
}
