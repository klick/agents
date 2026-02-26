---
title: Changelog
---

# Changelog

## Unreleased

## 0.3.0 (2026-02-26)

- Added CP credential lifecycle foundation (managed credential create/edit scopes/rotate/revoke/delete).
- Added one-time token reveal UX and last-used metadata visibility in the CP.
- Added permission-granular credential actions (`view`, `manage`, `rotate`, `revoke`, `delete`).
- Added runtime auth support for CP-managed credentials alongside env credentials.

## 0.2.0 (2026-02-26)

- Promoted incremental sync capabilities to the `v0.2.0` minor release baseline.
- Finalized deterministic continuation semantics for `/products`, `/orders`, `/entries`, and `/changes`.
- Finalized signed webhook delivery with queue retries and documented verification details.
- Completed OpenAPI/capabilities/docs parity and release-gate regression integration.

## 0.1.4 (2026-02-26)

- Hardened incremental request validation behavior on `/products`.
- Added no-store cache headers for guarded API and guarded error responses.
- Expanded OpenAPI guarded error metadata and regression harness integration.

## 0.1.3 (2026-02-25)

- Added request correlation IDs and standardized error schema behavior.
- Added incremental sync enhancements and unified `/changes` feed.
- Added optional signed webhook delivery and queue retry behavior.
- Added CP cockpit IA v1 with 4 tabs:
  - overview
  - readiness
  - discovery
  - security
- Added shared `SecurityPolicyService` for API/CP/startup warning parity.
- Added discovery operator controls and metadata previews.
- Fixed CP template resolution to follow Craft plugin conventions.

## 0.1.2 (2026-02-24)

- Hardened auth defaults and fail-closed behavior.
- Added discovery docs generation and cache invalidation hooks.
- Improved rate limiting strategy.

## 0.1.1 (2026-02-20)

- Initial release with read-only API, discoverability endpoints, and CLI commands.

For full source-of-truth release history, see `CHANGELOG.md` in the repository root.
