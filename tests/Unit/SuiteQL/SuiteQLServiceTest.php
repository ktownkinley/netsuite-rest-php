<?php

namespace NetSuiteRest\Tests\Unit\SuiteQL;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use NetSuiteRest\Config\NetSuiteConfig;
use NetSuiteRest\Exception\ApiException;
use NetSuiteRest\Http\NetSuiteRestClient;
use NetSuiteRest\SuiteQL\SuiteQLService;
use NetSuiteRest\Tests\Unit\Http\FakeAccessTokenProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class SuiteQLServiceTest extends TestCase
{
    /**
     * @param array<int, Response> $responses
     * @param array<int, array{request: RequestInterface}> $history Populated with each transaction as it happens.
     */
    private function newService(array $responses, array &$history): SuiteQLService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $client = new NetSuiteRestClient(
            $this->makeConfig(),
            new FakeAccessTokenProvider(),
            new Client(['handler' => $stack]),
        );

        return new SuiteQLService($client);
    }

    private function makeConfig(): NetSuiteConfig
    {
        return new NetSuiteConfig(
            clientId: 'client-id',
            certificateId: 'certificate-id',
            privateKey: '-----BEGIN PRIVATE KEY-----FAKE-----END PRIVATE KEY-----',
            restApiBaseUrl: 'https://1234567-sb1.suitetalk.api.netsuite.com',
            scopes: ['rest_webservices'],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private static function page(array $items, int $offset, int $totalResults, bool $hasMore): string
    {
        return json_encode([
            'links' => [],
            'count' => count($items),
            'offset' => $offset,
            'totalResults' => $totalResults,
            'hasMore' => $hasMore,
            'items' => $items,
        ]);
    }

    public function testQueryPageSendsTheExpectedRequest(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(200, [], self::page([['id' => '1']], 0, 1, false)),
        ], $history);

        $service->queryPage('SELECT id FROM customer');

        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://1234567-sb1.suitetalk.api.netsuite.com/services/rest/query/v1/suiteql',
            (string) $request->getUri()->withQuery(''),
        );
        self::assertSame('limit=1000&offset=0', $request->getUri()->getQuery());
        self::assertSame('transient', $request->getHeaderLine('Prefer'));
        self::assertSame(['q' => 'SELECT id FROM customer'], json_decode((string) $request->getBody(), true));
    }

    public function testQueryPageIncludesBoundParamsWhenGiven(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(200, [], self::page([], 0, 0, false)),
        ], $history);

        $service->queryPage('SELECT id FROM item WHERE id BETWEEN ? AND ?', ['-7', '0']);

        self::assertSame(
            ['q' => 'SELECT id FROM item WHERE id BETWEEN ? AND ?', 'params' => ['-7', '0']],
            json_decode((string) $history[0]['request']->getBody(), true),
        );
    }

    public function testQueryPageOmitsParamsFromTheBodyWhenNoneAreGiven(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(200, [], self::page([], 0, 0, false)),
        ], $history);

        $service->queryPage('SELECT id FROM customer');

        self::assertArrayNotHasKey('params', json_decode((string) $history[0]['request']->getBody(), true));
    }

    public function testQueryPageReturnsTheDecodedPage(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(200, [], self::page([['id' => '1'], ['id' => '2']], 0, 2, false)),
        ], $history);

        $page = $service->queryPage('SELECT id FROM customer');

        self::assertSame(2, $page['count']);
        self::assertSame(2, $page['totalResults']);
        self::assertFalse($page['hasMore']);
        self::assertSame([['id' => '1'], ['id' => '2']], $page['items']);
    }

    public function testQueryPageSendsTheRequestedLimitAndOffset(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(200, [], self::page([], 50, 200, true)),
        ], $history);

        $service->queryPage('SELECT id FROM customer', [], 50, 50);

        self::assertSame('limit=50&offset=50', $history[0]['request']->getUri()->getQuery());
    }

    public function testQueryPageThrowsForANonPositiveLimit(): void
    {
        $history = [];
        $service = $this->newService([], $history);

        $this->expectException(InvalidArgumentException::class);

        $service->queryPage('SELECT id FROM customer', [], 0);
    }

    public function testQueryPageThrowsForANegativeOffset(): void
    {
        $history = [];
        $service = $this->newService([], $history);

        $this->expectException(InvalidArgumentException::class);

        $service->queryPage('SELECT id FROM customer', [], 1000, -1);
    }

    public function testQueryYieldsRowsFromASinglePageWithoutAdditionalRequests(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(200, [], self::page([['id' => '1'], ['id' => '2']], 0, 2, false)),
        ], $history);

        $rows = iterator_to_array($service->query('SELECT id FROM customer'));

        self::assertSame([['id' => '1'], ['id' => '2']], $rows);
        self::assertCount(1, $history);
    }

    public function testQueryTransparentlyFollowsMultiplePages(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(200, [], self::page([['id' => '1'], ['id' => '2']], 0, 3, true)),
            new Response(200, [], self::page([['id' => '3']], 2, 3, false)),
        ], $history);

        // iterator_to_array() with its default key-preserving behavior would silently drop
        // rows if the generator re-used array keys per page — this proves it doesn't.
        $rows = iterator_to_array($service->query('SELECT id FROM customer', [], 2));

        self::assertSame([['id' => '1'], ['id' => '2'], ['id' => '3']], $rows);
        self::assertCount(2, $history);
        self::assertSame('limit=2&offset=0', $history[0]['request']->getUri()->getQuery());
        self::assertSame('limit=2&offset=2', $history[1]['request']->getUri()->getQuery());
    }

    public function testQueryAdvancesTheOffsetByThePageSizeNotTheReturnedCount(): void
    {
        $history = [];
        $service = $this->newService([
            // NetSuite can return fewer rows than the requested limit on an intermediate
            // page (e.g. permission-based row filtering) while still reporting hasMore=true.
            // The next request must still land on an offset that's a multiple of the page
            // size, per NetSuite's collection-paging contract, so it must advance by the
            // requested page size rather than by this page's actual item count.
            new Response(200, [], self::page([['id' => '1'], ['id' => '2']], 0, 5, true)),
            new Response(200, [], self::page([['id' => '3'], ['id' => '4'], ['id' => '5']], 3, 5, false)),
        ], $history);

        $rows = iterator_to_array($service->query('SELECT id FROM customer', [], 3));

        self::assertSame(
            [['id' => '1'], ['id' => '2'], ['id' => '3'], ['id' => '4'], ['id' => '5']],
            $rows,
        );
        self::assertSame('limit=3&offset=0', $history[0]['request']->getUri()->getQuery());
        self::assertSame('limit=3&offset=3', $history[1]['request']->getUri()->getQuery());
    }

    public function testQueryDoesNotSendAnyRequestUntilIterated(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(200, [], self::page([['id' => '1']], 0, 1, false)),
        ], $history);

        $result = $service->query('SELECT id FROM customer');

        self::assertCount(0, $history);

        iterator_to_array($result);

        self::assertCount(1, $history);
    }

    public function testQueryPreservesRowDataExactlyAsReturned(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(200, [], self::page([['id' => '143', 'companyname' => 'Acme', 'links' => []]], 0, 1, false)),
        ], $history);

        $rows = iterator_to_array($service->query('SELECT id, companyname FROM customer'));

        self::assertSame([['id' => '143', 'companyname' => 'Acme', 'links' => []]], $rows);
    }

    public function testSuiteQlErrorSurfacesAsApiExceptionWithParsedDetails(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(400, [], json_encode([
                'type' => 'https://example.test/errors/invalid-suiteql',
                'title' => 'Invalid Search Query',
                'o:errorDetails' => [
                    ['detail' => 'Search error occurred: Field not found', 'o:errorCode' => 'INVALID_SUITEQL_QUERY'],
                ],
            ])),
        ], $history);

        try {
            iterator_to_array($service->query('SELECT nonexistent_field FROM customer'));
            self::fail('Expected ApiException was not thrown.');
        } catch (ApiException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('INVALID_SUITEQL_QUERY', $e->getErrorDetails()[0]['o:errorCode']);
        }
    }
}
