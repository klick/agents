# Changelog

All notable changes to this project are documented in this file.

## Unreleased

## 0.9.2 - 2026-03-06

### Added

- Added per-agent Owner input in Agents CP create/edit form, with create-mode default prefilled from current CP user email.

### Changed

- Persisted credential owner metadata in managed credential storage and fed it into lifecycle ownership posture (with `.env` metadata-map fallback retained).
- Added migration `m260306_100000_add_credential_owner_column` and regression coverage for owner-field flows.
- Simplified Agents CP cards by removing lifecycle inline warning strips and the lifecycle risk table block.

### Fixed

- Fixed token-authenticated machine POST compatibility by disabling CSRF enforcement on API controller endpoints (including `/agents/v1/consumers/checkpoint`).

## 0.9.1 - 2026-03-06

### Changed

- Hidden lifecycle warning surfaces from the Agents CP view by removing the Lifecycle Governance summary block and per-agent warning strips/risk labels from cards.
- Kept lifecycle governance backend/API/CLI data paths intact so warning UI can be reintroduced without schema or service rollback.
- Updated lifecycle governance QA assertions to reflect the new CP visibility contract.

## 0.9.0 - 2026-03-06

### Added

- Added canonical template catalog service with API endpoint `GET /agents/v1/templates` (`templates:read`) and CLI command `craft agents/template-catalog`.
- Added schema/OpenAPI-linked reference automation docs and JSON fixtures for the three canonical first jobs.
- Added dedicated regression check (`scripts/qa/reference-automations-regression-check.sh`) and integrated it into the release gate.
- Added starter-pack catalog service with API endpoint `GET /agents/v1/starter-packs` (`templates:read`) and CLI command `craft agents/starter-packs` for copy/paste runtime snippets (`curl`, `javascript`, `python`).
- Added integration starter-pack docs at `docs/integration-starter-packs.md`.
- Added reliability threshold evaluation service with read-only triage summaries embedded in `GET /agents/v1/metrics`.
- Added CLI reliability snapshot check (`craft agents/reliability-check`) with strict mode support for CI/operator gates.
- Added dedicated reliability regression check (`scripts/qa/reliability-pack-regression-check.sh`) and integrated it into the release gate.
- Added lifecycle governance service with API endpoint `GET /agents/v1/lifecycle` (`lifecycle:read`) and CLI command `craft agents/lifecycle-report`.
- Added lifecycle governance operator docs (`docs/agent-lifecycle-governance.md`) and VitePress troubleshooting page for ownership/risk posture workflows.
- Added dedicated lifecycle governance regression check (`scripts/qa/lifecycle-governance-regression-check.sh`) and integrated it into the release gate.

### Changed

- Extended capability/openapi/schema contracts to advertise and describe template catalog usage for integrators.
- Enriched diagnostics bundle output with reliability summary/signal snapshots for faster incident triage.
- Enriched diagnostics bundle output with lifecycle status/summary snapshots for ownership and stale-agent triage.
- Updated Dashboard Readiness tab with “Needs Attention Now” triage signals and threshold-driven runbook guidance.
- Updated observability runbook thresholds and response playbooks for reliability signals.
- Updated Agents CP view with lifecycle governance summary cards and per-agent risk factor visibility.
- Hid CP Return Requests tab/routes/permissions by default behind an internal CP-only flag (`PLUGIN_AGENTS_RETURN_REQUESTS_CP_EXPERIMENTAL`) while keeping control-plane API/data internals unchanged.

## 0.8.7 - 2026-03-05

### Changed

- Reordered Control Panel subnavigation to place `Agents` directly below `Dashboard` for faster operator access.

## 0.8.6 - 2026-03-05

### Added

- Added CP runtime setting `enableCredentialUsageIndicator` to toggle live per-agent usage activity indicators on the Agents cards.
- Added managed-agent pause state persistence (`pausedAt`) with migration `m260305_110000_add_credential_pause_column`.
- Added pause/resume lifecycle actions for managed agents in CP and runtime credential filtering.

### Changed

- Reworked the CP `Agents` view from API-key table workflows to card-based agent management with inline create/edit flows.
- Renamed CP navigation and permission copy from API-key terminology to agent terminology.
- Extended managed credential usage tracking to classify read/write operations for activity-state UI feedback.

### Fixed

- Fixed pause/resume action reliability across upgraded installs by handling missing pause-column scenarios safely.
- Fixed live usage indicator behavior to respect settings while still allowing explicit debug simulation via query params.

## 0.8.5 - 2026-03-04

### Added

- Added environment profile resolver with optional `PLUGIN_AGENTS_ENV_PROFILE` (`local|test|staging|production`) and inferred profile fallback.
- Added profile-based runtime defaults for auth/rate-limit/webhook posture when explicit `PLUGIN_AGENTS_*` values are unset.
- Added runtime profile metadata across health/readiness/capabilities/schema/diagnostics outputs (`environmentProfile`, `environmentProfileSource`, `profileDefaultsApplied`, `effectivePolicyVersion`).
- Added read-only CP Environment Profile posture visibility in Security views.

## 0.8.1 - 2026-03-04

### Added

- Added inventory-aware product snapshots: `GET /agents/v1/products` now includes `hasUnlimitedStock` and `totalStock` per item.
- Added low-stock filtering on `GET /agents/v1/products` via `lowStock` and `lowStockThreshold` query parameters (full-sync mode).
- Added inventory fields to variant list payloads so `GET /agents/v1/variants` now exposes `stock`, `hasUnlimitedStock`, and `isAvailable`.

## 0.8.0 - 2026-03-04

### Added

- Added read APIs for additional Craft and Commerce resources: users (flag-gated), assets, categories, tags, global sets, addresses (flag-gated), content blocks, variants, subscriptions, transfers, and donations.
- Expanded `GET /agents/v1/changes` coverage to include newly exposed resources for broader incremental-sync parity.
- Added canonical agent handbook link exposure in discovery outputs (`/llms.txt`, `/llms-full.txt`).

## 0.7.0 - 2026-03-04

### Added

- Added one-click diagnostics bundle foundation across API (`GET /agents/v1/diagnostics/bundle`), CP download flow, and CLI (`craft agents/diagnostics-bundle`).
- Added `diagnostics:read` scope and contract metadata updates across capabilities/OpenAPI/schema/readme docs.

## 0.6.2 - 2026-03-04

### Fixed

- Fixed release metadata alignment by publishing a fresh immutable patch version after the previous `v0.6.1` tag pointed at a pre-bump commit.
- Fixed plugin-version fallback constants in API/readiness telemetry to match the current release.

## 0.6.1 - 2026-03-03

### Added

- Added release-gate protections to detect stale runtime version fallbacks before publish.

### Fixed

- Fixed adoption instrumentation runtime fatal by switching to the existing security posture API used across CP/runtime.
- Fixed machine-client POST compatibility by disabling CSRF enforcement for token-authenticated API endpoints.
- Fixed dual-approval race handling by adding optimistic concurrency guards/retries in approval decision flow.
- Fixed stale plugin-version fallback constants in API/readiness outputs.

## 0.6.0 - 2026-03-03

### Added

- Added guarded observability export endpoint `GET /agents/v1/metrics` (`metrics:read`) with runtime counters for auth failures, scope denials, rate-limit denials, request volume, and 5xx responses.
- Added CP Readiness telemetry snapshot cards sourced from observability metrics, plus threshold-based runbook/alert guidance for incident triage.

## 0.5.0 - 2026-03-03

- Improved CP operations UX with clearer section grouping, full-width separators, and state color coding across overview/dashboard/control/credentials views.
- Added API contract hardening via deterministic query validation (`400 INVALID_REQUEST` with `details`) for malformed `fields`/`filter`, enum, numeric, and identifier query paths.
- Added adoption instrumentation endpoint `GET /agents/v1/adoption/metrics` with `adoption:read` scope for funnel, time-to-first-success, and weekly managed-credential usage signals.
- Added canonical QA gates for API/scope/docs parity, deterministic validation regression, control/consumer surface regression, and migration safety checks; integrated into `scripts/qa/release-gate.sh`.
- Added operator-facing adoption docs: canonical first agent jobs and a copy/paste 30-minute quickstart flow.

## 0.4.0 - 2026-03-02

- Added webhook dead-letter queue persistence with guarded API replay endpoints and Control Panel replay controls.
- Added per-credential webhook subscription targeting (resource/action filters) to reduce firehose delivery.
- Added consumer lag tracking surfaces (`/agents/v1/consumers/checkpoint`, `/agents/v1/consumers/lag`) and dashboard visibility.
- Added credential expiry policies (TTL + reminder windows), CP warnings, and runtime exclusion of expired managed keys.
- Added credential CIDR allowlists with runtime API auth enforcement and CP management UI.
- Added policy simulator dry-run flow (`/agents/v1/control/policy-simulate`) plus CP simulation tooling.
- Added two-person approval support for high-risk actions with staged approval progress tracking.
- Added SLA escalation and auto-expiry behavior for pending approvals, including surfaced SLA state in CP/API payloads.
- Added list endpoint projection/filter support (`fields`, `filter`) on key read surfaces to reduce payload size.
- Added versioned machine-readable schema catalog endpoint (`/agents/v1/schema`) for safer client generation.

## 0.3.10 - 2026-03-02

- Added optional extended discovery export `GET /llms-full.txt` with capabilities/OpenAPI/CP surface alignment.
- Added CP-editable custom body settings for `llms.txt` and `commerce.txt`, including config lock-state awareness.
- Added Settings actions to reset custom `llms.txt` and `commerce.txt` bodies back to generated defaults.
- Extended discovery cache/prewarm/status flows to cover `llms-full.txt` and invalidate on settings save.
- Updated Dashboard discovery tab copy/labels to `Discovery Docs` for clearer IA.

## 0.3.9 - 2026-02-28

- Hardened discovery contract by adding root discovery aliases (`/capabilities`, `/openapi.json`) that map to the guarded API descriptors.
- Added authenticated introspection endpoint `GET /agents/v1/auth/whoami` with scope visibility, auth method details, and rate-limit snapshot.
- Added CLI validation commands for operators and CI:
  - `craft agents/auth-check`
  - `craft agents/discovery-check`
  - `craft agents/readiness-check`
  - `craft agents/smoke`
- Updated capabilities/OpenAPI/README contract metadata to include new auth/discovery surfaces and CLI checks.
- Updated `llms.txt` discovery output to annotate auth requirements/scopes and include canonical alias pointers.
- Dashboard settings now respect `config/agents.php` overrides for discovery toggles (`enableLlmsTxt`, `enableCommerceTxt`) and display lock-state guidance in CP.

## 0.3.8 - 2026-02-27

- Improved Dashboard tab readability by using the active tab label as the page heading.
- Removed repeated service-state panels from Readiness, Discovery, and Security tabs to reduce duplicate status noise.
- Refined Discovery tab document panels: default-at-a-glance status now focuses on URL and Last Modified, additional metadata is collapsed under Details, and preview code blocks use a subtle bordered style.

## 0.3.7 - 2026-02-27

- Fixed CP navigation state so the Agents section/subnav remains active across Dashboard, Settings, API Keys, and Return Requests routes.
- Fixed plugin settings entry-point behavior: opening Agents from `admin/settings/plugins` now redirects to `admin/agents/dashboard/overview`.
- Added an `Agents discovery caches` option to Craft’s Clear Caches utility (`agents-discovery`) to clear cached `llms.txt` and `commerce.txt` documents.
- Added canonical CP redirects for `admin/agents` and `admin/agents/dashboard` to `admin/agents/dashboard/overview`.

## 0.3.6 - 2026-02-27

- Polished CP IA by consolidating Overview/Readiness/Discovery/Security into a Dashboard with top tabs, while preserving legacy deep links via redirects.
- Renamed and simplified the experimental approvals area to Return Requests with clearer queue-first copy (`Now`, decisions, follow-up runs, activity) and agent-first fallback messaging.
- Improved API Keys UX with preset examples, native Craft scope selection, one-time key copy/download helpers, and a revoke+rotate shortcut action.
- Improved CP readability by default-collapsing technical JSON blocks and tightening labels/messages across settings and credential actions.
- Updated readiness diagnostics to treat CP and site web contexts as valid request context for the web-request readiness check.

## 0.3.5 - 2026-02-27

- Hid refund-approvals/control surfaces behind `PLUGIN_AGENTS_REFUND_APPROVALS_EXPERIMENTAL` (default off): CP tab/routes, API routes, capabilities/OpenAPI discoverability, and related scope catalog entries are now gated.
- Added agent-first approval request mode: CP request form is disabled by default and can be re-enabled via settings.
- Added API scope split for approvals: `control:approvals:request` and `control:approvals:decide` (legacy `control:approvals:write` remains supported).
- Added required approval-request provenance metadata on API (`metadata.source`, `metadata.agentId`, `metadata.traceId`).

## 0.3.4 - 2026-02-26

- Reworked the Control Plane CP interface into a queue-first operator flow (`Now`, `Act`, `Configure`, `Audit`).
- Added guided approval/execution forms with optional advanced JSON overrides for payload and metadata.
- Added policy-aware execute guardrails in CP (disabled-policy blocking, approval-required validation, action-type match checks).
- Improved control action flash messaging for idempotent replay, approval decisions, and blocked/failed execution outcomes.

## 0.3.3 - 2026-02-26

- Added control-plane foundation with governed policies, approvals, idempotent action execution ledger, and immutable audit events.
- Added new guarded control API endpoints under `/agents/v1/control/*` with explicit read/write scopes for policies, approvals, executions, and audit access.
- Added Control Plane Control Panel tab with policy upsert, approval queue decisions, execution controls, and control-plane snapshot visibility.
- Added plugin migration for control-plane persistence tables: policies, approvals, executions, and audit log.

## 0.3.1 - 2026-02-26

- Hardened Commerce availability checks in discovery/readiness surfaces to use project config state instead of forcing Commerce plugin bootstrap.
- Reduced risk of early `getCurrentStore()` fatals during plugin startup in environments where Commerce store/site mappings are incomplete.

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
