<?php

declare(strict_types=1);

namespace VocalStack\Connect;

/**
 * PKCE for one-click Connect (RFC 7636, S256): make a verifier, put its
 * challenge in the dashboard's `/connect` link, keep the verifier on the
 * server, and redeem the code with `$vs->connect->token($code, $verifier, $redirectUri)`.
 */
final class Pkce
{
    /**
     * A fresh verifier: `$bytes` random bytes (32 or more), base64url
     * without padding (64 characters of `A-Z a-z 0-9 - _` by default).
     */
    public static function verifier(int $bytes = 48): string
    {
        if ($bytes < 32 || $bytes > 96) {
            throw new \InvalidArgumentException('a PKCE verifier takes 32 to 96 random bytes (43 to 128 characters)');
        }
        return self::base64url(random_bytes($bytes));
    }

    /** `BASE64URL(SHA256(verifier))`, without padding (43 characters): the link's `code_challenge`. */
    public static function challenge(string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    /** The link's `code_challenge_method`. */
    public const METHOD = 'S256';

    /** A random `state` for the link (to match the callback to its verifier). */
    public static function state(int $bytes = 24): string
    {
        return self::base64url(random_bytes($bytes));
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
