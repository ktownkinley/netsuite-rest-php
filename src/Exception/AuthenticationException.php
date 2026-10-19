<?php

namespace NetSuiteRest\Exception;

/**
 * Thrown when obtaining or validating a NetSuite access token fails
 * (e.g. a rejected client assertion or a malformed token response).
 */
class AuthenticationException extends NetSuiteException
{
}
