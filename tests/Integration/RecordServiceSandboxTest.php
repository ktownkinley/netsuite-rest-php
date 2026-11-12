<?php

namespace NetSuiteRest\Tests\Integration;

use GuzzleHttp\Client;
use NetSuiteRest\Auth\OAuth2ClientCredentialsProvider;
use NetSuiteRest\Exception\ApiException;
use NetSuiteRest\Http\NetSuiteRestClient;
use NetSuiteRest\Record\RecordService;
use NetSuiteRest\SuiteQL\SuiteQLService;
use PHPUnit\Framework\TestCase;

/**
 * Exercises RecordService against a real NetSuite sandbox account.
 *
 * Requires real credentials — see .env.example. Skipped automatically when they aren't present.
 *
 * Every record this suite creates is deleted again before the test ends, successful or not, so
 * it never leaves test data behind in the connected sandbox.
 *
 * Creating a `customer` needs a valid `subsidiary` (and `category`, if that feature happens to
 * be enabled), but not every subsidiary a `SELECT id FROM subsidiary` returns is necessarily one
 * this integration's role is allowed to create customers under — NetSuite rejects the rest with
 * a plain 400, not a permissions-specific error. Rather than hardcoding an ID from this one
 * account's configuration, these tests try a small, bounded list of real candidates and use
 * whichever one NetSuite actually accepts.
 */
final class RecordServiceSandboxTest extends TestCase
{
    use RequiresSandboxConfig;

    private const RECORD_TYPE = 'customer';

    private function client(): NetSuiteRestClient
    {
        $config = $this->sandboxConfig();
        $tokenProvider = new OAuth2ClientCredentialsProvider($config, new Client());

        return new NetSuiteRestClient($config, $tokenProvider, new Client());
    }

    /**
     * @return iterable<array<string, mixed>> A small, bounded set of customer bodies to try,
     *                                        differing only in which subsidiary they target.
     */
    private function candidateCustomerBodies(NetSuiteRestClient $client, string $companyName): iterable
    {
        $suiteql = new SuiteQLService($client);

        $categories = iterator_to_array($suiteql->query('SELECT id FROM customercategory', [], 1));
        $categoryId = $categories[0]['id'] ?? null;

        foreach ($suiteql->query('SELECT id FROM subsidiary ORDER BY id', [], 5) as $row) {
            $body = ['companyName' => $companyName, 'isPerson' => false, 'subsidiary' => ['id' => $row['id']]];
            if ($categoryId !== null) {
                $body['category'] = ['id' => $categoryId];
            }

            yield $body;
        }
    }

    private function createWithAWorkingSubsidiary(RecordService $records, NetSuiteRestClient $client, string $companyName): string
    {
        $lastException = null;
        $attempted = false;

        foreach ($this->candidateCustomerBodies($client, $companyName) as $body) {
            $attempted = true;
            try {
                return $records->create(self::RECORD_TYPE, $body);
            } catch (ApiException $e) {
                $lastException = $e;
            }
        }

        if (!$attempted) {
            self::markTestSkipped('Sandbox account has no subsidiaries to test customer creation against.');
        }

        self::fail('No subsidiary in this sandbox allowed customer creation via the Record API: ' . $lastException?->getMessage());
    }

    private function upsertWithAWorkingSubsidiary(
        RecordService $records,
        NetSuiteRestClient $client,
        string $externalId,
        string $companyName,
    ): string {
        $lastException = null;
        $attempted = false;

        foreach ($this->candidateCustomerBodies($client, $companyName) as $body) {
            $attempted = true;
            try {
                return $records->upsert(self::RECORD_TYPE, $externalId, $body);
            } catch (ApiException $e) {
                $lastException = $e;
            }
        }

        if (!$attempted) {
            self::markTestSkipped('Sandbox account has no subsidiaries to test customer upsert against.');
        }

        self::fail('No subsidiary in this sandbox allowed customer upsert via the Record API: ' . $lastException?->getMessage());
    }

    public function testCreateGetAndDeleteARealCustomer(): void
    {
        $client = $this->client();
        $records = new RecordService($client);

        $id = $this->createWithAWorkingSubsidiary(
            $records,
            $client,
            'netsuite-rest-php RecordService integration test',
        );

        try {
            $record = $records->get(self::RECORD_TYPE, (int) $id, fields: ['companyName']);

            self::assertSame('netsuite-rest-php RecordService integration test', $record['companyName']);
        } finally {
            $records->delete(self::RECORD_TYPE, (int) $id);
        }

        try {
            $records->get(self::RECORD_TYPE, (int) $id);
            self::fail('Expected the deleted record to be gone.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getStatusCode());
        }
    }

    public function testUpsertCreatesThenUpdatesTheSameRecordByExternalId(): void
    {
        $client = $this->client();
        $records = new RecordService($client);
        $externalId = 'netsuite-rest-php-test-' . bin2hex(random_bytes(4));

        $createdId = $this->upsertWithAWorkingSubsidiary(
            $records,
            $client,
            $externalId,
            'netsuite-rest-php RecordService integration test',
        );

        try {
            $updatedId = $records->upsert(self::RECORD_TYPE, $externalId, [
                'companyName' => 'netsuite-rest-php RecordService integration test (updated)',
            ]);

            self::assertSame(
                $createdId,
                $updatedId,
                'Upserting the same external ID again must affect the same record, not create a second one.',
            );

            $record = $records->getByExternalId(self::RECORD_TYPE, $externalId, fields: ['companyName']);
            self::assertSame(
                'netsuite-rest-php RecordService integration test (updated)',
                $record['companyName'],
            );
        } finally {
            $records->deleteByExternalId(self::RECORD_TYPE, $externalId);
        }
    }

    public function testStrictPropertyNamesRejectsAnUnknownFieldThatIsOtherwiseIgnored(): void
    {
        $client = $this->client();
        $records = new RecordService($client);
        $unknownField = ['companyName' => 'netsuite-rest-php strict test', 'custentity_netsuite_rest_php_no_such_field' => 'x'];

        $id = $this->createWithAWorkingSubsidiary($records, $client, 'netsuite-rest-php strict test');

        try {
            // NetSuite's default: the unknown field is silently dropped and the write succeeds.
            $records->update(self::RECORD_TYPE, (int) $id, $unknownField);

            try {
                $records->withStrictPropertyNames()->update(self::RECORD_TYPE, (int) $id, $unknownField);
                self::fail('Expected the strict write to be rejected.');
            } catch (ApiException $e) {
                self::assertSame(400, $e->getStatusCode());
                self::assertStringContainsString('custentity_netsuite_rest_php_no_such_field', $e->getMessage());
            }
        } finally {
            $records->delete(self::RECORD_TYPE, (int) $id);
        }
    }

    public function testTransformCopiesTheSourceAndCanReplaceItsLines(): void
    {
        $client = $this->client();
        $records = (new RecordService($client))->withStrictPropertyNames();

        // Any billed sales order with at least two inventory lines; read-only use of existing data.
        $page = (new SuiteQLService($client))->queryPage(
            "SELECT t.id FROM transaction t
             JOIN transactionline tl ON tl.transaction = t.id AND tl.mainline = 'F' AND tl.itemtype = 'InvtPart'
             WHERE t.type = 'SalesOrd' AND t.status = 'G'
             GROUP BY t.id HAVING COUNT(*) >= 2",
            [],
            1,
        );
        $salesOrderId = (int) ($page['items'][0]['id'] ?? 0);
        if ($salesOrderId === 0) {
            self::markTestSkipped('Sandbox account has no billed sales order with two or more item lines.');
        }
        $sourceLines = array_values(array_filter(
            $records->get('salesOrder', $salesOrderId, expandSubResources: true)['item']['items'],
            static fn (array $line) => ($line['itemType']['id'] ?? null) === 'InvtPart',
        ));

        // No body: NetSuite copies the source, including every line.
        $fullId = $records->transform('salesOrder', $salesOrderId, 'returnAuthorization');
        try {
            $full = $records->get('returnAuthorization', (int) $fullId, expandSubResources: true);
            self::assertSame((string) $salesOrderId, $full['createdFrom']['id']);
            self::assertGreaterThanOrEqual(count($sourceLines), count($full['item']['items']));
        } finally {
            $records->delete('returnAuthorization', (int) $fullId);
        }

        // Replacing the item sublist keeps only the listed line.
        $partialId = $records->transform(
            'salesOrder',
            $salesOrderId,
            'returnAuthorization',
            ['item' => ['items' => [['orderLine' => $sourceLines[0]['line'], 'quantity' => 1]]]],
            ['item'],
        );
        try {
            $partial = $records->get('returnAuthorization', (int) $partialId, expandSubResources: true);
            self::assertSame((string) $salesOrderId, $partial['createdFrom']['id']);
            self::assertCount(1, $partial['item']['items']);
            self::assertSame($sourceLines[0]['item']['id'], $partial['item']['items'][0]['item']['id']);
        } finally {
            $records->delete('returnAuthorization', (int) $partialId);
        }
    }

    public function testGetThrowsApiExceptionForANonexistentRecord(): void
    {
        $records = new RecordService($this->client());

        try {
            $records->get(self::RECORD_TYPE, 999999999);
            self::fail('Expected ApiException was not thrown.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getStatusCode());
        }
    }
}
