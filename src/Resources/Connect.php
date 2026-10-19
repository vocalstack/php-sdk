<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/**
 * `$vs->connect`: one-click Connect, on the app's server. Make the link's
 * verifier and challenge with `VocalStack\Connect\Pkce`.
 */
final class Connect extends Group
{
    /**
     * Redeem the `code` the dashboard sent to the app's `redirectUri` for
     * an API key (OAuth 2.0 authorization code + PKCE): `codeVerifier` is
     * the verifier whose `BASE64URL(SHA256(verifier))` was the link's
     * `code_challenge`, `redirectUri` exactly the link's. Returns the
     * wire's `{ access_token, token_type, key_id, scope, account }`;
     * `access_token` is the key, shown once. No credential is sent, even
     * when the client has one, and the call is never retried: the code
     * works once. A refusal throws VocalStackException whose code is
     * OAuth's `error` (`invalid_grant`: an unknown, spent or expired code,
     * or a verifier or `redirectUri` that does not match).
     *
     * @return array<string, mixed>
     */
    public function token(string $code, string $codeVerifier, string $redirectUri): array
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
        ];
        return $this->t->json('POST', '/v1/connect/token', form: $form, anonymous: true, noRetry: true, oauthErrors: true);
    }
}
