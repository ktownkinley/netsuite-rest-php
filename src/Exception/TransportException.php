<?php

namespace NetSuiteRest\Exception;

use Throwable;

/**
 * Thrown when no valid HTTP response was received from NetSuite at all — for example a
 * DNS failure, connection failure, TLS problem, or timeout. Unlike {@see ApiException},
 * there is no status code or NetSuite error body to report, since NetSuite never had the
 * chance to respond.
 */
final class TransportException extends NetSuiteException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
