# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows
[Semantic Versioning](https://semver.org/). While the version is below 1.0.0, a minor release
(0.x → 0.y) may contain breaking changes.

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
