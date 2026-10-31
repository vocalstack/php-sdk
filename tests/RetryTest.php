<?php

declare(strict_types=1);

namespace VocalStack\Tests;

use PHPUnit\Framework\TestCase;
use VocalStack\Http\Transport;
use VocalStack\RealtimeSession;
use VocalStack\Tests\Support\FakeHttpClient;
use VocalStack\VocalStack;
use VocalStack\VocalStackException;

final class RetryTest extends TestCase
{
    private static function envelope(string $code, int $status, bool $retryable): array
    {
        return ['defined' => true, 'code' => $code, 'status' => $status, 'message' => $code, 'data' => ['retryable' => $retryable]];
    }

    private static function unavailable(): array
    {
        return [503, self::envelope('unavailable', 503, true), ['retry-after' => '0']];
    }

    private static function client(array $answers, int $maxRetries = 2): array
    {
        $http = new FakeHttpClient($answers);
        return [new VocalStack(apiKey: 'vs_key_x', baseUrl: 'http://api.test', maxRetries: $maxRetries, httpClient: $http), $http];
    }

    public function testAGetAnswered503UnavailableIsRetried(): void
    {
        [$vs, $http] = self::client([self::unavailable(), [200, ['status' => 'ok']]]);
        $this->assertSame('ok', $vs->meta->health()['status']);
        $this->assertCount(2, $http->calls);
    }

    public function testTheRetriesAreBoundedThenTheErrorSurfaces(): void
    {
        [$vs, $http] = self::client([self::unavailable()], 2);
        try {
            $vs->meta->health();
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('unavailable', $e->errorCode);
            $this->assertSame(503, $e->status);
            $this->assertTrue($e->retryable);
        }
        $this->assertCount(3, $http->calls);
    }

    public function testAPostWithoutAnIdempotencyKeyIsNotRetried(): void
    {
        [$vs, $http] = self::client([self::unavailable(), [201, ['id' => 'b1']]]);
        try {
            $vs->broadcasts->create(['name' => 'x']);
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('unavailable', $e->errorCode);
        }
        $this->assertCount(1, $http->calls);
    }

    public function testAPostWithAnIdempotencyKeyIsRetriedWithTheSameKey(): void
    {
        [$vs, $http] = self::client([self::unavailable(), [201, ['id' => 'b1', 'code' => 'x', 'name' => 'x']]]);
        $vs->broadcasts->create(['name' => 'x'], idempotencyKey: 'k1');
        $this->assertCount(2, $http->calls);
        $this->assertSame('k1', $http->calls[1]['headers']['idempotency-key']);
    }

    public function testWhichAnswersAreRetried(): void
    {
        $this->assertTrue(Transport::retries(503, 'unavailable', 'DELETE'));
        $this->assertTrue(Transport::retries(503, 'unavailable', 'HEAD'));
        $this->assertTrue(Transport::retries(503, 'unavailable', 'POST', 'k1'));
        $this->assertFalse(Transport::retries(503, 'unavailable', 'PATCH'));
        $this->assertFalse(Transport::retries(503, 'capacity_exceeded', 'GET'));
        $this->assertFalse(Transport::retries(500, 'internal', 'GET'));
        $this->assertTrue(Transport::retries(429, 'rate_limited', 'POST'));
    }

    public function testA429IsRetriedAfterItsRetryAfterButNotPastSixtySeconds(): void
    {
        $limited = self::envelope('rate_limited', 429, true);
        [$vs, $http] = self::client([[429, $limited, ['retry-after' => '0']], [200, ['status' => 'ok']]]);
        $this->assertSame('ok', $vs->meta->health()['status']);
        $this->assertCount(2, $http->calls);

        [$vs, $http] = self::client([[429, $limited, ['retry-after' => '61']], [200, ['status' => 'ok']]]);
        try {
            $vs->meta->health();
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('rate_limited', $e->errorCode);
            $this->assertSame(61.0, $e->retryAfter());
        }
        $this->assertCount(1, $http->calls);

        [$vs, $http] = self::client([[429, $limited]]);
        $this->expectException(VocalStackException::class);
        try {
            $vs->meta->health();
        } finally {
            $this->assertCount(1, $http->calls, 'no Retry-After: not retried');
        }
    }

    public function testErrorsCarryDetailsHeadersAndTheRequestId(): void
    {
        $body = ['code' => 'insufficient_credit', 'message' => 'out of credit', 'requestId' => 'req-1',
            'data' => ['retryable' => false, 'details' => ['balance' => 0, 'plan' => 'free']]];
        [$vs] = self::client([[402, $body, ['x-request-id' => 'req-header']]]);
        try {
            $vs->billing->get();
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('insufficient_credit', $e->errorCode);
            $this->assertSame(402, $e->status());
            $this->assertSame(['balance' => 0, 'plan' => 'free'], $e->details);
            $this->assertSame('req-1', $e->requestId());
            $this->assertSame('req-header', $e->headers['x-request-id']);
        }
        [$vs] = self::client([[500, '<html>', ['x-request-id' => 'req-2']]]);
        try {
            $vs->billing->get();
            $this->fail('no exception');
        } catch (VocalStackException $e) {
            $this->assertSame('internal', $e->errorCode);
            $this->assertSame('unexpected error (status 500)', $e->getMessage());
            $this->assertSame('req-2', $e->requestId);
        }
    }

    public function testA503WithAnUnavailableBodyIsUnavailable(): void
    {
        $e = RealtimeSession::upgradeRefused(503, json_encode(self::envelope('unavailable', 503, true)));
        $this->assertSame('unavailable', $e->errorCode);
        $this->assertTrue($e->retryable);
    }

    public function testABare503StaysCapacityExceeded(): void
    {
        $this->assertSame('capacity_exceeded', RealtimeSession::upgradeRefused(503, '')->errorCode);
        $this->assertSame('capacity_exceeded', RealtimeSession::upgradeRefused(503, null)->errorCode);
        $this->assertSame('capacity_exceeded', RealtimeSession::upgradeRefused(503, '<html>busy</html>')->errorCode);
        $this->assertSame('capacity_exceeded', RealtimeSession::upgradeRefused(503, json_encode(self::envelope('internal', 503, false)))->errorCode);
    }

    public function testABare502Or504IsCapacityExceeded(): void
    {
        foreach ([502, 504] as $status) {
            $e = RealtimeSession::upgradeRefused($status, '');
            $this->assertSame('capacity_exceeded', $e->errorCode);
            $this->assertTrue($e->retryable);
            $this->assertSame('capacity_exceeded', RealtimeSession::upgradeRefused($status, '<html>bad gateway</html>')->errorCode);
        }
        $this->assertSame('internal', RealtimeSession::upgradeRefused(502, json_encode(self::envelope('internal', 502, false)))->errorCode);
    }

    public function testOtherStatusesAreTheirOwnEnvelope(): void
    {
        $e = RealtimeSession::upgradeRefused(410, json_encode(self::envelope('session_expired', 410, false)));
        $this->assertSame('session_expired', $e->errorCode);
    }
}
