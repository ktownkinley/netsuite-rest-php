<?php

namespace NetSuiteRest\Tests\Unit\Auth;

use NetSuiteRest\Auth\BigIntegerSupport;
use NetSuiteRest\Exception\AuthenticationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BigIntegerSupportTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function supportedRuntimes(): iterable
    {
        yield 'gmp only' => [['gmp']];
        yield 'bcmath only' => [['bcmath']];
        yield 'both' => [['gmp', 'bcmath']];
    }

    /**
     * @param list<string> $loaded
     */
    #[DataProvider('supportedRuntimes')]
    public function testPassesWhenEitherExtensionIsLoaded(array $loaded): void
    {
        BigIntegerSupport::assertAvailable(static fn (string $ext): bool => in_array($ext, $loaded, true));

        $this->addToAssertionCount(1);
    }

    public function testThrowsANamedCauseWhenNeitherExtensionIsLoaded(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('requires the gmp or bcmath PHP extension');

        BigIntegerSupport::assertAvailable(static fn (string $ext): bool => false);
    }

    public function testDefaultsToTheRealRuntime(): void
    {
        $hasOne = extension_loaded('gmp') || extension_loaded('bcmath');

        if (!$hasOne) {
            $this->expectException(AuthenticationException::class);
        }

        BigIntegerSupport::assertAvailable();

        $this->addToAssertionCount(1);
    }
}
