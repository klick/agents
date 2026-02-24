# Changelog

All notable changes to this project are documented in this file.

## Unreleased

- No unreleased changes yet.

## 0.1.2 - 2026-02-24

- Hardened API auth defaults with explicit production fail-closed behavior.
- Disabled query-token auth by default and clarified token transport metadata.
- Added scope-aware authorization for sensitive order and non-live entry access.
- Added discovery text generation for `/llms.txt` and `/commerce.txt` with cache + `ETag`/`Last-Modified` behavior.
- Added cache invalidation hooks and CLI prewarm command: `craft agents/discovery-prewarm`.
- Improved rate-limiting strategy with pre-auth throttling and atomic counter fallback.

## 0.1.1 - 2026-02-20

- Initial public release of `klick/agents`.
- Added read-only HTTP API endpoints for products, orders, entries, sections, readiness, and health.
- Added discoverability endpoints: `/agents/v1/capabilities` and `/agents/v1/openapi.json`.
- Added read-only CLI commands under `craft agents/*`.
- Added control panel section for plugin dashboard and health views.
