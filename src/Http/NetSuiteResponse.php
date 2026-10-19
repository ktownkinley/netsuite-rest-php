<?php

namespace NetSuiteRest\Http;

use NetSuiteRest\Exception\UnexpectedResponseException;
use Psr\Http\Message\ResponseInterface;

/**
 * A small wrapper around a NetSuite HTTP response.
 *
 * NetSuite's REST APIs mix bodyless 204 responses (with useful information such as a
 * created record's URL only in the `Location` header) with JSON bodies, so returning a
 * bare decoded array from the transport layer would lose information. This wraps the raw
 * PSR-7 response without reimplementing it, decoding JSON lazily and only on request.
 */
final class NetSuiteResponse
{
    private bool $bodyDecoded = false;

    private ?array $decodedBody = null;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function getHeader(string $name): ?string
    {
        $values = $this->response->getHeader($name);

        return $values[0] ?? null;
    }

    /**
     * Decodes the response body as JSON.
     *
     * Returns null only when the body was empty (e.g. a 204 No Content response). A
     * non-empty body that cannot be decoded as the expected JSON object throws rather
     * than silently returning null, since that is a materially different failure.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if ($this->bodyDecoded) {
            return $this->decodedBody;
        }

        $body = (string) $this->response->getBody();

        if ($body === '') {
            $this->bodyDecoded = true;

            return null;
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new UnexpectedResponseException(
                'NetSuite response body could not be decoded as JSON: ' . json_last_error_msg(),
                $this,
            );
        }

        $this->bodyDecoded = true;
        $this->decodedBody = $data;

        return $this->decodedBody;
    }

    public function getRawResponse(): ResponseInterface
    {
        return $this->response;
    }
}
