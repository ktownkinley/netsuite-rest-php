<?php

namespace NetSuiteRest\Tests\Unit\Record;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NetSuiteRest\Config\NetSuiteConfig;
use NetSuiteRest\Exception\ApiException;
use NetSuiteRest\Exception\UnexpectedResponseException;
use NetSuiteRest\Http\NetSuiteRestClient;
use NetSuiteRest\Record\RecordService;
use NetSuiteRest\Tests\Unit\Http\FakeAccessTokenProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class RecordServiceTest extends TestCase
{
    private const BASE_URL = 'https://1234567-sb1.suitetalk.api.netsuite.com/services/rest/record/v1';

    /**
     * @param array<int, Response> $responses
     * @param array<int, array{request: RequestInterface}> $history Populated with each transaction as it happens.
     */
    private function newService(array $responses, array &$history): RecordService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $client = new NetSuiteRestClient(
            $this->makeConfig(),
            new FakeAccessTokenProvider(),
            new Client(['handler' => $stack]),
        );

        return new RecordService($client);
    }

    private function makeConfig(): NetSuiteConfig
    {
        return new NetSuiteConfig(
            clientId: 'client-id',
            certificateId: 'certificate-id',
            privateKey: '-----BEGIN PRIVATE KEY-----FAKE-----END PRIVATE KEY-----',
            restApiBaseUrl: 'https://1234567-sb1.suitetalk.api.netsuite.com',
            scopes: ['rest_webservices'],
        );
    }

    public function testGetSendsARequestForTheGivenInternalId(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(200, [], json_encode(['id' => '123', 'companyName' => 'Acme'])),
        ], $history);

        $record = $service->get('customer', 123);

        $request = $history[0]['request'];
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/customer/123', (string) $request->getUri()->withQuery(''));
        self::assertSame(['id' => '123', 'companyName' => 'Acme'], $record);
    }

    public function testGetOmitsFieldsAndExpandSubResourcesByDefault(): void
    {
        $history = [];
        $service = $this->newService([new Response(200, [], '{}')], $history);

        $service->get('customer', 123);

        self::assertSame('', $history[0]['request']->getUri()->getQuery());
    }

    public function testGetSendsFieldsAsACommaSeparatedList(): void
    {
        $history = [];
        $service = $this->newService([new Response(200, [], '{}')], $history);

        $service->get('customer', 123, fields: ['companyName', 'email']);

        parse_str($history[0]['request']->getUri()->getQuery(), $query);
        self::assertSame('companyName,email', $query['fields']);
    }

    public function testGetSendsExpandSubResourcesWhenRequested(): void
    {
        $history = [];
        $service = $this->newService([new Response(200, [], '{}')], $history);

        $service->get('customer', 123, expandSubResources: true);

        self::assertSame('expandSubResources=true', $history[0]['request']->getUri()->getQuery());
    }

    public function testGetThrowsUnexpectedResponseExceptionForAnEmptyBody(): void
    {
        $history = [];
        $service = $this->newService([new Response(200, [], '')], $history);

        $this->expectException(UnexpectedResponseException::class);

        $service->get('customer', 123);
    }

    public function testGetByExternalIdAddressesTheRecordByEid(): void
    {
        $history = [];
        $service = $this->newService([new Response(200, [], '{}')], $history);

        $service->getByExternalId('customer', 'SKU-001');

        self::assertSame(self::BASE_URL . '/customer/eid:SKU-001', (string) $history[0]['request']->getUri());
    }

    /**
     * An external ID is opaque data, not URL structure — it must reach NetSuite as a single
     * path component no matter what characters it contains. A `/` that leaked through
     * un-encoded would silently split into an extra path segment instead of raising any
     * error, so this checks the actual wire contract (one component, round-trips back to the
     * original value) rather than asserting a specific encoding function was called.
     */
    public function testGetByExternalIdSendsTheExternalIdAsASingleEncodedPathComponent(): void
    {
        $history = [];
        $service = $this->newService([new Response(200, [], '{}')], $history);

        $service->getByExternalId('customer', 'A/B');

        $path = $history[0]['request']->getUri()->getPath();
        $prefix = parse_url(self::BASE_URL, PHP_URL_PATH) . '/customer/';

        self::assertStringStartsWith($prefix, $path);

        $lastSegment = substr($path, strlen($prefix));

        self::assertStringNotContainsString(
            '/',
            $lastSegment,
            'The external ID must stay a single path component, not split into an extra segment.',
        );
        self::assertSame('eid:A/B', rawurldecode($lastSegment));
    }

    public function testCreateSendsAPostAndReturnsTheIdFromLocation(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(204, ['Location' => self::BASE_URL . '/customer/647']),
        ], $history);

        $id = $service->create('customer', ['companyName' => 'Acme']);

        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/customer', (string) $request->getUri());
        self::assertSame(['companyName' => 'Acme'], json_decode((string) $request->getBody(), true));
        self::assertSame('647', $id);
    }

    public function testCreateThrowsUnexpectedResponseExceptionWhenLocationIsMissing(): void
    {
        $history = [];
        $service = $this->newService([new Response(204)], $history);

        $this->expectException(UnexpectedResponseException::class);

        $service->create('customer', ['companyName' => 'Acme']);
    }

    public function testCreateThrowsUnexpectedResponseExceptionWhenLocationHasNoIdSegment(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(204, ['Location' => self::BASE_URL . '/customer/']),
        ], $history);

        $this->expectException(UnexpectedResponseException::class);

        $service->create('customer', ['companyName' => 'Acme']);
    }

    public function testUpdateSendsAPatchToTheInternalId(): void
    {
        $history = [];
        $service = $this->newService([new Response(204)], $history);

        $service->update('customer', 123, ['companyName' => 'Acme Updated']);

        $request = $history[0]['request'];
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame(self::BASE_URL . '/customer/123', (string) $request->getUri());
        self::assertSame(['companyName' => 'Acme Updated'], json_decode((string) $request->getBody(), true));
    }

    public function testUpdateByExternalIdSendsAPatchToTheEid(): void
    {
        $history = [];
        $service = $this->newService([new Response(204)], $history);

        $service->updateByExternalId('customer', 'SKU-001', ['companyName' => 'Acme Updated']);

        self::assertSame(self::BASE_URL . '/customer/eid:SKU-001', (string) $history[0]['request']->getUri());
    }

    public function testDeleteSendsADeleteToTheInternalId(): void
    {
        $history = [];
        $service = $this->newService([new Response(204)], $history);

        $service->delete('customer', 123);

        $request = $history[0]['request'];
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame(self::BASE_URL . '/customer/123', (string) $request->getUri());
    }

    public function testDeleteByExternalIdSendsADeleteToTheEid(): void
    {
        $history = [];
        $service = $this->newService([new Response(204)], $history);

        $service->deleteByExternalId('customer', 'SKU-001');

        self::assertSame(self::BASE_URL . '/customer/eid:SKU-001', (string) $history[0]['request']->getUri());
    }

    public function testUpsertSendsAPutAndReturnsTheIdFromLocation(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(204, ['Location' => self::BASE_URL . '/customer/647']),
        ], $history);

        $id = $service->upsert('customer', 'SKU-001', ['companyName' => 'Acme']);

        $request = $history[0]['request'];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame(self::BASE_URL . '/customer/eid:SKU-001', (string) $request->getUri());
        self::assertSame(['companyName' => 'Acme'], json_decode((string) $request->getBody(), true));
        self::assertSame('647', $id);
    }

    public function testUpsertThrowsUnexpectedResponseExceptionWhenLocationIsMissing(): void
    {
        $history = [];
        $service = $this->newService([new Response(204)], $history);

        $this->expectException(UnexpectedResponseException::class);

        $service->upsert('customer', 'SKU-001', ['companyName' => 'Acme']);
    }

    public function testTransformPostsToTheSourceRecordAndReturnsTheNewId(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(204, ['Location' => self::BASE_URL . '/returnAuthorization/900']),
        ], $history);

        $id = $service->transform('salesOrder', 123, 'returnAuthorization', ['memo' => 'Damaged']);

        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/salesOrder/123/!transform/returnAuthorization', (string) $request->getUri());
        self::assertSame(['memo' => 'Damaged'], json_decode((string) $request->getBody(), true));
        self::assertSame('900', $id);
    }

    public function testTransformWithNoBodySendsAnEmptyObject(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(204, ['Location' => self::BASE_URL . '/returnAuthorization/900']),
        ], $history);

        $service->transform('salesOrder', 123, 'returnAuthorization');

        self::assertSame('{}', (string) $history[0]['request']->getBody());
    }

    public function testTransformSendsTheSublistsToReplace(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(204, ['Location' => self::BASE_URL . '/returnAuthorization/900']),
            new Response(204, ['Location' => self::BASE_URL . '/returnAuthorization/901']),
        ], $history);

        $lines = ['item' => ['items' => [['orderLine' => 2, 'quantity' => 1]]]];
        $service->transform('salesOrder', 123, 'returnAuthorization', $lines, ['item']);
        $service->transform('salesOrder', 123, 'returnAuthorization', $lines + ['shipGroup' => ['items' => []]], ['item', 'shipGroup']);

        self::assertSame('replace=item', $history[0]['request']->getUri()->getQuery());
        self::assertSame('replace=item%2CshipGroup', $history[1]['request']->getUri()->getQuery());
    }

    public function testTransformSendsNoReplaceByDefault(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(204, ['Location' => self::BASE_URL . '/returnAuthorization/900']),
        ], $history);

        $service->transform('salesOrder', 123, 'returnAuthorization', ['memo' => 'x']);

        self::assertSame('', $history[0]['request']->getUri()->getQuery());
    }

    public function testTransformRejectsAnEmptySublistName(): void
    {
        $history = [];
        $service = $this->newService([], $history);

        $this->expectException(\InvalidArgumentException::class);

        $service->transform('salesOrder', 123, 'returnAuthorization', [], ['']);
    }

    public function testTransformByExternalIdAddressesTheSourceByEncodedEid(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(204, ['Location' => self::BASE_URL . '/returnAuthorization/900']),
        ], $history);

        $id = $service->transformByExternalId('salesOrder', 'Magento2-100/1', 'returnAuthorization');

        self::assertSame(
            self::BASE_URL . '/salesOrder/eid:Magento2-100%2F1/!transform/returnAuthorization',
            (string) $history[0]['request']->getUri(),
        );
        self::assertSame('900', $id);
    }

    public function testTransformThrowsUnexpectedResponseExceptionWhenLocationIsMissing(): void
    {
        $history = [];
        $service = $this->newService([new Response(204)], $history);

        $this->expectException(UnexpectedResponseException::class);

        $service->transform('salesOrder', 123, 'returnAuthorization');
    }

    public function testUpdatesAndUpsertSendTheSublistsToReplace(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(204),
            new Response(204),
            new Response(204, ['Location' => self::BASE_URL . '/salesOrder/647']),
        ], $history);
        $body = ['item' => ['items' => [['item' => ['id' => '10'], 'quantity' => 1]]], 'addressBook' => ['items' => []]];

        $service->update('salesOrder', 647, $body, ['item']);
        $service->updateByExternalId('salesOrder', 'SO-1', $body, ['item', 'addressBook']);
        $service->upsert('salesOrder', 'SO-1', $body, ['item']);

        self::assertSame('replace=item', $history[0]['request']->getUri()->getQuery());
        self::assertSame('replace=item%2CaddressBook', $history[1]['request']->getUri()->getQuery());
        self::assertSame('replace=item', $history[2]['request']->getUri()->getQuery());
        self::assertSame(self::BASE_URL . '/salesOrder/eid:SO-1?replace=item', (string) $history[2]['request']->getUri());
    }

    public function testUpdatesAndUpsertSendNoReplaceByDefault(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(204),
            new Response(204),
            new Response(204, ['Location' => self::BASE_URL . '/salesOrder/647']),
        ], $history);

        $service->update('salesOrder', 647, ['memo' => 'x']);
        $service->updateByExternalId('salesOrder', 'SO-1', ['memo' => 'x']);
        $service->upsert('salesOrder', 'SO-1', ['memo' => 'x']);

        foreach ($history as $transaction) {
            self::assertSame('', $transaction['request']->getUri()->getQuery());
        }
    }

    /**
     * @return array<string, array{\Closure(RecordService): mixed}>
     */
    public static function writesReplacingASublistMissingFromTheBody(): array
    {
        $body = ['memo' => 'no item sublist'];
        return [
            'update' => [static fn (RecordService $s) => $s->update('salesOrder', 1, $body, ['item'])],
            'updateByExternalId' => [static fn (RecordService $s) => $s->updateByExternalId('salesOrder', 'A', $body, ['item'])],
            'upsert' => [static fn (RecordService $s) => $s->upsert('salesOrder', 'A', $body, ['item'])],
            'transform' => [static fn (RecordService $s) => $s->transform('salesOrder', 1, 'returnAuthorization', $body, ['item'])],
        ];
    }

    /**
     * @param \Closure(RecordService): mixed $operation
     */
    #[DataProvider('writesReplacingASublistMissingFromTheBody')]
    public function testReplacingASublistMissingFromTheBodyFailsBeforeAnyRequest(\Closure $operation): void
    {
        $history = [];
        $service = $this->newService([], $history);

        try {
            $operation($service);
            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"item"', $e->getMessage());
        }
        self::assertSame([], $history);
    }

    public function testRecordApiErrorsSurfaceAsApiException(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(404, [], json_encode([
                'type' => 'https://example.test/errors/not-found',
                'title' => 'Not Found',
                'o:errorDetails' => [['detail' => 'Invalid id.', 'o:errorCode' => 'SSS_INVALID_ID']],
            ])),
        ], $history);

        try {
            $service->get('customer', 999999);
            self::fail('Expected ApiException was not thrown.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getStatusCode());
            self::assertSame('SSS_INVALID_ID', $e->getErrorDetails()[0]['o:errorCode']);
        }
    }

    /**
     * @return array<string, array{\Closure(RecordService): mixed}>
     */
    public static function writeOperations(): array
    {
        return [
            'create' => [static fn (RecordService $s) => $s->create('customer', ['companyName' => 'Acme'])],
            'update' => [static fn (RecordService $s) => $s->update('customer', 123, ['companyName' => 'Acme'])],
            'updateByExternalId' => [
                static fn (RecordService $s) => $s->updateByExternalId('customer', 'SKU-001', ['companyName' => 'Acme']),
            ],
            'upsert' => [static fn (RecordService $s) => $s->upsert('customer', 'SKU-001', ['companyName' => 'Acme'])],
            'transform' => [static fn (RecordService $s) => $s->transform('salesOrder', 123, 'returnAuthorization')],
            'transformByExternalId' => [
                static fn (RecordService $s) => $s->transformByExternalId('salesOrder', 'SO-1', 'returnAuthorization'),
            ],
        ];
    }

    /**
     * @param \Closure(RecordService): mixed $operation
     */
    #[DataProvider('writeOperations')]
    public function testWritesDoNotRequestPropertyNameValidationByDefault(\Closure $operation): void
    {
        $history = [];
        $service = $this->newService([new Response(204, ['Location' => self::BASE_URL . '/customer/647'])], $history);

        $operation($service);

        self::assertFalse($history[0]['request']->hasHeader('X-NetSuite-PropertyNameValidation'));
    }

    /**
     * @param \Closure(RecordService): mixed $operation
     */
    #[DataProvider('writeOperations')]
    public function testStrictWritesAskNetSuiteToRejectUnknownFields(\Closure $operation): void
    {
        $history = [];
        $service = $this->newService([new Response(204, ['Location' => self::BASE_URL . '/customer/647'])], $history);

        $operation($service->withStrictPropertyNames());

        self::assertSame('Error', $history[0]['request']->getHeaderLine('X-NetSuite-PropertyNameValidation'));
    }

    public function testStrictReadsAndDeletesAreUnaffected(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(200, [], json_encode(['id' => '123'])),
            new Response(204),
        ], $history);
        $strict = $service->withStrictPropertyNames();

        $strict->get('customer', 123);
        $strict->delete('customer', 123);

        self::assertFalse($history[0]['request']->hasHeader('X-NetSuite-PropertyNameValidation'));
        self::assertFalse($history[1]['request']->hasHeader('X-NetSuite-PropertyNameValidation'));
    }

    public function testWithStrictPropertyNamesLeavesTheOriginalInstanceUnchanged(): void
    {
        $history = [];
        $service = $this->newService([
            new Response(204, ['Location' => self::BASE_URL . '/customer/647']),
        ], $history);

        $strict = $service->withStrictPropertyNames();
        $service->create('customer', ['companyName' => 'Acme']);

        self::assertNotSame($service, $strict);
        self::assertFalse($history[0]['request']->hasHeader('X-NetSuite-PropertyNameValidation'));
    }
}
