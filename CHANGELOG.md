# Changelog

All notable changes to this project are documented in this file.

## Unreleased

- Hardened API auth defaults with explicit security env flags and fail-closed production behavior.
- Disabled query-token auth by default (`PLUGIN_AGENTS_ALLOW_QUERY_TOKEN=false`).
- Added scope-aware authorization for guarded endpoints, including sensitive order and non-live entry controls.
- Added email redaction control for non-sensitive order access (`PLUGIN_AGENTS_REDACT_EMAIL`).
- Reworked rate limiting with pre-auth throttling and atomic counter strategy with mutex fallback.
- Updated capabilities/OpenAPI auth metadata to reflect runtime token transport configuration.
- Added startup security warnings for insecure or misconfigured modes.
- Added public discovery routes for `GET /llms.txt` and `GET /commerce.txt`.
- Added config-driven discovery text generation with plugin settings overrides via `config/agents.php`.
- Added discovery text caching with `ETag`/`Last-Modified` support and `304` responses.
- Added automatic cache invalidation on entry/product/variant save/delete events.
- Added CLI prewarm command: `craft agents/discovery-prewarm`.

## 0.1.1 - 2026-02-20

- Initial public release of `klick/agents`.
- Added read-only HTTP API endpoints for products, orders, entries, sections, readiness, and health.
- Added discoverability endpoints: `/agents/v1/capabilities` and `/agents/v1/openapi.json`.
- Added read-only CLI commands under `craft agents/*`.
- Added control panel section for plugin dashboard and health views.
