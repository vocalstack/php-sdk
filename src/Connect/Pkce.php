<?php

declare(strict_types=1);

namespace VocalStack\Connect;

final class Pkce
{
    public static function verifier(int $bytes = 48): string
    {
        if ($bytes < 32 || $bytes > 96) {
            throw new \InvalidArgumentException('a PKCE verifier takes 32 to 96 random bytes (43 to 128 characters)');
        }
        return self::base64url(random_bytes($bytes));
    }

    public static function challenge(string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    public const METHOD = 'S256';

    public static function state(int $bytes = 24): string
    {
        return self::base64url(random_bytes($bytes));
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
