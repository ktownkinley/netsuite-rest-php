<?php

namespace NetSuiteRest\SuiteQL;

use InvalidArgumentException;
use NetSuiteRest\Http\NetSuiteRestClient;

/**
 * Executes SuiteQL queries against NetSuite's REST query API.
 *
 * This knows SuiteQL's specific contract — the `q`/`params` request shape, the required
 * `Prefer: transient` header, and NetSuite's `limit`/`offset`/`hasMore` pagination — but
 * nothing about the underlying HTTP transport, authentication, or any other NetSuite
 * domain (records, transactions, etc). That lives in {@see NetSuiteRestClient} below it
 * and other services beside it.
 */
final class SuiteQLService
{
    private const RESOURCE_PATH = 'query/v1/suiteql';

    private const DEFAULT_PAGE_SIZE = 1000;

    public function __construct(private readonly NetSuiteRestClient $client)
    {
    }

    /**
     * Executes a SuiteQL query and lazily yields every matching row, transparently
     * paging through NetSuite's REST pagination as needed. Rows are yielded exactly as
     * NetSuite returns them — no field renaming, no value coercion.
     *
     * Nothing is fetched until the returned generator is iterated, and each page is
     * fetched only when iteration reaches it, so memory stays bounded to one page at a
     * time regardless of how many rows the query matches.
     *
     * @param array<int, scalar> $params Bound values for `?` placeholders in $query.
     * @return iterable<array<string, mixed>>
     */
    public function query(string $query, array $params = [], int $pageSize = self::DEFAULT_PAGE_SIZE): iterable
    {
        $offset = 0;

        do {
            $page = $this->queryPage($query, $params, $pageSize, $offset);

            // A plain foreach + yield (rather than `yield from`) is deliberate: `yield from`
            // on an array preserves that array's own keys, which restart at 0 for every page
            // and would silently collide/overwrite if a caller collects the results with
            // iterator_to_array(). Yielding without a key lets the generator assign its own
            // ever-increasing keys across the whole result set instead.
            foreach ($page['items'] as $item) {
                yield $item;
            }

            // Advance by the requested page size, not the actual item count. NetSuite's
            // collection-paging contract requires offset to stay a multiple of limit, and
            // doesn't guarantee an intermediate page returns exactly $pageSize items (e.g.
            // permission-based row filtering could shrink one page below the requested size
            // while hasMore is still true) — advancing by count would desync from that
            // constraint the first time that happens.
            $offset += $pageSize;
        } while ($page['hasMore']);
    }

    /**
     * Executes a single page of a SuiteQL query, giving direct access to NetSuite's
     * pagination metadata (`totalResults`, `hasMore`, etc). Most callers want {@see query()}
     * instead; this exists for cases that need manual control over paging.
     *
     * @param array<int, scalar> $params Bound values for `?` placeholders in $query.
     * @return array{
     *     links: array<int, array<string, mixed>>,
     *     count: int,
     *     offset: int,
     *     totalResults: int,
     *     hasMore: bool,
     *     items: array<int, array<string, mixed>>,
     * }
     */
    public function queryPage(
        string $query,
        array $params = [],
        int $limit = self::DEFAULT_PAGE_SIZE,
        int $offset = 0,
    ): array {
        if ($limit < 1) {
            throw new InvalidArgumentException('SuiteQL page size (limit) must be at least 1.');
        }

        if ($offset < 0) {
            throw new InvalidArgumentException('SuiteQL offset must not be negative.');
        }

        $body = $params === [] ? ['q' => $query] : ['q' => $query, 'params' => $params];

        $response = $this->client->post(
            self::RESOURCE_PATH,
            $body,
            query: ['limit' => $limit, 'offset' => $offset],
            headers: ['Prefer' => 'transient'],
        );

        return $response->json();
    }
}
