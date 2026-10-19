# NetSuite REST PHP

A standalone PHP library for interacting with NetSuite's REST APIs — OAuth 2.0
machine-to-machine (M2M) authentication, SuiteQL, and the Record API today.

Independent of Magento or any other framework; anything under `src/` is usable by any PHP
application.

## Project status

This package is pre-1.0 and its public API may still change based on real usage.

**Implemented today:**

* Configuration (`NetSuiteConfig`)
* OAuth 2.0 client-credentials (machine-to-machine) authentication, verified against a real
  NetSuite sandbox
* An authenticated REST transport (`NetSuiteRestClient`)
* SuiteQL (`SuiteQLService`), including automatic and manual pagination
* The Record API (`RecordService`) — single-record create/read/update/delete and
  upsert-by-external-ID, verified against a real NetSuite sandbox
* A consumer-facing facade (`NetSuite`) composing all of the above into a single entry point —
  see [Quick Start](#quick-start)

## Requirements

* PHP 8.2+
* **`ext-gmp`** — hard requirement, see below
* Composer

### Why `ext-gmp` is required

NetSuite's OAuth 2.0 client-credentials grant requires a JWT client assertion signed with
**PS256** (RSA-PSS), using an RSA key of at least 3072 bits (this README's setup example
uses 4096). Without a fast big-integer extension, `web-token/jwt-library` falls back to
pure-PHP arbitrary-precision arithmetic for RSA-PSS signing — and for a key this large, that
fallback is slow enough to break correctness, not just performance.

Measured on a real 4096-bit key: **~7 minutes to sign without `ext-gmp`, versus ~8
milliseconds with it.** NetSuite's client assertion must carry a short lifetime (this library
uses 60 seconds), so without `ext-gmp`, signing alone can outlast that window — a valid
assertion arrives already expired, and NetSuite reports it as a bare `invalid_grant` with no
hint that the real cause is signing latency rather than misconfiguration.

Lengthening the assertion's lifetime would just paper over slow signing rather than fix it,
so instead this package requires `ext-gmp` up front: `composer install` fails loudly on an
unsupported runtime instead of silently installing into an environment where authentication
is effectively broken. (`ext-bcmath` is a slower alternative some environments already have,
but `ext-gmp` is the one actually required here.)

## Installation

```
composer require mckinleycard/netsuite-rest-php
```

The package is pre-1.0: a minor release (`0.2` → `0.3`) may include breaking changes, so
Composer's default `^0.x` constraint is the right one — it accepts bug-fix releases but not
the next minor. See [`CHANGELOG.md`](CHANGELOG.md) for what changed between versions.

## NetSuite OAuth 2.0 M2M setup

This library authenticates using NetSuite's OAuth 2.0 client-credentials (machine-to-machine)
grant with a certificate-based JWT client assertion (PS256). Setting this up in NetSuite
takes several distinct steps, each in a different part of the UI.

### 1. Enable the required features

**Setup → Company → Enable Features → SuiteCloud tab:**

* **REST Web Services** — required to call any NetSuite REST endpoint at all (SuiteQL, the
  Record API, etc).
* **OAuth 2.0** — required separately from REST Web Services; this is what makes OAuth 2.0
  grants (including client credentials) available on Integration records.

These are two distinct checkboxes controlling two distinct things. Enabling only one leaves
you either unable to authenticate, or authenticated but unable to call the REST API at all.
(Exact checkbox wording can shift slightly between NetSuite versions — confirm against your
account's Enable Features page.)

### 2. Create an Integration record

**Setup → Integration → Manage Integrations → New:**

* Name it, set State to Enabled.
* On the **Authentication** subtab, check **Client Credentials (Machine to Machine) Grant**.
* Select scopes — for this package today, that's **REST Web Services**
  (`rest_webservices`); see [Scopes](#5-scopes) below.
* Save.

NetSuite displays a **Client ID** and **Client Secret** exactly once, immediately after
saving — neither can be retrieved again later. **Record the Client ID** — it becomes
`NetSuiteConfig::$clientId` and the JWT's `iss` claim.

This library's certificate-based client-credentials implementation **does not use the
Client Secret** — no code path in this package reads it.

### 3. Generate a certificate

This library signs the client assertion with **PS256 (RSA-PSS)**. NetSuite permits RSA keys
of 3072 or 4096 bits for this; use 4096:

```
openssl req -x509 -newkey rsa:4096 -keyout private.pem -out public.pem -days 365 -nodes
```

* `private.pem` stays with your application. It becomes `NetSuiteConfig::$privateKey` and
  must **never** be committed to source control or uploaded to NetSuite.
* `public.pem` is the only part NetSuite ever sees — it's uploaded in the next step.

### 4. Map the certificate to an Entity, Role, and Integration

**Setup → Integration → Manage Authentication → OAuth 2.0 Client Credentials (M2M) Setup →
Create New:**

* **Entity** — the employee record this integration authenticates as.
* **Role** — the role that entity uses for this integration; it needs whatever permissions
  your queries/records actually touch.
* **Application** — the Integration record from step 2 (only selectable if Client
  Credentials (M2M) Grant is checked on it).
* Upload `public.pem`.
* Save. NetSuite assigns a **Certificate ID** — this becomes `NetSuiteConfig::$certificateId`
  and is sent as the JWT header's `kid`.

> **This mapping is environment-specific.** A mapping created in one account (e.g.
> production) does not carry over to another (e.g. sandbox) — each account needs its own
> Entity/Role/Integration/certificate mapping configured separately. **Sandbox refreshes
> clear this mapping entirely**, so refreshing a sandbox will require redoing this step.

### 5. Scopes

`NetSuiteConfig::$scopes` must match what's enabled on the Integration record. For everything
this package currently does (SuiteQL over REST, and any direct `NetSuiteRestClient` calls),
that's:

```php
scopes: ['rest_webservices']
```

`restlets` is only relevant if you're also calling NetSuite RESTlets (script-based custom
endpoints) — nothing in this package requires it. `suite_analytics` is a separate NetSuite
feature (SuiteAnalytics Connect, a JDBC/ODBC connection) and is **not** required for SuiteQL
over REST, which is what this package uses — don't enable it on the Integration record just
to run SuiteQL.

### 6. The account-specific REST URL

`NetSuiteConfig::$restApiBaseUrl` must be the exact REST domain NetSuite gives your account,
e.g. `https://1234567-sb1.suitetalk.api.netsuite.com`. Don't construct this from the account
ID yourself — not every account follows that pattern, and this library deliberately never
tries to derive it for you.

## Quick Start

```php
use NetSuiteRest\Config\NetSuiteConfig;
use NetSuiteRest\NetSuite;

$config = new NetSuiteConfig(
    clientId: '<Client ID from the Integration record>',
    certificateId: '<Certificate ID from OAuth 2.0 Client Credentials (M2M) Setup>',
    privateKey: file_get_contents('/path/to/private.pem'),
    restApiBaseUrl: 'https://1234567-sb1.suitetalk.api.netsuite.com',
    scopes: ['rest_webservices'],
);

$netsuite = new NetSuite($config);

foreach ($netsuite->suiteQL()->query('SELECT id, companyname FROM customer WHERE id = ?', [42]) as $row) {
    echo $row['companyname'], PHP_EOL;
}

$customer = $netsuite->records()->get('customer', 42);
```

`NetSuite` owns OAuth 2.0 authentication, JWT signing, token caching, and the HTTP transport
internally. `suiteQL()` and `records()` return the exact same `SuiteQLService`/`RecordService`
documented below — just already wired to one shared, authenticated client — so everything in
the [SuiteQL](#suiteql) and [Record API](#record-api) sections applies unchanged whether you
reach them through `NetSuite` or construct them yourself (see
[Advanced: manual construction](#advanced-manual-construction)).

### Customizing the HTTP client or authentication

`NetSuite`'s constructor takes two optional arguments beyond `$config`, and no others — this is
a deliberately fixed, small set of customization points, not a general options mechanism:

```php
new NetSuite($config, $myGuzzleClient);                   // custom middleware, proxy, timeouts, logging, etc.
new NetSuite($config, $myGuzzleClient, $myTokenProvider);  // e.g. a token provider backed by a shared cache
```

Supplying a custom `$httpClient` alone is enough on its own — the default
`OAuth2ClientCredentialsProvider` is built against whichever client you pass, not a second,
separately constructed one. Supplying a custom `$tokenProvider` (anything implementing
`AccessTokenProviderInterface`) replaces authentication entirely, independent of the HTTP
client.

By default (no `$httpClient` supplied), the client `NetSuite` builds itself sets
`connect_timeout: 15` and `timeout: 120` seconds — Guzzle's own defaults otherwise allow a
request to hang indefinitely. This bounds a single request (one token acquisition, or one
SuiteQL/Record API call); it has no bearing on how long a multi-page `query()` iteration takes
overall, since each page fetched while iterating is its own separate HTTP request. Supplying
your own `$httpClient` replaces these defaults entirely — the package applies no timeout policy
on top of an injected client, so your own settings (including no timeout at all) are respected
exactly as configured.

### Escape hatch: `NetSuite::client()`

```php
$netsuite->client()->get('record/v1/customer/123/!transform/salesOrder');
```

Returns the same authenticated `NetSuiteRestClient` instance `suiteQL()`/`records()` are built
on, for anything they don't model — e.g. the Record API options
[deliberately left out](#options-kept-out-of-this-version) of `RecordService` — without
constructing a second, separate transport by hand.

## Configuration reference

`NetSuiteConfig` is a plain, framework-agnostic value object — supply values from wherever
your application keeps configuration (environment variables, a secrets manager, etc.); this
library doesn't read any of that itself.

| Constructor argument | What it is |
|---|---|
| `clientId` | The Client ID from the Integration record (setup step 2). Sent as the JWT `iss` claim. |
| `certificateId` | The Certificate ID from OAuth 2.0 Client Credentials (M2M) Setup (setup step 4). Sent as the JWT `kid`. |
| `privateKey` | PEM-encoded RSA private key **content** — not a file path. Read the file yourself before constructing. |
| `restApiBaseUrl` | The account-specific REST base URL (setup step 6). |
| `scopes` | Array of OAuth 2.0 scopes matching the Integration record, e.g. `['rest_webservices']`. |

## SuiteQL

`SuiteQLService` wraps NetSuite's SuiteQL REST endpoint (`query/v1/suiteql`). It has two
methods.

### `query()` — the normal way to run a query

```php
foreach ($suiteql->query('SELECT id, companyname FROM customer WHERE id = ?', [42]) as $row) {
    // $row is an associative array, exactly as NetSuite returned it — no renaming, no coercion
}
```

* Automatically pages through NetSuite's REST pagination for you, at a default page size of
  1000.
* **Pagination is offset-based**, not keyset-based — NetSuite doesn't guarantee stable relative
  row order across separate page requests unless your query specifies one. Add an `ORDER BY`
  (e.g. on the table's id) to any query you page through, the same as you would for any
  offset-paginated SQL API.
* **Lazy**: calling `query()` does no HTTP work by itself. It returns an `iterable`, and
  requests are only made as you iterate it, one page at a time. If your query is invalid, or
  NetSuite is unreachable, the resulting exception is thrown **during iteration**, not at the
  point you called `query()`.
* **Single-pass**: the returned iterable is single-use — once it's been iterated, it's spent
  and isn't reusable. If you need the results more than once, or want every page fetched up
  front rather than interleaved with your own per-row processing (slow row-by-row work
  otherwise delays later page requests until your processing catches up), collect them
  yourself first: `$rows = iterator_to_array($suiteql->query(...))`. This only narrows the
  window between page requests — it does not give you snapshot isolation or transactional
  consistency; NetSuite can still change the underlying data between any two page requests
  either way.
* Bound parameters (below) are the second argument; an optional third argument overrides the
  page size (default 1000).

### `queryPage()` — manual pagination

```php
$page = $suiteql->queryPage('SELECT id, companyname FROM customer', limit: 50, offset: 0);

$page['items'];        // this page's rows
$page['count'];        // how many rows this page actually returned
$page['totalResults']; // total rows matching the query, across all pages
$page['hasMore'];      // whether another page exists
```

Use this when you want direct control over paging (e.g. a paged UI), or want `totalResults`
up front without fetching everything. `query()` is built on top of this internally.

### Bound parameters

SuiteQL supports anonymous `?` placeholders, bound via the `$params` argument:

```php
$suiteql->query('SELECT * FROM transaction WHERE id BETWEEN ? AND ?', [100, 200]);
```

Prefer this over interpolating values into the SQL string yourself — exactly as with any
other SQL API, string interpolation invites SQL injection the moment any part of the query
comes from outside your own code.

### Row shape

Rows from `query()`/`queryPage()` are passed through exactly as NetSuite's SuiteQL endpoint
returns them — no renaming, no coercion, nothing stripped:

* Expect scalar values to commonly arrive as strings. NetSuite's SuiteQL endpoint returns
  values such as IDs, quantities, and prices as JSON strings in normal query results. The
  package does not coerce them into PHP numeric or boolean types; cast values explicitly when
  your application requires a specific type.
* **A column whose value is `null` may be absent from the row entirely**, rather than present
  with a `null` value. Check with `array_key_exists()`/`??`, not an assumption that every
  selected column is always a key in every row.
* **Rows include NetSuite's own `links` entry.** This package doesn't strip it — ignore or
  `unset()` the key yourself if you don't need it.

## Record API

`RecordService` wraps NetSuite's Record API (`record/v1/{recordType}/...`) for single-record
operations. It is deliberately scoped to a record as the unit of work — NetSuite's own
collection endpoint (`GET record/v1/{recordType}`) returns links rather than record bodies, so
using it for datasets would just reintroduce the fetch-IDs-then-fetch-each-record pattern this
library is meant to move away from. Use [SuiteQL](#suiteql) for datasets; use `RecordService`
when you already know which single record you need, or are writing one.

```php
// Read (internal ID or external ID)
$customer = $records->get('customer', 42);
$customer = $records->get('customer', 42, fields: ['companyName', 'email']);
$customer = $records->get('customer', 42, expandSubResources: true); // inline sublists/subrecords
$customer = $records->getByExternalId('customer', 'SKU-001');

// Create — returns the new record's NetSuite-assigned internal ID
$id = $records->create('customer', ['companyName' => 'Acme, Inc.', 'subsidiary' => ['id' => 1]]);

// Update — a partial update; omitted fields are left unchanged
$records->update('customer', 42, ['companyName' => 'Acme Updated']);
$records->updateByExternalId('customer', 'SKU-001', ['companyName' => 'Acme Updated']);

// Delete
$records->delete('customer', 42);
$records->deleteByExternalId('customer', 'SKU-001');

// Upsert — create if externalId doesn't exist yet, update if it does; one call, no
// existence check needed beforehand. Returns the affected record's internal ID either way.
$id = $records->upsert('customer', 'SKU-001', ['companyName' => 'Acme, Inc.']);
```

### Internal IDs vs. external IDs

NetSuite internal IDs are system-assigned, sequential integers — never alphanumeric — so
every method taking one types it as `int`. External IDs are caller-assigned, can be
alphanumeric, and stay stable across environments in a way internal IDs don't. The two are
different NetSuite addressing schemes, not two spellings of the same thing, so they're
exposed as explicitly separate methods (`get()` vs. `getByExternalId()`, etc.) rather than one
method accepting either — a loosely-typed `int|string $id` would let a numeric-looking
external ID silently get misread as an internal one.

`upsert()` only exists for external IDs: NetSuite requires an external ID to upsert by (there
is no "upsert by internal ID", since you can't invent an internal ID for a record that doesn't
exist yet).

### `create()` and `upsert()` return the new/affected record's internal ID

NetSuite reports this via the `Location` response header, not the (empty) response body — this
is the only way the library can report it back to you. If a successful response doesn't
actually carry a usable ID this way, `create()`/`upsert()` throw `UnexpectedResponseException`
rather than returning an empty or null value — there's no honest fallback value to invent
there.

`update()`, `updateByExternalId()`, `delete()`, and `deleteByExternalId()` return nothing:
NetSuite's `Location` header on those just echoes the ID you already supplied, so there's
nothing new to hand back.

Note that `upsert()`'s response is identical either way (`204`, same `Location`) whether it
created or updated the record — NetSuite doesn't report which branch it took, and this library
has no way to recover that information either.

### Options kept out of this version

NetSuite's Record API also supports `replaceSelectedFields` (controlling whether a `null`
field in a PATCH body deletes that field) and `replace` (wholesale sublist replacement, with
different merge semantics for keyed vs. non-keyed sublists). Neither is exposed on
`RecordService` yet — their exact combined behavior isn't fully confirmed, and nothing in this
library's current use cases needs them. They're still reachable directly via
`NetSuiteRestClient::patch(..., query: ['replaceSelectedFields' => 'true', 'replace' => 'item'])`
if you need them now.

## Error handling

Every exception this package throws extends `NetSuiteRest\Exception\NetSuiteException`, so
catching that is always a safe catch-all. Beyond that:

| Exception | Means |
|---|---|
| `AuthenticationException` | Acquiring or refreshing an access token failed (bad credentials, unreachable token endpoint, malformed token response). |
| `ApiException` | NetSuite received the request and returned a non-success HTTP status (400/403/404/429/5xx/etc). Carries the status code and, when present, NetSuite's parsed error details (`getType()`, `getTitle()`, `getErrorDetails()`). |
| `TransportException` | A valid HTTP response was not received from NetSuite at all — DNS failure, connection failure, TLS problem, timeout. |
| `UnexpectedResponseException` | NetSuite returned a response the library considers successful, but its body couldn't be interpreted as expected (e.g. non-JSON where JSON was required). |

`ApiException::getMessage()` includes NetSuite's own diagnostic text when one is available —
specifically, the first `o:errorDetails[].detail` entry NetSuite returned, falling back to the
generic `title` when no detail is present. `getErrorDetails()` remains the complete-access path
for every detail NetSuite returned, not just the one used in the message.

None of these ever let a raw Guzzle exception escape to your code.

### Retry behavior

`NetSuiteRestClient` performs exactly one authentication replay: if a request gets a `401`,
it invalidates the cached access token, acquires a new one, and retries the *same* request
once. If that retry also returns `401`, it becomes a normal `ApiException` — there is no
second retry. If acquiring the replacement token itself fails, the resulting
`AuthenticationException` propagates instead.

Nothing else is retried automatically — `429` and `5xx` responses become `ApiException`
immediately, with no backoff. Retry/backoff for those is a deliberately separate,
not-yet-designed concern, particularly since this library will eventually perform NetSuite
transactions, where blind retries aren't safe without idempotency handling.

## Advanced: manual construction

`NetSuite` is pure composition — a small wiring step, not a requirement. Nothing it does is
unavailable by constructing the same pieces yourself:

```php
use GuzzleHttp\Client;
use NetSuiteRest\Auth\OAuth2ClientCredentialsProvider;
use NetSuiteRest\Config\NetSuiteConfig;
use NetSuiteRest\Http\NetSuiteRestClient;
use NetSuiteRest\Record\RecordService;
use NetSuiteRest\SuiteQL\SuiteQLService;

$config = new NetSuiteConfig(/* ... */);
$httpClient = new Client();

$tokenProvider = new OAuth2ClientCredentialsProvider($config, $httpClient);
$restClient = new NetSuiteRestClient($config, $tokenProvider, $httpClient);
$suiteql = new SuiteQLService($restClient);
$records = new RecordService($restClient);
```

This is exactly what `NetSuite`'s constructor does internally. Reach for it directly if you
only want one capability and would rather not construct a `NetSuite` you're not otherwise
using, or if you're composing these classes into something of your own. For most
customization needs (a custom HTTP client, a custom token provider) `NetSuite`'s constructor
already covers it — see [Customizing the HTTP client or authentication](#customizing-the-http-client-or-authentication)
above — so this manual path is for when you want the lower-level classes themselves, not just
non-default behavior from them.

## Lower-level: `NetSuiteRestClient`

`RecordService` and `SuiteQLService` are both built on `NetSuiteRestClient`, which stays
available directly for anything they don't cover (e.g. `RecordService`'s own
[options kept out of this version](#options-kept-out-of-this-version)):

```php
$response = $restClient->get('record/v1/customer/123');
$response->json();                 // decoded body (array), or null for an empty body
$response->getStatusCode();
$response->getHeader('Location');  // e.g. the created resource's URL after a 204 response

$restClient->post('record/v1/customer', ['companyName' => 'Acme, Inc.']);
$restClient->patch('record/v1/salesOrder/100', ['item' => []], query: ['replace' => 'item']);
$restClient->put('record/v1/customer/eid:CID001', ['companyName' => 'Acme, Inc.']);
$restClient->delete('record/v1/customer/123');
```

`get()`/`post()`/`patch()`/`put()`/`delete()` all accept `query` (query-string parameters —
e.g. NetSuite's `replace` parameter for sublist replacement on `PATCH`) and `headers` as named
arguments. Nothing here understands SuiteQL or record-type-specific behavior.

## Testing

```
composer test              # unit + integration
composer test:unit         # no network access required
composer test:integration  # requires real NetSuite sandbox credentials
```

Integration tests need real sandbox credentials, supplied via a local `.env` (gitignored;
copy `.env.example` to start) plus a PEM private key file referenced from it. They are
skipped automatically — not failed — when those aren't present, so `composer test` stays
green without sandbox access. See `.env.example` for the exact variables and
`tests/Integration/SandboxConfig.php` for how they're loaded.

Sandbox integration tests are deliberately bounded — small `limit`s for single-page checks,
and a `WHERE ROWNUM <= n` clause where a test needs to exercise real multi-page pagination —
since a real sandbox account can hold a large amount of real data. Tests that create records
(`RecordServiceSandboxTest`) delete them again before the test ends, successful or not, so
they never leave test data behind in the connected sandbox.

## Development

The project runs as its own Composer project in Docker; the repository is mounted into the
PHP container (see `compose.yaml` / `.docker/php/Dockerfile`, which already installs and
enables `ext-gmp`).

```
docker compose up -d php
docker compose exec php composer install
```

Any CI configuration for this project must provision `ext-gmp` in its runtime image/
environment; `composer install` will fail its platform check otherwise.

## Roadmap

Nothing specific is planned beyond what's documented above. Further capabilities (e.g.
RESTlets, metadata) get added to `NetSuite` only once a real underlying service exists for
them, the same way `SuiteQLService`/`RecordService` did — never speculatively.

## License

Apache-2.0 — see [`LICENSE`](LICENSE) and [`NOTICE`](NOTICE).

To report a security issue, see [`SECURITY.md`](SECURITY.md).
