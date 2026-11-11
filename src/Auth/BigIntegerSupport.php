<?php

namespace NetSuiteRest\Auth;

use NetSuiteRest\Exception\AuthenticationException;

/**
 * Guards PS256 signing against a runtime with no fast big-integer extension.
 *
 * web-token signs PS256 (RSA-PSS) with PHP-level RSA arithmetic through brick/math, which uses
 * ext-gmp if loaded, then ext-bcmath, and otherwise falls back to pure PHP. For the 3072+ bit
 * keys NetSuite requires, the pure-PHP fallback takes minutes per signature, so the client
 * assertion expires before it is sent and NetSuite answers with a bare `invalid_grant`. Failing
 * here, before any request, turns that into an error that names the actual cause.
 *
 * @internal Not part of the public API.
 */
final class BigIntegerSupport
{
    /** Extensions brick/math can use for fast arithmetic, in the order it prefers them. */
    public const EXTENSIONS = ['gmp', 'bcmath'];

    /**
     * @param (callable(string): bool)|null $isExtensionLoaded Replaceable for tests; defaults to extension_loaded().
     *
     * @throws AuthenticationException When none of the supported extensions is loaded.
     */
    public static function assertAvailable(?callable $isExtensionLoaded = null): void
    {
        $isExtensionLoaded ??= extension_loaded(...);

        foreach (self::EXTENSIONS as $extension) {
            if ($isExtensionLoaded($extension)) {
                return;
            }
        }

        throw new AuthenticationException(
            'Signing the NetSuite PS256 client assertion requires the gmp or bcmath PHP extension, '
            . 'and neither is loaded. Without one, signing takes minutes and NetSuite rejects the '
            . 'expired assertion. Install or enable ext-gmp (fastest) or ext-bcmath.'
        );
    }
}
