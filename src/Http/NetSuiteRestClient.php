<?php

namespace NetSuiteRest\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use NetSuiteRest\Auth\AccessTokenProviderInterface;
use NetSuiteRest\Config\NetSuiteConfig;
use NetSuiteRest\Exception\ApiException;
use NetSuiteRest\Exception\TransportException;

/**
 * Generic authenticated HTTP transport for NetSuite's REST APIs.
 *
 * This knows how to reach NetSuite: URL construction, bearer authentication, JSON
 * request/response handling, and turning transport/HTTP failures into this library's
 * exceptions. It knows nothing about SuiteQL syntax, record types, or any other
 * NetSuite domain behavior — that belongs in the services built on top of it.
 */
final class NetSuiteRestClient
{
    private const REST_BASE_PATH = '/services/rest/';

    public function __construct(
        private readonly NetSuiteConfig $config,
        private readonly AccessTokenProviderInterface $tokenProvider,
        private readonly ClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     */
    public function get(string $path, array $query = [], array $headers = []): NetSuiteResponse
    {
        return $this->request('GET', $path, ['query' => $query], $headers);
    }

    /**
     * @param array<string, mixed>|null $body Sent as a JSON-encoded body, or omitted entirely when null.
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     */
    public function post(string $path, ?array $body = null, array $query = [], array $headers = []): NetSuiteResponse
    {
        return $this->request('POST', $path, $this->bodyOptions($body, $query), $headers);
    }

    /**
     * @param array<string, mixed>|null $body Sent as a JSON-encoded body, or omitted entirely when null.
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     */
    public function patch(string $path, ?array $body = null, array $query = [], array $headers = []): NetSuiteResponse
    {
        return $this->request('PATCH', $path, $this->bodyOptions($body, $query), $headers);
    }

    /**
     * @param array<string, mixed>|null $body Sent as a JSON-encoded body, or omitted entirely when null.
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     */
    public function put(string $path, ?array $body = null, array $query = [], array $headers = []): NetSuiteResponse
    {
        return $this->request('PUT', $path, $this->bodyOptions($body, $query), $headers);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     */
    public function delete(string $path, array $query = [], array $headers = []): NetSuiteResponse
    {
        return $this->request('DELETE', $path, ['query' => $query], $headers);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function bodyOptions(?array $body, array $query): array
    {
        $options = ['query' => $query];

        if ($body !== null) {
            $options['json'] = $body;
        }

        return $options;
    }

    /**
     * Sends the request, retrying exactly once if NetSuite rejects the access token with a
     * 401. There are only ever two attempts made here — one initial send below, and one
     * fixed replay inside the `if` — so this cannot recurse or loop indefinitely regardless
     * of what NetSuite returns.
     *
     * @param array<string, mixed> $options Guzzle request options (e.g. 'query', 'json'), excluding auth/headers.
     * @param array<string, string> $headers Caller-supplied headers, merged over the defaults.
     */
    private function request(string $method, string $path, array $options, array $headers): NetSuiteResponse
    {
        $response = $this->sendOnce($method, $path, $options, $headers, $this->tokenProvider->getAccessToken());

        if ($response->getStatusCode() === 401) {
            $this->tokenProvider->invalidate();

            // If reacquiring a token fails, the resulting AuthenticationException propagates as-is.
            $freshToken = $this->tokenProvider->getAccessToken();

            $response = $this->sendOnce($method, $path, $options, $headers, $freshToken);
        }

        if ($response->getStatusCode() >= 400) {
            throw new ApiException($response);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $headers
     */
    private function sendOnce(string $method, string $path, array $options, array $headers, string $accessToken): NetSuiteResponse
    {
        $requestOptions = array_merge($options, [
            'headers' => array_merge([
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ], $headers),
        ]);

        try {
            $response = $this->httpClient->request($method, $this->url($path), $requestOptions);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                return new NetSuiteResponse($e->getResponse());
            }

            throw new TransportException('Failed to reach NetSuite: ' . $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new TransportException('Failed to reach NetSuite: ' . $e->getMessage(), $e);
        }

        return new NetSuiteResponse($response);
    }

    private function url(string $path): string
    {
        return rtrim($this->config->getRestApiBaseUrl(), '/') . self::REST_BASE_PATH . ltrim($path, '/');
    }
}
