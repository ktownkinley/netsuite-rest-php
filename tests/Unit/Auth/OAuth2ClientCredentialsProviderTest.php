<?php

namespace NetSuiteRest\Tests\Unit\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NetSuiteRest\Auth\OAuth2ClientCredentialsProvider;
use NetSuiteRest\Config\NetSuiteConfig;
use NetSuiteRest\Exception\AuthenticationException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;

final class OAuth2ClientCredentialsProviderTest extends TestCase
{
    private static string $privateKeyPem;

    /**
     * Generating an RSA key is slow, and no test here depends on it being unique per test —
     * it's just fixture data for signing, so one key is generated once for the whole class.
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
        self::$privateKeyPem = $pem;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeConfig(array $overrides = []): NetSuiteConfig
    {
        $args = array_merge([
            'clientId' => 'test-client-id',
            'certificateId' => 'test-certificate-id',
            'privateKey' => self::$privateKeyPem,
            'restApiBaseUrl' => 'https://1234567-sb1.suitetalk.api.netsuite.com',
            'scopes' => ['rest_webservices'],
        ], $overrides);

        return new NetSuiteConfig(...$args);
    }

    /**
     * @param Response[] $responses
     * @param array<int, array{request: RequestInterface}> $history Populated with each transaction as it happens.
     */
    private function newProvider(array $responses, array &$history, ?NetSuiteConfig $config = null): OAuth2ClientCredentialsProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new OAuth2ClientCredentialsProvider(
            $config ?? $this->makeConfig(),
            new Client(['handler' => $stack]),
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [header, payload]
     */
    private static function decodeAssertion(RequestInterface $request): array
    {
        parse_str((string) $request->getBody(), $formParams);
        $assertion = $formParams['client_assertion'];

        [$headerB64, $payloadB64] = explode('.', $assertion);

        return [
            json_decode(self::base64UrlDecode($headerB64), true),
            json_decode(self::base64UrlDecode($payloadB64), true),
        ];
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public function testReturnsTheAccessTokenFromTheTokenResponse(): void
    {
        $history = [];
        $provider = $this->newProvider([
            new Response(200, [], json_encode([
                'access_token' => 'token-abc',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ])),
        ], $history);

        self::assertSame('token-abc', $provider->getAccessToken());
    }

    public function testCachesTheTokenInsteadOfRequestingANewOneEachCall(): void
    {
        $history = [];
        $provider = $this->newProvider([
            new Response(200, [], json_encode(['access_token' => 'token-abc', 'expires_in' => 3600])),
        ], $history);

        $provider->getAccessToken();
        $provider->getAccessToken();

        self::assertCount(1, $history);
    }

    public function testRequestsANewTokenOnceTheCachedOneIsNearExpiry(): void
    {
        $history = [];
        $provider = $this->newProvider([
            new Response(200, [], json_encode(['access_token' => 'first', 'expires_in' => 3600])),
            new Response(200, [], json_encode(['access_token' => 'second', 'expires_in' => 3600])),
        ], $history);

        self::assertSame('first', $provider->getAccessToken());

        // Simulate the cached token having reached its safety-margin expiry, without sleeping in the test.
        $expiresAt = new ReflectionProperty($provider, 'accessTokenExpiresAt');
        $expiresAt->setAccessible(true);
        $expiresAt->setValue($provider, time());

        self::assertSame('second', $provider->getAccessToken());
        self::assertCount(2, $history);
    }

    public function testSendsTheRequestNetSuiteExpects(): void
    {
        $history = [];
        $provider = $this->newProvider([
            new Response(200, [], json_encode(['access_token' => 'token-abc', 'expires_in' => 3600])),
        ], $history);

        $provider->getAccessToken();

        /** @var RequestInterface $request */
        $request = $history[0]['request'];

        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://1234567-sb1.suitetalk.api.netsuite.com/services/rest/auth/oauth2/v1/token',
            (string) $request->getUri(),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

        parse_str((string) $request->getBody(), $formParams);
        self::assertSame('client_credentials', $formParams['grant_type']);
        self::assertSame(
            'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            $formParams['client_assertion_type'],
        );
        self::assertArrayHasKey('client_assertion', $formParams);
    }

    public function testClientAssertionCarriesTheClaimsAndHeadersNetSuiteRequires(): void
    {
        $history = [];
        $provider = $this->newProvider([
            new Response(200, [], json_encode(['access_token' => 'token-abc', 'expires_in' => 3600])),
        ], $history);

        $provider->getAccessToken();

        [$header, $payload] = self::decodeAssertion($history[0]['request']);

        self::assertSame('JWT', $header['typ']);
        self::assertSame('PS256', $header['alg']);
        self::assertSame('test-certificate-id', $header['kid']);

        self::assertSame('test-client-id', $payload['iss']);
        self::assertSame('rest_webservices', $payload['scope']);
        self::assertSame(
            'https://1234567-sb1.suitetalk.api.netsuite.com/services/rest/auth/oauth2/v1/token',
            $payload['aud'],
        );

        // Pinned to the exact intentional lifetime (well under NetSuite's 3600s ceiling), not just
        // "some short value" — this is a deliberately short window (see ext-gmp requirement in the
        // README) and a regression that quietly widens it to compensate for slow signing should
        // fail this test rather than pass silently.
        self::assertSame(60, $payload['exp'] - $payload['iat']);
    }

    public function testPayloadDoesNotEscapeForwardSlashes(): void
    {
        // PHP's json_encode() escapes "/" as "\/" by default. The aud claim is a URL, so without
        // JSON_UNESCAPED_SLASHES the payload would contain escaped slashes — still valid JSON, but
        // an unnecessary difference from what a hand-built assertion (or another JWT library) would
        // produce, and worth pinning down now that we've had to reason carefully about exact wire
        // content once already while debugging.
        $history = [];
        $provider = $this->newProvider([
            new Response(200, [], json_encode(['access_token' => 'token-abc', 'expires_in' => 3600])),
        ], $history);

        $provider->getAccessToken();

        parse_str((string) $history[0]['request']->getBody(), $formParams);
        [, $payloadB64] = explode('.', $formParams['client_assertion']);
        $rawPayloadJson = self::base64UrlDecode($payloadB64);

        self::assertStringNotContainsString('\\/', $rawPayloadJson);
    }

    public function testJoinsMultipleScopesWithCommas(): void
    {
        $history = [];
        $provider = $this->newProvider(
            [new Response(200, [], json_encode(['access_token' => 'token-abc', 'expires_in' => 3600]))],
            $history,
            $this->makeConfig(['scopes' => ['rest_webservices', 'restlets']]),
        );

        $provider->getAccessToken();

        [, $payload] = self::decodeAssertion($history[0]['request']);

        self::assertSame('rest_webservices,restlets', $payload['scope']);
    }

    public function testInvalidateForcesTheNextCallToRequestANewToken(): void
    {
        $history = [];
        $provider = $this->newProvider([
            new Response(200, [], json_encode(['access_token' => 'first', 'expires_in' => 3600])),
            new Response(200, [], json_encode(['access_token' => 'second', 'expires_in' => 3600])),
        ], $history);

        self::assertSame('first', $provider->getAccessToken());

        $provider->invalidate();

        self::assertSame('second', $provider->getAccessToken());
        self::assertCount(2, $history);
    }

    public function testInvalidateIsSafeToCallBeforeATokenHasEverBeenFetched(): void
    {
        $history = [];
        $provider = $this->newProvider([], $history);

        $provider->invalidate();

        self::assertCount(0, $history);
    }

    public function testThrowsAuthenticationExceptionOnAMalformedTokenResponse(): void
    {
        $history = [];
        $provider = $this->newProvider([
            new Response(200, [], json_encode(['unexpected' => 'shape'])),
        ], $history);

        $this->expectException(AuthenticationException::class);

        $provider->getAccessToken();
    }

    public function testThrowsAuthenticationExceptionOnATransportFailure(): void
    {
        $history = [];
        $provider = $this->newProvider([
            new ConnectException('Connection refused', new Request('POST', 'https://example.test')),
        ], $history);

        $this->expectException(AuthenticationException::class);

        $provider->getAccessToken();
    }
}
