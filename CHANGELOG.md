# Changelog

All notable changes to this project are documented in this file.

## Unreleased

## 0.3.0 - 2026-02-26

- Added Control Panel credential lifecycle foundation: managed credential create/edit scopes/rotate/revoke/delete flows and one-time token reveal UX.
- Added managed credential persistence with runtime auth integration and last-used metadata tracking (`lastUsedAt`, `lastUsedIp`, auth method).
- Added permission-granular credential actions (`view`, `manage`, `rotate`, `revoke`, `delete`) and updated CP/docs posture to reflect hybrid env + managed credential support.

## 0.2.0 - 2026-02-26

- Promoted incremental sync capabilities to the `v0.2.0` minor baseline.
- Finalized deterministic `cursor`/`updatedSince` continuation behavior on `/products`, `/orders`, `/entries`, and `/changes`.
- Finalized queued webhook delivery with `X-Agents-Webhook-Signature` (`HMAC-SHA256`) and bounded retry semantics.
- Completed OpenAPI/capabilities alignment and integrated incremental/webhook regression harnesses into release gating.

## 0.1.4 - 2026-02-26

- Hardened incremental request validation on `/products` to return deterministic `400 INVALID_REQUEST` for malformed `cursor`/`updatedSince` inputs.
- Tightened credential parsing in `SecurityPolicyService` to accept only credential-object shapes and ignore malformed scalar entries.
- Added explicit `Cache-Control: no-store, private` headers for guarded JSON and API error responses.
- Normalized order change snapshots in `/changes` to use `updatedAt` consistently.
- Expanded OpenAPI route response metadata to include guarded error outcomes (`401`/`403`/`429`/`503`) across protected endpoints.
- Added incremental and webhook regression harnesses and integrated them into the release gate workflow.
- Added release validation evidence and handoff checklist updates for maintainers.

## 0.1.3 - 2026-02-25

- Added request correlation IDs on API responses via `X-Request-Id`.
- Standardized error response schema with stable error codes and per-response `requestId`/`status`.
- Added capabilities/OpenAPI error taxonomy metadata for integration clients.
- Added `INCREMENTAL_SYNC_CONTRACT.md` defining `cursor`/`updatedSince`, ordering, replay, and tombstone semantics for `v0.2.0`.
- Added incremental sync filters to `/products`, `/orders`, and `/entries` with cursor precedence, deterministic ordering, and snapshot-window continuation metadata.
- Added `GET /agents/v1/changes` unified feed with normalized `created|updated|deleted` items, deterministic checkpoint continuation, and tombstones from soft-deleted records.
- Added optional webhook delivery for `product|order|entry` change events with queued retries and `X-Agents-Webhook-Signature` HMAC verification headers.
- Added CP cockpit IA v1 with 4 deep-linkable tabs: `overview`, `readiness`, `discovery`, and `security` (legacy `agents/dashboard` + `agents/health` aliases retained).
- Added shared `SecurityPolicyService` for effective auth/rate-limit/redaction/webhook posture across API, CP security view, and plugin startup warnings.
- Added discovery operator controls in CP (`prewarm all|llms|commerce`, clear cache) with read-only discovery metadata/previews.
- Fixed CP template resolution using Craft plugin-handle template conventions (`agents/*` in CP mode).

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
