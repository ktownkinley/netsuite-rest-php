<?php

namespace NetSuiteRest\Exception;

/**
 * Base exception for all library-specific failures.
 *
 * Consumers can catch this broadly to handle any error originating from
 * this library, or catch a more specific subclass to react differently
 * to a particular failure mode.
 */
class NetSuiteException extends \RuntimeException
{
}
