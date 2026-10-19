<?php

namespace NetSuiteRest\Exception;

use NetSuiteRest\Http\NetSuiteResponse;

/**
 * Thrown when NetSuite returns a response the library considers successful (a non-error
 * HTTP status), but whose body cannot be interpreted as expected — for example a non-empty
 * body that should be JSON but isn't. This is distinct from {@see ApiException}, where
 * NetSuite explicitly reported an error, and from {@see TransportException}, where no
 * response was received at all.
 */
final class UnexpectedResponseException extends NetSuiteException
{
    public function __construct(string $message, private readonly NetSuiteResponse $response)
    {
        parent::__construct($message);
    }

    public function getResponse(): NetSuiteResponse
    {
        return $this->response;
    }
}
