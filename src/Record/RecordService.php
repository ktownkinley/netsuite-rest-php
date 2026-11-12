<?php

namespace NetSuiteRest\Record;

use NetSuiteRest\Exception\UnexpectedResponseException;
use NetSuiteRest\Http\NetSuiteResponse;
use NetSuiteRest\Http\NetSuiteRestClient;

/**
 * Executes NetSuite Record API operations: single-record create, read, update, delete,
 * upsert-by-external-ID, and transform (creating one record from another).
 *
 * This knows the Record API's specific contract — the `record/v1/{recordType}/{id}` URL
 * shape, the `eid:` external-ID addressing scheme, and how a written record's internal ID is
 * reported via the `Location` header — but nothing about HTTP transport, authentication, or
 * any other NetSuite domain. That lives in {@see NetSuiteRestClient} below it and other
 * services beside it.
 *
 * Deliberately scoped to a record as the unit of work. NetSuite's own collection endpoint
 * (`GET record/v1/{recordType}`) returns links rather than record bodies, so using it for
 * datasets would just reintroduce the fetch-IDs-then-fetch-each-record pattern this library
 * is meant to move away from — use SuiteQL for datasets instead.
 *
 * By default NetSuite silently ignores body fields that don't exist on the record, so a
 * misspelled or missing field is dropped without any error. {@see self::withStrictPropertyNames()}
 * opts in to having NetSuite reject those writes instead.
 */
final class RecordService
{
    private const RESOURCE_PATH = 'record/v1';

    private const PROPERTY_NAME_VALIDATION_HEADER = 'X-NetSuite-PropertyNameValidation';

    /**
     * Not readonly only so {@see self::withStrictPropertyNames()} can set it on a clone; nothing
     * changes it on an existing instance.
     */
    private bool $strictPropertyNames = false;

    public function __construct(private readonly NetSuiteRestClient $client)
    {
    }

    /**
     * Returns a copy whose writes (`create()`, `update()`, `updateByExternalId()`, `upsert()`,
     * `transform()`, `transformByExternalId()`)
     * ask NetSuite to reject any body field that doesn't exist on the record, instead of
     * silently ignoring it. A rejected write fails with an `ApiException` (400, "Unknown field
     * name ...") and changes nothing. Reads and deletes are unaffected.
     *
     * This instance is left unchanged, so code holding it keeps NetSuite's default behavior.
     */
    public function withStrictPropertyNames(): self
    {
        $copy = clone $this;
        $copy->strictPropertyNames = true;

        return $copy;
    }

    /**
     * @param string[] $fields Body field names to return. Empty returns all body fields.
     *                         Sublist/subrecord fields cannot be selected this way.
     * @param bool $expandSubResources Inline sublists/subrecords instead of returning them as
     *                                 links — NetSuite does not expand them by default.
     * @return array<string, mixed>
     */
    public function get(string $recordType, int $id, array $fields = [], bool $expandSubResources = false): array
    {
        return $this->getByPath($this->path($recordType, (string) $id), $fields, $expandSubResources);
    }

    /**
     * @param string[] $fields Body field names to return. Empty returns all body fields.
     * @return array<string, mixed>
     */
    public function getByExternalId(
        string $recordType,
        string $externalId,
        array $fields = [],
        bool $expandSubResources = false,
    ): array {
        return $this->getByPath($this->path($recordType, $this->eid($externalId)), $fields, $expandSubResources);
    }

    /**
     * Creates a new record and returns its NetSuite-assigned internal ID.
     *
     * @param array<string, mixed> $body Must include every field NetSuite requires for this
     *                                   record type; the `id` field is server-assigned and
     *                                   cannot be set here.
     */
    public function create(string $recordType, array $body): string
    {
        $response = $this->client->post($this->basePath($recordType), $body, [], $this->writeHeaders());

        return $this->idFromLocation($response);
    }

    /**
     * Partially updates a record. Fields omitted from `$body` are left unchanged.
     *
     * @param array<string, mixed> $body
     */
    public function update(string $recordType, int $id, array $body): void
    {
        $this->client->patch($this->path($recordType, (string) $id), $body, [], $this->writeHeaders());
    }

    /**
     * @param array<string, mixed> $body
     */
    public function updateByExternalId(string $recordType, string $externalId, array $body): void
    {
        $this->client->patch($this->path($recordType, $this->eid($externalId)), $body, [], $this->writeHeaders());
    }

    public function delete(string $recordType, int $id): void
    {
        $this->client->delete($this->path($recordType, (string) $id));
    }

    public function deleteByExternalId(string $recordType, string $externalId): void
    {
        $this->client->delete($this->path($recordType, $this->eid($externalId)));
    }

    /**
     * Creates a record if `$externalId` doesn't already exist, or updates it if it does —
     * in a single call, with no existence check required beforehand. Returns the affected
     * record's internal ID either way: NetSuite's response is otherwise identical for both
     * outcomes (204, no body), so nothing in it distinguishes a create from an update.
     *
     * @param array<string, mixed> $body
     */
    public function upsert(string $recordType, string $externalId, array $body): string
    {
        $response = $this->client->put($this->path($recordType, $this->eid($externalId)), $body, [], $this->writeHeaders());

        return $this->idFromLocation($response);
    }

    /**
     * Creates a new record of `$toType` from an existing record — NetSuite's "transform", e.g. a sales
     * order into an item fulfillment, invoice or return authorization — and returns the new record's
     * internal ID.
     *
     * NetSuite fills the new record from the source the same way its UI does: body fields, the link back
     * to the source (`createdFrom`), and by default every line of the source's sublists. `$body` adds or
     * overrides fields on top of that. To keep only some lines, name the sublist in `$replaceSublists`
     * and list the lines to keep in `$body`; for a sales order that is
     * `['item' => ['items' => [['orderLine' => 2, 'quantity' => 1]]]]` with `$replaceSublists = ['item']`.
     * A transform is a write, so {@see self::withStrictPropertyNames()} applies to it.
     *
     * @param array<string, mixed> $body Fields to set on the new record; may be empty.
     * @param string[] $replaceSublists Sublists (e.g. `item`) whose lines `$body` replaces entirely,
     *                                  rather than the lines NetSuite copied from the source.
     */
    public function transform(
        string $fromType,
        int $id,
        string $toType,
        array $body = [],
        array $replaceSublists = [],
    ): string {
        return $this->transformPath($this->path($fromType, (string) $id), $toType, $body, $replaceSublists);
    }

    /**
     * {@see self::transform()}, with the source record addressed by its external ID.
     *
     * @param array<string, mixed> $body
     * @param string[] $replaceSublists
     */
    public function transformByExternalId(
        string $fromType,
        string $externalId,
        string $toType,
        array $body = [],
        array $replaceSublists = [],
    ): string {
        return $this->transformPath($this->path($fromType, $this->eid($externalId)), $toType, $body, $replaceSublists);
    }

    /**
     * @param array<string, mixed> $body
     * @param string[] $replaceSublists
     */
    private function transformPath(string $sourcePath, string $toType, array $body, array $replaceSublists): string
    {
        foreach ($replaceSublists as $sublist) {
            if (!is_string($sublist) || $sublist === '') {
                throw new \InvalidArgumentException('Sublist names to replace must be non-empty strings.');
            }
        }
        $query = $replaceSublists === [] ? [] : ['replace' => implode(',', $replaceSublists)];

        $response = $this->client->post($sourcePath . '/!transform/' . $toType, $body, $query, $this->writeHeaders());

        return $this->idFromLocation($response);
    }

    /**
     * @return array<string, string>
     */
    private function writeHeaders(): array
    {
        return $this->strictPropertyNames ? [self::PROPERTY_NAME_VALIDATION_HEADER => 'Error'] : [];
    }

    /**
     * @param string[] $fields
     * @return array<string, mixed>
     */
    private function getByPath(string $path, array $fields, bool $expandSubResources): array
    {
        $query = [];
        if ($fields !== []) {
            $query['fields'] = implode(',', $fields);
        }
        if ($expandSubResources) {
            $query['expandSubResources'] = 'true';
        }

        $response = $this->client->get($path, $query);
        $body = $response->json();

        if ($body === null) {
            throw new UnexpectedResponseException(
                'Expected a NetSuite record body in the response, but none was present.',
                $response,
            );
        }

        return $body;
    }

    /**
     * Parses the affected record's internal ID from the `Location` header NetSuite returns
     * on a successful create/upsert. A 2xx response that doesn't actually carry a usable ID
     * this way is treated as unexpected rather than silently returning an empty or null
     * value — this library has no other way to report the ID, so failing loudly here is
     * more honest than inventing a guarantee NetSuite didn't keep.
     */
    private function idFromLocation(NetSuiteResponse $response): string
    {
        $location = $response->getHeader('Location');
        $path = $location !== null ? parse_url($location, PHP_URL_PATH) : null;
        // Intentionally not filtering out empty segments: a trailing slash (e.g. a Location
        // ending in ".../customer/") must leave the last segment empty, not fall through to
        // "customer" as a false-positive ID.
        $segments = is_string($path) ? explode('/', $path) : [];
        $id = $segments === [] ? null : end($segments);

        if ($id === null || $id === '') {
            throw new UnexpectedResponseException(
                'Expected the response to identify the affected record via a usable Location header, '
                . 'but none was found.',
                $response,
            );
        }

        return $id;
    }

    private function basePath(string $recordType): string
    {
        return self::RESOURCE_PATH . '/' . $recordType;
    }

    private function path(string $recordType, string $idSegment): string
    {
        return $this->basePath($recordType) . '/' . $idSegment;
    }

    /**
     * `eid:` addressing requires the external ID to arrive as a single path component.
     * Percent-encoding it is what keeps that true: an un-encoded `/` would silently split
     * into an extra path segment, and `?`/`#` would be parsed as the start of a query
     * string/fragment and silently truncate the external ID instead of raising any error.
     */
    private function eid(string $externalId): string
    {
        return 'eid:' . rawurlencode($externalId);
    }
}
