<?php

namespace NetSuiteRest;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use NetSuiteRest\Auth\AccessTokenProviderInterface;
use NetSuiteRest\Auth\OAuth2ClientCredentialsProvider;
use NetSuiteRest\Config\NetSuiteConfig;
use NetSuiteRest\Http\NetSuiteRestClient;
use NetSuiteRest\Record\RecordService;
use NetSuiteRest\SuiteQL\SuiteQLService;

/**
 * The package's consumer-facing entry point: wires configuration, OAuth 2.0 authentication,
 * and HTTP transport into ready-to-use NetSuite API capabilities.
 *
 *     $netsuite = new NetSuite($config);
 *     $netsuite->suiteQL()->query('SELECT id FROM customer');
 *     $netsuite->records()->get('customer', 42);
 *
 * This is pure composition over the package's existing lower-level classes — it owns none of
 * their behavior (pagination, retry, error translation, token caching, Record API semantics
 * all stay exactly where they already live) and exposes NetSuite API capabilities
 * (`suiteQL()`, `records()`), not NetSuite business objects. A future capability only belongs
 * here once a real service exists for it, the same way `SuiteQLService`/`RecordService` do
 * today.
 *
 * The object graph is built once, here, and reused for the lifetime of this instance —
 * {@see OAuth2ClientCredentialsProvider}'s token cache is only useful if every capability
 * shares the same instance of it rather than each acquiring its own.
 *
 * `$httpClient`/`$tokenProvider` are the deliberate, and only, customization points: supply a
 * custom Guzzle client (logging, proxy, custom middleware) and/or a custom token provider
 * (e.g. one backed by a shared cache) without losing access to `suiteQL()`/`records()`.
 * Anything this class doesn't expose remains reachable via {@see self::client()} or by
 * constructing the lower-level classes directly — see the README.
 */
final class NetSuite
{
    /** Seconds to wait for the TCP/TLS connection itself before giving up. */
    private const DEFAULT_CONNECT_TIMEOUT_SECONDS = 15;

    /**
     * Seconds to wait for one complete HTTP request/response (token or API). This bounds a
     * single request, not an entire `SuiteQLService::query()` iteration — each page fetched
     * while iterating is its own HTTP request, so a long-running multi-page query is never at
     * risk from this value; only a single stalled request is.
     */
    private const DEFAULT_TIMEOUT_SECONDS = 120;

    private readonly NetSuiteRestClient $client;

    private readonly SuiteQLService $suiteQL;

    private readonly RecordService $records;

    public function __construct(
        NetSuiteConfig $config,
        ?ClientInterface $httpClient = null,
        ?AccessTokenProviderInterface $tokenProvider = null,
    ) {
        // Guzzle's own defaults allow a request to hang indefinitely. These bounds apply only
        // to the client this class builds itself — an injected $httpClient's timeout policy
        // (or lack of one) is left completely untouched, same as every other aspect of it.
        $httpClient ??= new Client([
            'connect_timeout' => self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
        ]);
        $tokenProvider ??= new OAuth2ClientCredentialsProvider($config, $httpClient);

        $this->client = new NetSuiteRestClient($config, $tokenProvider, $httpClient);
        $this->suiteQL = new SuiteQLService($this->client);
        $this->records = new RecordService($this->client);
    }

    public function suiteQL(): SuiteQLService
    {
        return $this->suiteQL;
    }

    public function records(): RecordService
    {
        return $this->records;
    }

    /**
     * Escape hatch to the underlying transport, for anything `suiteQL()`/`records()` don't
     * model (e.g. a Record API option deliberately kept out of `RecordService` — see the
     * README). Reuses the same authenticated client this instance already built, rather than
     * requiring a second one to be constructed by hand.
     */
    public function client(): NetSuiteRestClient
    {
        return $this->client;
    }
}
