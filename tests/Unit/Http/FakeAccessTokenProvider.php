<?php

namespace NetSuiteRest\Tests\Unit\Http;

use NetSuiteRest\Auth\AccessTokenProviderInterface;

final class FakeAccessTokenProvider implements AccessTokenProviderInterface
{
    private int $index = 0;

    public int $invalidateCallCount = 0;

    /**
     * @param string[] $tokens Returned in order, one per getAccessToken() call. The last
     *                         token is repeated if getAccessToken() is called more times
     *                         than tokens were supplied.
     */
    public function __construct(private readonly array $tokens = ['test-access-token'])
    {
    }

    public function getAccessToken(): string
    {
        $token = $this->tokens[$this->index] ?? $this->tokens[array_key_last($this->tokens)];
        $this->index++;

        return $token;
    }

    public function invalidate(): void
    {
        $this->invalidateCallCount++;
    }
}
