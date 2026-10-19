<?php

namespace NetSuiteRest\Tests\Unit\Exception;

use NetSuiteRest\Exception\NetSuiteException;
use NetSuiteRest\Exception\TransportException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransportExceptionTest extends TestCase
{
    public function testIsANetSuiteException(): void
    {
        self::assertInstanceOf(NetSuiteException::class, new TransportException('Connection refused'));
    }

    public function testCarriesTheMessageAndPreviousException(): void
    {
        $previous = new RuntimeException('cURL error 6: Could not resolve host');

        $exception = new TransportException('Could not reach NetSuite.', $previous);

        self::assertSame('Could not reach NetSuite.', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }
}
