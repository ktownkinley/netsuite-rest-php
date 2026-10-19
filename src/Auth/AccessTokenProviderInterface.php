<?php

namespace NetSuiteRest\Auth;

interface AccessTokenProviderInterface
{
    public function getAccessToken(): string;

    /**
     * Discards any cached access token, forcing the next {@see getAccessToken()} call to
     * obtain a new one. Callers use this when NetSuite has rejected the current token
     * (e.g. a 401 response), since the provider owns caching and is the only thing that
     * knows how to acquire a replacement.
     */
    public function invalidate(): void;
}
