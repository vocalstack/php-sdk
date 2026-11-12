<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Me extends Group
{
    /**
     * Who the credential is, and the workspace it works in.
     *
     * @return array{principalId: string, displayName: string, scopes: list<string>, reach: string, workspace: array{id: string, name: string, personal: bool, iconUrl: ?string}}
     */
    public function get(): array
    {
        return $this->t->json('GET', '/v1/me');
    }
}
