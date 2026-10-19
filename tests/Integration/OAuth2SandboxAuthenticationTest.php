<?php

namespace NetSuiteRest\Tests\Integration;

use GuzzleHttp\Client;
use NetSuiteRest\Auth\OAuth2ClientCredentialsProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises OAuth2ClientCredentialsProvider against a real NetSuite sandbox account.
 *
 * Requires real credentials — see .env.example. Skipped automatically when they aren't present.
 */
final class OAuth2SandboxAuthenticationTest extends TestCase
{
    use RequiresSandboxConfig;

    public function testItObtainsARealAccessTokenFromNetSuite(): void
    {
        $config = $this->sandboxConfig();

        $provider = new OAuth2ClientCredentialsProvider($config, new Client());

        $token = $provider->getAccessToken();

        self::assertNotSame('', $token);

        // NetSuite's own access tokens are themselves JWTs: three dot-separated base64url segments.
        self::assertMatchesRegularExpression('/^[\w-]+\.[\w-]+\.[\w-]+$/', $token);
    }

    public function testASecondCallReusesTheCachedToken(): void
    {
        $config = $this->sandboxConfig();

        $provider = new OAuth2ClientCredentialsProvider($config, new Client());

        self::assertSame($provider->getAccessToken(), $provider->getAccessToken());
    }
}
