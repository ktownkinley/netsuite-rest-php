<?php

namespace NetSuiteRest\Tests\Unit\Config;

use InvalidArgumentException;
use NetSuiteRest\Config\NetSuiteConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NetSuiteConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function makeConfig(array $overrides = []): NetSuiteConfig
    {
        $args = array_merge([
            'clientId' => 'client-id',
            'certificateId' => 'certificate-id',
            'privateKey' => '-----BEGIN PRIVATE KEY-----FAKE-----END PRIVATE KEY-----',
            'restApiBaseUrl' => 'https://1234567-sb1.suitetalk.api.netsuite.com',
            'scopes' => ['rest_webservices'],
        ], $overrides);

        return new NetSuiteConfig(...$args);
    }

    public function testGettersReturnTheConstructorValues(): void
    {
        $config = $this->makeConfig();

        self::assertSame('client-id', $config->getClientId());
        self::assertSame('certificate-id', $config->getCertificateId());
        self::assertSame('-----BEGIN PRIVATE KEY-----FAKE-----END PRIVATE KEY-----', $config->getPrivateKey());
        self::assertSame('https://1234567-sb1.suitetalk.api.netsuite.com', $config->getRestApiBaseUrl());
        self::assertSame(['rest_webservices'], $config->getScopes());
    }

    public function testAcceptsMultipleScopes(): void
    {
        $config = $this->makeConfig(['scopes' => ['rest_webservices', 'restlets']]);

        self::assertSame(['rest_webservices', 'restlets'], $config->getScopes());
    }

    #[DataProvider('requiredStringFieldProvider')]
    public function testThrowsWhenARequiredStringFieldIsEmpty(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeConfig([$field => '']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredStringFieldProvider(): array
    {
        return [
            'clientId' => ['clientId'],
            'certificateId' => ['certificateId'],
            'privateKey' => ['privateKey'],
            'restApiBaseUrl' => ['restApiBaseUrl'],
        ];
    }

    public function testThrowsWhenScopesIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeConfig(['scopes' => []]);
    }

    public function testThrowsWhenAScopeIsAnEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeConfig(['scopes' => ['rest_webservices', '']]);
    }

    public function testThrowsWhenAScopeIsNotAString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeConfig(['scopes' => [123]]);
    }
}
