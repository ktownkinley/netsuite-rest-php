<?php

namespace NetSuiteRest\Tests\Unit\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NetSuiteRest\Auth\AccessTokenProviderInterface;
use NetSuiteRest\Config\NetSuiteConfig;
use NetSuiteRest\Exception\AuthenticationException;
use NetSuiteRest\Exception\ApiException;
use NetSuiteRest\Exception\TransportException;
use NetSuiteRest\Exception\UnexpectedResponseException;
use NetSuiteRest\Http\NetSuiteRestClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class NetSuiteRestClientTest extends TestCase
{
    /**
     * @param array<int, Response|\Throwable> $responses
     * @param array<int, array{request: RequestInterface}> $history Populated with each transaction as it happens.
     */
    private function newClient(
        array $responses,
        array &$history,
        ?AccessTokenProviderInterface $tokenProvider = null,
    ): NetSuiteRestClient {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new NetSuiteRestClient(
            $this->makeConfig(),
            $tokenProvider ?? new FakeAccessTokenProvider(),
            new Client(['handler' => $stack]),
        );
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

    public function testGetBuildsTheRestUrlFromTheConfiguredBase(): void
    {
        $history = [];
        $client = $this->newClient([new Response(200, [], '{}')], $history);

        $client->get('record/v1/customer/123');

        self::assertSame(
            'https://1234567-sb1.suitetalk.api.netsuite.com/services/rest/record/v1/customer/123',
            (string) $history[0]['request']->getUri(),
        );
    }

    public function testGetTrimsRedundantSlashesBetweenBaseAndPath(): void
    {
        $history = [];
        $client = $this->newClient([new Response(200, [], '{}')], $history);

        $client->get('/record/v1/customer/123');

        self::assertSame(
            'https://1234567-sb1.suitetalk.api.netsuite.com/services/rest/record/v1/customer/123',
            (string) $history[0]['request']->getUri(),
        );
    }

    public function testGetSendsTheQueryString(): void
    {
        $history = [];
        $client = $this->newClient([new Response(200, [], '{}')], $history);

        $client->get('query/v1/suiteql', ['limit' => 10, 'offset' => 0]);

        self::assertSame('limit=10&offset=0', $history[0]['request']->getUri()->getQuery());
    }

    public function testSendsBearerAndAcceptHeadersByDefault(): void
    {
        $history = [];
        $client = $this->newClient(
            [new Response(200, [], '{}')],
            $history,
            new FakeAccessTokenProvider(['token-abc']),
        );

        $client->get('record/v1/customer/123');

        $request = $history[0]['request'];
        self::assertSame('Bearer token-abc', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testCustomHeadersAreSentAlongsideDefaults(): void
    {
        $history = [];
        $client = $this->newClient([new Response(200, [], '{}')], $history);

        $client->get('query/v1/suiteql', [], ['Prefer' => 'transient']);

        self::assertSame('transient', $history[0]['request']->getHeaderLine('Prefer'));
    }

    public function testCustomHeadersOverrideTheDefaultAcceptHeader(): void
    {
        $history = [];
        $client = $this->newClient([new Response(200, [], '{}')], $history);

        $client->get('record/v1/customer/123', [], ['Accept' => 'application/vnd.oracle.resource+json']);

        self::assertSame('application/vnd.oracle.resource+json', $history[0]['request']->getHeaderLine('Accept'));
    }

    public function testPostSendsAJsonEncodedBody(): void
    {
        $history = [];
        $client = $this->newClient([new Response(204)], $history);

        $client->post('record/v1/customer', ['companyName' => 'Acme']);

        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['companyName' => 'Acme'], json_decode((string) $request->getBody(), true));
    }

    public function testPostWithNoBodySendsNoBodyAndNoContentType(): void
    {
        $history = [];
        $client = $this->newClient([new Response(204)], $history);

        $client->post('record/v1/customer/123/!transform/salesOrder');

        $request = $history[0]['request'];
        self::assertSame('', (string) $request->getBody());
        self::assertSame('', $request->getHeaderLine('Content-Type'));
    }

    public function testPatchSendsAJsonEncodedBody(): void
    {
        $history = [];
        $client = $this->newClient([new Response(204)], $history);

        $client->patch('record/v1/customer/123', ['companyName' => 'Acme Updated']);

        $request = $history[0]['request'];
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame(['companyName' => 'Acme Updated'], json_decode((string) $request->getBody(), true));
    }

    public function testPutSendsAJsonEncodedBody(): void
    {
        $history = [];
        $client = $this->newClient([new Response(204)], $history);

        $client->put('record/v1/customer/eid:CID001', ['companyName' => 'Acme']);

        $request = $history[0]['request'];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame(['companyName' => 'Acme'], json_decode((string) $request->getBody(), true));
    }

    public function testDeleteSendsNoBody(): void
    {
        $history = [];
        $client = $this->newClient([new Response(204)], $history);

        $client->delete('record/v1/customer/123');

        $request = $history[0]['request'];
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
    }

    public function testPostSendsTheQueryString(): void
    {
        $history = [];
        $client = $this->newClient([new Response(200, [], '{}')], $history);

        $client->post('query/v1/suiteql', ['q' => 'SELECT id FROM customer'], query: ['limit' => 10, 'offset' => 0]);

        self::assertSame('limit=10&offset=0', $history[0]['request']->getUri()->getQuery());
    }

    public function testPatchSendsTheQueryStringForSublistReplace(): void
    {
        $history = [];
        $client = $this->newClient([new Response(204)], $history);

        $client->patch('record/v1/salesOrder/100', ['item' => []], query: ['replace' => 'item']);

        self::assertSame('replace=item', $history[0]['request']->getUri()->getQuery());
    }

    public function testDeleteSendsTheQueryString(): void
    {
        $history = [];
        $client = $this->newClient([new Response(204)], $history);

        $client->delete('record/v1/customer/123', ['transient' => 'true']);

        self::assertSame('transient=true', $history[0]['request']->getUri()->getQuery());
    }

    public function testReturnsANetSuiteResponseOnSuccess(): void
    {
        $history = [];
        $client = $this->newClient([new Response(200, [], json_encode(['id' => '123']))], $history);

        $response = $client->get('record/v1/customer/123');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => '123'], $response->json());
    }

    public function testDoesNotEagerlyDecodeTheBodyOnSuccess(): void
    {
        $history = [];
        $client = $this->newClient([new Response(200, [], '<html>not json</html>')], $history);

        $response = $client->get('record/v1/customer/123');

        self::assertSame(200, $response->getStatusCode());
        $this->expectException(UnexpectedResponseException::class);
        $response->json();
    }

    public function testThrowsApiExceptionOnAClientErrorStatus(): void
    {
        $history = [];
        $client = $this->newClient([new Response(404, [], json_encode([
            'type' => 'https://example.test/errors/not-found',
            'title' => 'Not Found',
        ]))], $history);

        try {
            $client->get('record/v1/customer/999');
            self::fail('Expected ApiException was not thrown.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getStatusCode());
            self::assertSame('Not Found', $e->getTitle());
        }

        self::assertCount(1, $history);
    }

    public function testThrowsApiExceptionOnAServerErrorStatus(): void
    {
        $history = [];
        $client = $this->newClient([new Response(500, [], 'Internal Server Error')], $history);

        $this->expectException(ApiException::class);

        $client->get('record/v1/customer/123');
    }

    public function testThrowsTransportExceptionWhenNoResponseIsReceived(): void
    {
        $history = [];
        $client = $this->newClient([
            new ConnectException('Connection refused', new Request('GET', 'https://example.test')),
        ], $history);

        try {
            $client->get('record/v1/customer/123');
            self::fail('Expected TransportException was not thrown.');
        } catch (TransportException $e) {
            self::assertInstanceOf(ConnectException::class, $e->getPrevious());
        }
    }

    public function test401RetriesExactlyOnceWithAFreshTokenAndSucceeds(): void
    {
        $history = [];
        $tokenProvider = new FakeAccessTokenProvider(['expired-token', 'fresh-token']);
        $client = $this->newClient([
            new Response(401),
            new Response(200, [], json_encode(['id' => '123'])),
        ], $history, $tokenProvider);

        $response = $client->get('record/v1/customer/123');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $history);
        self::assertSame('Bearer expired-token', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('Bearer fresh-token', $history[1]['request']->getHeaderLine('Authorization'));
        self::assertSame(1, $tokenProvider->invalidateCallCount);
    }

    public function test401OnTheReplayThrowsApiExceptionWithoutFurtherRetries(): void
    {
        $history = [];
        $tokenProvider = new FakeAccessTokenProvider(['expired-token', 'still-rejected-token']);
        $client = $this->newClient([
            new Response(401),
            new Response(401),
        ], $history, $tokenProvider);

        try {
            $client->get('record/v1/customer/123');
            self::fail('Expected ApiException was not thrown.');
        } catch (ApiException $e) {
            self::assertSame(401, $e->getStatusCode());
        }

        self::assertCount(2, $history);
        self::assertSame(1, $tokenProvider->invalidateCallCount);
    }

    public function testPropagatesAuthenticationExceptionWhenReacquiringTheTokenAfter401Fails(): void
    {
        $history = [];
        $tokenProvider = new class implements AccessTokenProviderInterface {
            private int $calls = 0;

            public int $invalidateCallCount = 0;

            public function getAccessToken(): string
            {
                $this->calls++;

                if ($this->calls === 1) {
                    return 'expired-token';
                }

                throw new AuthenticationException('NetSuite rejected the refreshed client assertion.');
            }

            public function invalidate(): void
            {
                $this->invalidateCallCount++;
            }
        };

        $client = $this->newClient([new Response(401)], $history, $tokenProvider);

        $this->expectException(AuthenticationException::class);

        try {
            $client->get('record/v1/customer/123');
        } finally {
            // Only the initial request should have been sent — reacquiring a token failed
            // before any replay could happen.
            self::assertCount(1, $history);
            self::assertSame(1, $tokenProvider->invalidateCallCount);
        }
    }
}
