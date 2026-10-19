<?php

namespace NetSuiteRest\Record;

use NetSuiteRest\Exception\UnexpectedResponseException;
use NetSuiteRest\Http\NetSuiteResponse;
use NetSuiteRest\Http\NetSuiteRestClient;

/**
 * Executes NetSuite Record API operations: single-record create, read, update, delete, and
 * upsert-by-external-ID.
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
 */
final class RecordService
{
    private const RESOURCE_PATH = 'record/v1';

    public function __construct(private readonly NetSuiteRestClient $client)
    {
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
        $response = $this->client->post($this->basePath($recordType), $body);

        return $this->idFromLocation($response);
    }

    /**
     * Partially updates a record. Fields omitted from `$body` are left unchanged.
     *
     * @param array<string, mixed> $body
     */
    public function update(string $recordType, int $id, array $body): void
    {
        $this->client->patch($this->path($recordType, (string) $id), $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function updateByExternalId(string $recordType, string $externalId, array $body): void
    {
        $this->client->patch($this->path($recordType, $this->eid($externalId)), $body);
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
        $response = $this->client->put($this->path($recordType, $this->eid($externalId)), $body);

        return $this->idFromLocation($response);
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
