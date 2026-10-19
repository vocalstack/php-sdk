<?php

declare(strict_types=1);

namespace VocalStack\Tests;

use PHPUnit\Framework\TestCase;
use VocalStack\Tests\Support\FakeHttpClient;
use VocalStack\VocalStack;
use VocalStack\VocalStackException;

/** `$vs->connect->token()` against FakeHttpClient (no network). */
final class ConnectTest extends TestCase
{
    private const ISSUED = [
        'access_token' => 'issued-key',
        'token_type' => 'api_key',
        'key_id' => '00000000-0000-4000-8000-000000000001',
        'scope' => 'read write',
        'account' => ['name' => 'Acme'],
    ];

    private static function args(): array
    {
        return ['code' => 'c0de', 'codeVerifier' => str_repeat('v', 43), 'redirectUri' => 'https://app.test/cb?x=1'];
    }

    private static function client(array $answers, ?string $apiKey = null): array
    {
        $http = new FakeHttpClient($answers);
        return [new VocalStack(apiKey: $apiKey, baseUrl: 'http://api.test', httpClient: $http), $http];
    }

    public function testPostsAFormWithNoCredentialEvenWhenTheClientHasOne(): void
    {
        [$vs, $http] = self::client([[200, self::ISSUED]], 'configured-key');
        $this->assertSame(self::ISSUED, $vs->connect->token(...self::args()));
        $this->assertCount(1, $http->calls);
        $req = $http->calls[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame('http://api.test/v1/connect/token', $req['url']);
        $this->assertStringStartsWith('application/x-www-form-urlencoded', $req['headers']['content-type']);
        $this->assertArrayNotHasKey('authorization', $req['headers']);
        $this->assertArrayNotHasKey('origin', $req['headers']);
        $this->assertArrayNotHasKey('sec-fetch-site', $req['headers']);
        parse_str($req['content'], $form);
        $this->assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'c0de',
            'code_verifier' => str_repeat('v', 43),
            'redirect_uri' => 'https://app.test/cb?x=1',
        ], $form);
    }

    public function testAClientWithNoCredentialCanRedeemACode(): void
    {
        [$vs, $http] = self::client([[200, self::ISSUED]]);
        $this->assertSame(self::ISSUED['key_id'], $vs->connect->token(...self::args())['key_id']);
        $this->assertArrayNotHasKey('authorization', $http->calls[0]['headers']);
    }

    public function testA400IsAVocalStackErrorWithTheOAuthCodeNotRetried(): void
    {
        $refused = ['error' => 'invalid_grant', 'error_description' => 'the code was already used'];
        [$vs, $http] = self::client([[400, $refused], [200, self::ISSUED]]);
        try {
            $vs->connect->token(...self::args());
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('invalid_grant', $e->errorCode);
            $this->assertSame('invalid_grant', $e->errorCode());
            $this->assertSame('the code was already used', $e->getMessage());
            $this->assertSame(400, $e->status);
            $this->assertFalse($e->retryable);
        }
        $this->assertCount(1, $http->calls);
    }

    public function testA429IsNeverRetriedEither(): void
    {
        $limited = ['defined' => true, 'code' => 'rate_limited', 'status' => 429, 'message' => 'slow down', 'data' => ['retryable' => true]];
        [$vs, $http] = self::client([[429, $limited, ['retry-after' => '0']], [200, self::ISSUED]]);
        try {
            $vs->connect->token(...self::args());
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('rate_limited', $e->errorCode);
        }
        $this->assertCount(1, $http->calls);
    }
}
