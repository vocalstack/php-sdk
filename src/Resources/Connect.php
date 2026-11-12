<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Connect extends Group
{
    /**
     * @return array{access_token: string, token_type: string, key_id: string, scope: string, reach: string, account: array{name: string}, workspace: array{id: string, name: string, personal: bool, iconUrl: ?string}}
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
