<?php

namespace NetSuiteRest\Tests\Integration;

use NetSuiteRest\Config\NetSuiteConfig;
use RuntimeException;

/**
 * Shared by integration test cases that need real sandbox credentials. Marks the test
 * skipped (rather than failed) when credentials aren't available, so `composer test` stays
 * green for anyone without sandbox access, including most CI runs.
 */
trait RequiresSandboxConfig
{
    private function sandboxConfig(): NetSuiteConfig
    {
        try {
            return SandboxConfig::fromEnvironment();
        } catch (RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }
    }
}
