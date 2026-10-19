<?php

namespace NetSuiteRest\Tests\Unit\Exception;

use GuzzleHttp\Psr7\Response;
use NetSuiteRest\Exception\NetSuiteException;
use NetSuiteRest\Exception\UnexpectedResponseException;
use NetSuiteRest\Http\NetSuiteResponse;
use PHPUnit\Framework\TestCase;

final class UnexpectedResponseExceptionTest extends TestCase
{
    public function testIsANetSuiteException(): void
    {
        $response = new NetSuiteResponse(new Response(200));

        self::assertInstanceOf(
            NetSuiteException::class,
            new UnexpectedResponseException('bad body', $response),
        );
    }

    public function testCarriesTheMessageAndOriginatingResponse(): void
    {
        $response = new NetSuiteResponse(new Response(200));

        $exception = new UnexpectedResponseException('NetSuite response body could not be decoded.', $response);

        self::assertSame('NetSuite response body could not be decoded.', $exception->getMessage());
        self::assertSame($response, $exception->getResponse());
    }
}
