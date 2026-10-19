<?php

namespace NetSuiteRest\Config;

final readonly class NetSuiteConfig
{
    /**
     * @param string[] $scopes One or more NetSuite OAuth 2.0 scopes (e.g. "rest_webservices", "restlets",
     *                         "suite_analytics") the application is authorized for and intends to use.
     */
    public function __construct(
        private string $clientId,
        private string $certificateId,
        private string $privateKey,
        private string $restApiBaseUrl,
        private array $scopes,
    ) {
        if ($clientId === '') {
            throw new \InvalidArgumentException('NetSuite client ID must not be empty.');
        }
        if ($certificateId === '') {
            throw new \InvalidArgumentException('NetSuite certificate ID must not be empty.');
        }
        if ($privateKey === '') {
            throw new \InvalidArgumentException('NetSuite private key must not be empty.');
        }
        if ($restApiBaseUrl === '') {
            throw new \InvalidArgumentException('NetSuite REST API base URL must not be empty.');
        }
        if ($scopes === []) {
            throw new \InvalidArgumentException('At least one NetSuite OAuth 2.0 scope must be provided.');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || $scope === '') {
                throw new \InvalidArgumentException('NetSuite OAuth 2.0 scopes must be non-empty strings.');
            }
        }
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getCertificateId(): string
    {
        return $this->certificateId;
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    /**
     * The account-specific NetSuite REST base URL, e.g. "https://1234567-sb1.suitetalk.api.netsuite.com".
     *
     * NetSuite recommends using the actual account-specific URL rather than one constructed from the
     * account ID, since not every account follows the simple "<account-id>.suitetalk.api.netsuite.com"
     * pattern. The caller is responsible for supplying the correct value.
     */
    public function getRestApiBaseUrl(): string
    {
        return $this->restApiBaseUrl;
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }
}
