<?php

declare(strict_types=1);

namespace VocalStack\Resources;

use VocalStack\Http\MultipartBody;
use VocalStack\Http\MultipartPart;
use VocalStack\Http\Transport;
use VocalStack\Upload;

final class SpeakerInvites extends Group
{
    public function get(string $token): array
    {
        return $this->t->json('GET', '/v1/speaker-invites/' . self::p($token), anonymous: true);
    }

    public function submit(string $token, mixed $sample, bool $consent, ?string $email = null, ?string $fileName = null): array
    {
        $meta = self::body(['consent' => $consent, 'email' => $email]);
        $upload = Upload::from($sample);
        $parts = [
            MultipartPart::field('meta', Transport::encodeJson($meta), 'application/json'),
            MultipartPart::file('sample', $upload, $upload->fileName($fileName, 'sample.webm')),
        ];
        return $this->t->json('POST', '/v1/speaker-invites/' . self::p($token), multipart: new MultipartBody($parts), anonymous: true);
    }

    public function withdrawal(string $token): array
    {
        return $this->t->json('GET', '/v1/speaker-invites/withdraw/' . self::p($token), anonymous: true);
    }

    public function withdraw(string $token): array
    {
        return $this->t->json('POST', '/v1/speaker-invites/withdraw/' . self::p($token), anonymous: true);
    }
}
