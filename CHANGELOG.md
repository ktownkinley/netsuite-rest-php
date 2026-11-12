# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows
[Semantic Versioning](https://semver.org/). While the version is below 1.0.0, a minor release
(0.x → 0.y) may contain breaking changes.

## [0.3.0] - 2026-11-12

### Added

- `RecordService::withStrictPropertyNames()` returns a copy of the service whose writes
  (`create()`, `update()`, `updateByExternalId()`, `upsert()`) send
  `X-NetSuite-PropertyNameValidation: Error`, so NetSuite rejects a body field that doesn't
  exist on the record with an `ApiException` instead of silently dropping it. Opt-in: the
  default behavior is unchanged.

## [0.2.1] - 2026-11-11

### Changed

- `ext-gmp` is no longer a Composer requirement. PS256 signing now needs either `ext-gmp` or
  `ext-bcmath` (both listed under `suggest`); `ext-bcmath` signs a 4096-bit key in ~0.3 s on
  PHP 8.4+ (~12 s on PHP 8.2 / 8.3). This lets the package install on runtimes that ship
  bcmath but not gmp.

### Added

- Before signing a client assertion, authentication now throws an `AuthenticationException`
  naming the missing extensions when neither `ext-gmp` nor `ext-bcmath` is loaded, instead of
  stalling for minutes and failing with NetSuite's bare `invalid_grant`.

## [0.2.0] - 2026-10-19

First public release.

### Added

- `NetSuite` facade as the single consumer entry point.
- `NetSuiteConfig` for consumer-supplied configuration.
- OAuth 2.0 client-credentials (machine-to-machine) authentication with PS256-signed JWT
  client assertions.
- SuiteQL queries with automatic and manual pagination and bound parameters.
- Record API: single-record create, read, update, delete, and upsert by external ID.
- A predictable exception hierarchy under `NetSuiteRest\Exception`.
