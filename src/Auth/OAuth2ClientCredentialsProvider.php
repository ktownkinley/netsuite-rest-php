<?php

namespace NetSuiteRest\Auth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use NetSuiteRest\Config\NetSuiteConfig;
use NetSuiteRest\Exception\AuthenticationException;

/**
 * Obtains and caches NetSuite OAuth 2.0 access tokens using the client credentials
 * (machine-to-machine) grant, authenticating with a signed JWT client assertion.
 */
final class OAuth2ClientCredentialsProvider implements AccessTokenProviderInterface
{
    private const TOKEN_ENDPOINT_PATH = '/services/rest/auth/oauth2/v1/token';

    /** Seconds subtracted from the token's reported lifetime before it's treated as stale. */
    private const EXPIRY_SAFETY_MARGIN_SECONDS = 60;

    /** Lifetime of the signed client assertion itself; must stay well under NetSuite's 60-minute ceiling. */
    private const ASSERTION_LIFETIME_SECONDS = 60;

    private ?string $accessToken = null;

    private ?int $accessTokenExpiresAt = null;

    public function __construct(
        private readonly NetSuiteConfig $config,
        private readonly ClientInterface $httpClient,
    ) {
    }

    public function getAccessToken(): string
    {
        if (!$this->hasValidCachedToken()) {
            $this->requestNewAccessToken();
        }

        return $this->accessToken;
    }

    public function invalidate(): void
    {
        $this->accessToken = null;
        $this->accessTokenExpiresAt = null;
    }

    private function hasValidCachedToken(): bool
    {
        return $this->accessToken !== null
            && $this->accessTokenExpiresAt !== null
            && time() < ($this->accessTokenExpiresAt - self::EXPIRY_SAFETY_MARGIN_SECONDS);
    }

    private function requestNewAccessToken(): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->tokenEndpoint(), [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                    'client_assertion' => $this->buildSignedClientAssertion(),
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new AuthenticationException(
                'Failed to request a NetSuite access token: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data) || !isset($data['access_token'], $data['expires_in'])) {
            throw new AuthenticationException('NetSuite token response did not contain the expected fields.');
        }

        $this->accessToken = $data['access_token'];
        $this->accessTokenExpiresAt = time() + (int) $data['expires_in'];
    }

    private function buildSignedClientAssertion(): string
    {
        $jwk = JWKFactory::createFromKey($this->config->getPrivateKey());
        $jwsBuilder = new JWSBuilder(new AlgorithmManager([new PS256()]));

        $now = time();

        $payload = json_encode([
            'iss' => $this->config->getClientId(),
            'scope' => implode(',', $this->config->getScopes()),
            'aud' => $this->tokenEndpoint(),
            'iat' => $now,
            'exp' => $now + self::ASSERTION_LIFETIME_SECONDS,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $jws = $jwsBuilder
            ->create()
            ->withPayload($payload)
            ->addSignature($jwk, [
                'typ' => 'JWT',
                'alg' => 'PS256',
                'kid' => $this->config->getCertificateId(),
            ])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    private function tokenEndpoint(): string
    {
        return rtrim($this->config->getRestApiBaseUrl(), '/') . self::TOKEN_ENDPOINT_PATH;
    }
}
