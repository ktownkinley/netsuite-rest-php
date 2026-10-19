<?php

namespace NetSuiteRest\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NetSuiteRest\Config\NetSuiteConfig;
use NetSuiteRest\Http\NetSuiteRestClient;
use NetSuiteRest\NetSuite;
use NetSuiteRest\Record\RecordService;
use NetSuiteRest\SuiteQL\SuiteQLService;
use NetSuiteRest\Tests\Unit\Http\FakeAccessTokenProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class NetSuiteTest extends TestCase
{
    private static string $realPrivateKeyPem;

    /**
     * Only one test here (testDefaultTokenProviderIsBuiltAgainstTheSuppliedHttpClient) needs a
     * real key — everything else either does no signing (plain construction/type checks) or
     * supplies its own token provider. Generated once for the class since it's slow, matching
     * OAuth2ClientCredentialsProviderTest's existing pattern.
     */
    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, 'Failed to generate a throwaway RSA key for testing.');

        $pem = '';
        openssl_pkey_export($key, $pem);
        self::$realPrivateKeyPem = $pem;
    }

    private function makeConfig(?string $privateKey = null): NetSuiteConfig
    {
        return new NetSuiteConfig(
            clientId: 'client-id',
            certificateId: 'certificate-id',
            privateKey: $privateKey ?? '-----BEGIN PRIVATE KEY-----FAKE-----END PRIVATE KEY-----',
            restApiBaseUrl: 'https://1234567-sb1.suitetalk.api.netsuite.com',
            scopes: ['rest_webservices'],
        );
    }

    public function testConstructsSuccessfullyWithOnlyConfig(): void
    {
        // No network call happens merely by constructing this — token acquisition is lazy,
        // deferred to OAuth2ClientCredentialsProvider's first real request — so the fake,
        // non-signable placeholder key is fine here.
        $netsuite = new NetSuite($this->makeConfig());

        self::assertInstanceOf(NetSuite::class, $netsuite);
    }

    public function testSuiteQLReturnsASuiteQLServiceInstance(): void
    {
        $netsuite = new NetSuite($this->makeConfig());

        self::assertInstanceOf(SuiteQLService::class, $netsuite->suiteQL());
    }

    public function testRecordsReturnsARecordServiceInstance(): void
    {
        $netsuite = new NetSuite($this->makeConfig());

        self::assertInstanceOf(RecordService::class, $netsuite->records());
    }

    public function testClientReturnsTheSameNetSuiteRestClientInstanceEachCall(): void
    {
        $netsuite = new NetSuite($this->makeConfig());

        self::assertInstanceOf(NetSuiteRestClient::class, $netsuite->client());
        self::assertSame($netsuite->client(), $netsuite->client());
    }

    /**
     * Guzzle's own defaults allow a request to hang indefinitely, so the default client this
     * class builds internally must carry bounded timeouts. Reaches through NetSuiteRestClient's
     * own private $httpClient via reflection since neither class exposes it publicly — there's
     * no other way to observe which Guzzle client ended up wired in.
     */
    public function testDefaultHttpClientHasBoundedConnectAndRequestTimeouts(): void
    {
        $netsuite = new NetSuite($this->makeConfig());

        $httpClient = self::innerHttpClient($netsuite);

        self::assertInstanceOf(Client::class, $httpClient);
        self::assertSame(15, $httpClient->getConfig('connect_timeout'));
        self::assertSame(120, $httpClient->getConfig('timeout'));
    }

    /**
     * The whole point of the $httpClient constructor parameter being an escape hatch is that a
     * consumer's own timeout policy (or deliberate lack of one) is never second-guessed.
     */
    public function testSuppliedHttpClientsTimeoutsAreNotOverridden(): void
    {
        $customClient = new Client(['connect_timeout' => 3, 'timeout' => 7]);

        $netsuite = new NetSuite($this->makeConfig(), $customClient);

        $httpClient = self::innerHttpClient($netsuite);

        self::assertSame($customClient, $httpClient);
        self::assertSame(3, $httpClient->getConfig('connect_timeout'));
        self::assertSame(7, $httpClient->getConfig('timeout'));
    }

    private static function innerHttpClient(NetSuite $netsuite): mixed
    {
        $clientProperty = new \ReflectionProperty($netsuite->client(), 'httpClient');
        $clientProperty->setAccessible(true);

        return $clientProperty->getValue($netsuite->client());
    }

    /**
     * The whole point of building the object graph once is that OAuth2ClientCredentialsProvider's
     * token cache is shared — a fresh provider per capability would silently defeat it. Proven
     * here by seeding two *distinct* tokens into one shared provider: if suiteQL() and records()
     * each had their own client/provider, both calls would independently return the first token
     * instead of advancing through the sequence.
     */
    public function testSuiteQLAndRecordsShareTheSameUnderlyingClientAndTokenProvider(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'links' => [], 'count' => 0, 'offset' => 0, 'totalResults' => 0, 'hasMore' => false, 'items' => [],
            ])),
            new Response(200, [], '{"id":"1"}'),
        ]));
        $stack->push(Middleware::history($history));

        $netsuite = new NetSuite(
            $this->makeConfig(),
            new Client(['handler' => $stack]),
            new FakeAccessTokenProvider(['first-token', 'second-token']),
        );

        $netsuite->suiteQL()->queryPage('SELECT id FROM customer');
        $netsuite->records()->get('customer', 1);

        self::assertCount(2, $history);
        self::assertSame('Bearer first-token', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('Bearer second-token', $history[1]['request']->getHeaderLine('Authorization'));
    }

    /**
     * When no custom token provider is supplied, the default OAuth2ClientCredentialsProvider
     * must still be built against the *supplied* custom HTTP client — not a second, separately
     * constructed default client. Proven by checking both the token-endpoint call and the
     * subsequent API call land in the same mock handler's history.
     */
    public function testDefaultTokenProviderIsBuiltAgainstTheSuppliedHttpClient(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'real-token', 'expires_in' => 3600])),
            new Response(200, [], '{"id":"1"}'),
        ]));
        $stack->push(Middleware::history($history));

        $netsuite = new NetSuite(
            $this->makeConfig(self::$realPrivateKeyPem),
            new Client(['handler' => $stack]),
        );

        $netsuite->records()->get('customer', 1);

        self::assertCount(2, $history);
        /** @var RequestInterface $tokenRequest */
        $tokenRequest = $history[0]['request'];
        self::assertStringContainsString('/auth/oauth2/v1/token', (string) $tokenRequest->getUri());
        self::assertSame('Bearer real-token', $history[1]['request']->getHeaderLine('Authorization'));
    }
}
