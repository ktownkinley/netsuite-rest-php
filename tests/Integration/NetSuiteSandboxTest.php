<?php

namespace NetSuiteRest\Tests\Integration;

use NetSuiteRest\NetSuite;
use PHPUnit\Framework\TestCase;

/**
 * Confirms the `NetSuite` facade's wiring actually works end-to-end against a real sandbox —
 * not a re-test of SuiteQL/Record behavior itself, which OAuth2SandboxAuthenticationTest,
 * SuiteQLSandboxQueryTest, and RecordServiceSandboxTest already cover in depth against the
 * lower-level classes directly.
 *
 * Requires real credentials — see .env.example. Skipped automatically when they aren't present.
 */
final class NetSuiteSandboxTest extends TestCase
{
    use RequiresSandboxConfig;

    public function testSuiteQLAndRecordsWorkThroughTheFacadeWithOnlyConfigSupplied(): void
    {
        $netsuite = new NetSuite($this->sandboxConfig());

        $page = $netsuite->suiteQL()->queryPage('SELECT id FROM customer', [], 1);
        self::assertNotEmpty($page['items'], 'Sandbox account has no customer records to test against.');

        $id = (int) $page['items'][0]['id'];
        $record = $netsuite->records()->get('customer', $id, fields: ['id']);

        self::assertSame((string) $id, $record['id']);
    }
}
