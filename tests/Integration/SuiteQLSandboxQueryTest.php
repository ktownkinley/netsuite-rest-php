<?php

namespace NetSuiteRest\Tests\Integration;

use GuzzleHttp\Client;
use NetSuiteRest\Auth\OAuth2ClientCredentialsProvider;
use NetSuiteRest\Http\NetSuiteRestClient;
use NetSuiteRest\SuiteQL\SuiteQLService;
use PHPUnit\Framework\TestCase;

/**
 * Exercises SuiteQLService against a real NetSuite sandbox account.
 *
 * Requires real credentials — see .env.example. Skipped automatically when they aren't present.
 *
 * A real sandbox account can hold a large amount of data, so every query here is
 * deliberately bounded: single-page checks use a small
 * REST `limit`, and the multi-page check bounds the underlying result set itself with a
 * `WHERE ROWNUM <= n` clause. Nothing in this suite walks an entire table.
 */
final class SuiteQLSandboxQueryTest extends TestCase
{
    use RequiresSandboxConfig;

    private function service(): SuiteQLService
    {
        $config = $this->sandboxConfig();
        $tokenProvider = new OAuth2ClientCredentialsProvider($config, new Client());
        $client = new NetSuiteRestClient($config, $tokenProvider, new Client());

        return new SuiteQLService($client);
    }

    public function testQueryPageReturnsRealCustomerRows(): void
    {
        $page = $this->service()->queryPage('SELECT id, entityid FROM customer', [], 5);

        self::assertLessThanOrEqual(5, $page['count']);
        self::assertIsInt($page['totalResults']);
        self::assertGreaterThan(0, $page['totalResults']);
        self::assertIsBool($page['hasMore']);
        self::assertNotEmpty($page['items']);
        self::assertArrayHasKey('id', $page['items'][0]);
    }

    public function testQuerySupportsBoundParameters(): void
    {
        $service = $this->service();

        $firstPage = $service->queryPage('SELECT id FROM customer', [], 1);
        self::assertNotEmpty($firstPage['items'], 'Sandbox account has no customer records to test against.');
        $knownId = $firstPage['items'][0]['id'];

        $page = $service->queryPage('SELECT id FROM customer WHERE id = ?', [$knownId]);

        self::assertSame(1, $page['count']);
        self::assertSame($knownId, $page['items'][0]['id']);
    }

    /**
     * Bounds the underlying result set itself with ROWNUM (not just the REST page size),
     * so this exercises real multi-page auto-pagination — verifying the offset-advancement
     * fix against NetSuite's actual pagination behavior — without pulling more than a
     * handful of the sandbox's real customer records.
     */
    public function testQueryTransparentlyPaginatesAcrossMultipleRealRequests(): void
    {
        $rows = iterator_to_array(
            $this->service()->query('SELECT id FROM customer WHERE ROWNUM <= 7', [], 3),
        );

        self::assertCount(7, $rows);

        $ids = array_column($rows, 'id');
        self::assertSame($ids, array_values(array_unique($ids)), 'Expected no duplicate rows across pages.');
    }
}
