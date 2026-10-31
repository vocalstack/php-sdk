<?php

declare(strict_types=1);

namespace VocalStack\Tests;

use PHPUnit\Framework\TestCase;
use VocalStack\Tests\Support\FakeHttpClient;
use VocalStack\VocalStack;

final class PagingTest extends TestCase
{
    private static function client(array $pages): array
    {
        $http = new FakeHttpClient(array_map(static fn ($p) => [200, $p], $pages));
        return [new VocalStack(apiKey: 'vs_key_x', baseUrl: 'http://api.test', httpClient: $http), $http];
    }

    private static function tx(string $id): array
    {
        return [
            'id' => $id, 'code' => 'tx-1', 'name' => $id, 'kind' => 'transcription', 'source' => ['kind' => 'mic'],
            'lifecycle' => 'active', 'ownerPrincipalId' => 'p', 'shareUrl' => '', 'settings' => [], 'recordingCount' => 0,
            'createdAt' => '2026-10-12T00:00:00Z', 'updatedAt' => '2026-10-12T00:00:00Z',
        ];
    }

    public function testListFollowsNextCursorToTheLastPage(): void
    {
        [$vs, $http] = self::client([
            ['transcriptions' => [self::tx('t1'), self::tx('t2')], 'nextCursor' => 'c1'],
            ['transcriptions' => [self::tx('t3')]],
        ]);
        $got = $vs->transcriptions->list('all');
        $this->assertSame(['t1', 't2', 't3'], array_column($got, 'id'));
        $this->assertSame([null, 'c1'], [$http->query(0)['cursor'] ?? null, $http->query(1)['cursor'] ?? null]);
        $this->assertSame('all', $http->query(0)['lifecycle']);
        $this->assertSame('all', $http->query(1)['lifecycle']);
    }

    public function testListPageAsksForOnePage(): void
    {
        [$vs, $http] = self::client([['transcriptions' => [self::tx('t1')], 'nextCursor' => 'c9']]);
        $page = $vs->transcriptions->listPage(cursor: 'c8', limit: 100);
        $this->assertSame('c9', $page['nextCursor']);
        $this->assertCount(1, $http->calls);
        $this->assertSame(['c8', '100'], [$http->query(0)['cursor'], $http->query(0)['limit']]);
    }

    public function testEventsStartAfterASeqThenFollowTheCursor(): void
    {
        $ev = static fn (int $s) => ['seq' => $s, 'kind' => 'transcript.final', 'at' => '2026-10-12T00:00:00Z', 'payload' => []];
        [$vs, $http] = self::client([['events' => [$ev(4), $ev(5)], 'nextCursor' => '5'], ['events' => [$ev(6)]]]);
        $got = $vs->recordings->events('r1', after: 3, limit: 2);
        $this->assertSame([4, 5, 6], array_column($got, 'seq'));
        $this->assertSame('3', $http->query(0)['after']);
        $this->assertArrayNotHasKey('after', $http->query(1), 'never cursor and after together');
        $this->assertSame('5', $http->query(1)['cursor']);
    }
}
