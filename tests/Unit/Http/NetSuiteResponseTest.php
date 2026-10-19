<?php

namespace NetSuiteRest\Tests\Unit\Http;

use GuzzleHttp\Psr7\Response;
use NetSuiteRest\Exception\UnexpectedResponseException;
use NetSuiteRest\Http\NetSuiteResponse;
use PHPUnit\Framework\TestCase;

final class NetSuiteResponseTest extends TestCase
{
    public function testReturnsTheStatusCode(): void
    {
        $response = new NetSuiteResponse(new Response(404));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testReturnsAHeaderValueWhenPresent(): void
    {
        $response = new NetSuiteResponse(new Response(204, [
            'Location' => 'https://example.test/services/rest/record/v1/customer/123',
        ]));

        self::assertSame(
            'https://example.test/services/rest/record/v1/customer/123',
            $response->getHeader('Location'),
        );
    }

    public function testReturnsNullForAMissingHeader(): void
    {
        $response = new NetSuiteResponse(new Response(200));

        self::assertNull($response->getHeader('Location'));
    }

    public function testJsonReturnsNullForAnEmptyBody(): void
    {
        $response = new NetSuiteResponse(new Response(204, [], ''));

        self::assertNull($response->json());
    }

    public function testJsonReturnsTheDecodedBody(): void
    {
        $response = new NetSuiteResponse(new Response(200, [], json_encode([
            'items' => [['id' => '1'], ['id' => '2']],
            'hasMore' => false,
        ])));

        self::assertSame(
            ['items' => [['id' => '1'], ['id' => '2']], 'hasMore' => false],
            $response->json(),
        );
    }

    public function testJsonThrowsOnAMalformedNonEmptyBody(): void
    {
        $response = new NetSuiteResponse(new Response(200, [], '{not valid json'));

        $this->expectException(UnexpectedResponseException::class);

        $response->json();
    }

    public function testUnexpectedResponseExceptionCarriesTheOriginatingResponse(): void
    {
        $response = new NetSuiteResponse(new Response(200, [], '<html>not json</html>'));

        try {
            $response->json();
            self::fail('Expected UnexpectedResponseException was not thrown.');
        } catch (UnexpectedResponseException $e) {
            self::assertSame($response, $e->getResponse());
        }
    }

    public function testGetRawResponseReturnsTheUnderlyingPsr7Response(): void
    {
        $raw = new Response(200);
        $response = new NetSuiteResponse($raw);

        self::assertSame($raw, $response->getRawResponse());
    }
}
