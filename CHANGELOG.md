# Changelog

All notable changes to this project are documented in this file.

## Unreleased

## 0.21.12 - 2026-03-15

### Added

- Published a public operator-facing `Scope Guide` with plain-language explanations of what each scope unlocks, when a worker would need it, and when it usually should not be assigned.
- Published a governed `entry.updateDraft` worker example plus matching workflow docs so operators and developers can test the full approval-driven draft-write path end to end.
- Added an `Entry Translation Drafts` account template and public workflow guide for bounded localization-draft workflows that stay inside approvals.

### Changed

- Made `Accounts` the canonical Agents landing page, so `admin/agents` now redirects to `admin/agents/accounts` and keeps the Accounts subnav selected.
- Updated the Agents plugin entry from `Settings -> Plugins` to open `Agents -> Settings` inside the Agents CP instead of sending operators through the generic plugin settings path.
- Added direct Accounts header links to the public first-worker guide and the new scope guide to make account setup and scope selection less opaque.
- Refined the Accounts surface with a native secondary-pane account card background, calmer template-card copy color, and the revised top-level nav order of `Accounts`, `Approvals`, `Status`, and `Settings`.

## 0.21.11 - 2026-03-15

### Fixed

- Bound approved governed entry-draft requests to the exact saved draft created by execution so later review/apply surfaces no longer have to reconstruct draft identity from loose payload fragments.
- Blocked governed draft creation when a canonical entry already has a saved draft and surfaced the resulting conflict details directly in `Approvals`, including the conflicting draft ids and open links for operator follow-up.

## 0.21.10 - 2026-03-14

### Added

- Published the `First Worker` guide and bootstrap example so operators and developers now have a stable public path from account creation to a working scheduled worker.

### Changed

- Reworked the Accounts bootstrap flow around a lighter direct edit trigger, one-time worker `.env` export, async `Test Account` / `Rotate` / `Revoke` actions, and clearer in-product guidance for first-worker setup.
- Updated the tracked roadmap with `F19` workflow starter kits and companion workers, and refreshed the public marketing banner asset.

## 0.21.9 - 2026-03-14

### Changed

- Reworked `Settings` around native Craft CP tabs with one shared top-right `Save Settings` action instead of per-panel save actions and custom tab chrome.
- Refined the `Status` surface with explicit card ordering, calmer detail toggles, and consistent human-readable timestamps across operator-facing tables and summaries.
- Enriched `Operator Notifications` recent-delivery rows so recipients resolve to clickable CP user names plus delivery channels when possible.
- Polished `Approvals` journey cards by muting empty stages, hiding empty-state chevrons, and keeping the staged control view aligned with the rest of the CP.

## 0.21.8 - 2026-03-14

### Changed

- Reworked `Approvals` into a max-width journey of card-based stages with centered divider dots, embedded rules management, and toggleable sections that align with the rest of the CP.
- Made approval-rule management more operator-friendly by adding inline `Edit` and `Delete` actions plus a human-readable governed-action selector in the shared rule form.
- Stacked pending decision buttons vertically with consistent widths for clearer high-risk approval actions.

## 0.21.7 - 2026-03-13

### Fixed

- Hid Commerce-only scopes from runtime defaults, capabilities, and the Accounts scope picker when Craft Commerce is not installed.

### Changed

- Reworked the Accounts scope picker into a responsive multi-column layout with group guidance so operators can evaluate access decisions more easily on wider viewports.

## 0.21.6 - 2026-03-13

### Fixed

- Treated Commerce availability as optional in readiness diagnostics so CMS-only installs no longer surface a degraded `Status` state after update.

## 0.21.5 - 2026-03-13

### Fixed

- Removed the remaining bootstrap-only missing-account warning from the `Status` degradation path so healthy fresh installs no longer render `Degraded` after update.

### Changed

- Applied the muted notice background treatment to the `Status` summary strip while keeping the summary items themselves transparent.

## 0.21.4 - 2026-03-13

### Changed

- Softened the fresh-install `Status` posture so healthy environments without any accounts now read as `Ready` instead of `Blocked`.
- Reframed the main `Status` surface around operator-facing account language instead of internal credential terminology for the core readiness and action-mapping flow.
- Hid the `Operator Notifications` card from `Status` when operator notifications are disabled.
- Updated the internal roadmap with explicit pre-1.0 milestones for full multi-site/multi-store support and Craft Cloud compatibility.

## 0.21.3 - 2026-03-13

### Added

- Added an account-scoped API token reveal overlay for newly created and rotated accounts, including copy/download actions and an explicit close control on the affected account card.
- Added finer Accounts pulse-simulation controls for local demos and QA, including mode, account targeting, and interval query parameters.

### Changed

- Moved the one-time API token reveal from a global Accounts panel into the matching account card so create/rotate flows stay visually anchored to the affected account.

## 0.21.2 - 2026-03-13

### Changed

- Softened the `Status` readiness verdict for healthy low-traffic environments so they stay `Ready` while confidence is still building, instead of defaulting to `Unproven`.
- Reframed `Traffic / Access` and `Confidence / Observability` messaging to communicate calm, positive readiness without hiding real monitoring gaps.

## 0.21.1 - 2026-03-13

### Added

- Added a persisted short description field for managed accounts so operators can capture the account purpose directly on account cards and in the add/edit form.
- Added a dedicated `Account Templates` section with compact starter cards and a new `Legal & Consent Checker` template focused on core Craft site review.

### Changed

- Reordered the top-level Agents CP navigation to `Status`, `Accounts`, `Approvals`, and `Settings`.
- Grouped account scopes by type in the add/edit form so operators can evaluate access decisions more quickly.
- Reworked the `Waiting for Decision` table so dual-control approvals expose two explicit approval buttons and visually consume one slot after the first approval.

### Removed

- Removed the temporary `Reason for rejection` and `Optional note` inputs from the waiting-table decision actions.
- Removed legacy case-specific account templates in favor of broadly useful core-Craft starter profiles.

## 0.21.0 - 2026-03-13

### Added

- Added operator notifications with queue-backed email delivery for approval requests, approval decisions, execution issues, webhook delivery failures, and scheduled system-status checks.
- Added account-level `Approval recipients` selection so governed-write notifications can route to specific CP users instead of only global operator recipients.

### Changed

- Switched managed account ownership from a free-text owner field to a native Craft user relation while preserving legacy owner strings as a fallback until operators remap them.
- Added an `Operator Notifications` Status card with recipient visibility, recent delivery state, and explicit last SMTP handoff details for operator verification.
- Added webhook transport settings to Settings so runtime webhook URL and signing secret can be managed with Craft-native env-aware inputs from the CP.

### Fixed

- Fixed notification queue processing and email message construction so approval emails hand off correctly through SMTP-backed Craft mail transports.
- Fixed account-level governed-write notifications so approval-recipient routing is reflected in both runtime delivery and the Status card summary.

## 0.20.0 - 2026-03-12

### Added

- Added a dev-only `Webhook Test Sink` with local capture storage, signature verification, CP inspection, a one-click `Send test webhook` action, and scripted smoke/E2E validation helpers for local webhook development.
- Added env-aware webhook target and signing-secret fields to Settings so runtime webhook transport can be configured from the CP using Craft-native env/alias inputs.

### Changed

- Realigned the CP information architecture and canonical routes around `Status`, `Approvals`, `Accounts`, and `Settings`, with the visible paths now using `/status`, `/approvals`, and `/accounts`.
- Hardened first-run operator UX so healthy fresh installs bias toward `Ready to Connect`, treat sync-state as optional until configured, and keep confidence gaps visible without making the whole page pessimistic.
- Reworked the webhook test sink into a dedicated Status card with capture-state handling, payload drill-down, and clearer dev-only/runtime-target copy.
- Standardized rectangular CP card surfaces to a `3px` radius and tightened Status card composition, diagnostics-bundle placement, and proof/action affordances.
- Removed Discovery Docs from the core plugin surface, including CP UI, routes, generated discovery files, diagnostics references, and docs coverage.

### Fixed

- Fixed the webhook payload dialog so long payloads scroll inside the modal instead of expanding the entire overlay.

## 0.10.9 - 2026-03-11

### Changed

- Reworked the top-level CP information architecture to `Status`, `Approvals`, `Accounts`, `Discovery Docs`, and `Settings`, removing local sidebars from `Status`, `Accounts`, and `Discovery Docs` and promoting Discovery Docs to its own top-level surface.
- Added a first-run `Ready to Connect` bootstrap state for fresh installs so healthy but inactive environments no longer open on a pessimistic `Unproven` verdict.
- Renamed the CP-facing `Control` surface to `Approvals` while preserving the underlying governed-write routes and compatibility redirects.
- Reframed account-level webhook subscription copy as event-interest routing so operators can more clearly understand how external workers are selected and woken through the shared webhook destination.
- Added a `Monthly Report Agent` managed-account template and tightened Discovery Docs/Status presentation to match the current CP direction.

## 0.10.8 - 2026-03-11

### Changed

- Merged dashboard security posture fully into `Readiness`, so operators now work from one combined state card, one action-mapping table, and one shared proof-card grid.
- Replaced the separate security summary/proof surfaces with merged proof-card detail dialogs for `Traffic / Access`, `Delivery / Webhooks`, `Integration / Capacity`, `Credentials / Policy`, and `Confidence / Observability`.
- Removed the standalone `Security` dashboard tab while preserving legacy route and anchor compatibility inside the merged `Readiness` surface.
- Tightened the merged readiness card styling to match the current Figma direction for the top signal header, summary strip, proof-card borders, and embedded detail actions.
- Removed the readiness-page security technical JSON section now that security posture is represented through the merged proof-card and detail-dialog model.

## 0.10.7 - 2026-03-11

### Changed

- Reworked the Dashboard `Readiness` view into an operator state card with summary strips, structured proof panels, and a filtered action-mapping table that only appears when signals need follow-up.
- Reworked the Dashboard `Security` view to use the same state-card and action-mapping model while preserving dead-letter queue replay operations below the summary surface.
- Added focused deep-link support on Accounts cards so dashboard remediation links can open and highlight the most relevant machine account context.
- Added stable section anchors for Dashboard and Settings surfaces and updated dashboard regression checks to match the current card-based CP architecture.

### Fixed

- Fixed the Security dashboard dead-letter queue summary so an empty queue no longer throws a Twig runtime error when rendering the latest-update field.

## 0.10.6 - 2026-03-09

### Changed

- Refined the Control CP tables to share a consistent Waiting for Decision-derived header/body treatment across approvals, follow-up, activity, and rules views.
- Added collapsed-by-default disclosure toggles for Approved, Applied / Completed, Runs That Need Follow-up, Activity Log, and inline Proposed changes details.
- Tightened Control CP spacing and card-strip behavior for a more consistent Craft-native operator experience across desktop and mobile.

## 0.10.5 - 2026-03-09

### Changed

- Hardened machine-write auth: query-token transport remains read-only even when enabled, and write routes now require header auth plus `Content-Type: application/json`.
- Bound sync-state checkpoint writes to the authenticated credential context so dedicated credentials can no longer overwrite another integration's checkpoint state.
- Persisted approval assurance mode and downgrade reason on each request (`dual_control`, `single_approval`, `single_operator_degraded`) so later operator-count changes do not rewrite historical approval strength.
- Surfaced approval assurance details in the Control CP and control-approval flash messaging for clearer operator auditability.

### Fixed

- Fixed requester/approver separation so self-approval is blocked whenever a request was evaluated under non-degraded assurance.
- Fixed managed credential generation to fail closed when `random_bytes()` is unavailable instead of falling back to predictable entropy.

## 0.10.4 - 2026-03-09

### Added

- Added guarded runtime incident feed endpoint `GET /agents/v1/incidents` with `incidents:read` scope and query filters (`severity`, `limit`) for strict-redacted reliability incident snapshots.
- Added incident snapshot coverage to runtime contracts (`/capabilities`, `/openapi.json`, `/schema`) and reliability regression checks.

### Changed

- Updated release/docs parity for the new incidents scope and endpoint across README and operator runbooks.
- Updated Accounts scope selection defaults to include `incidents:read` for managed credential setup.
- Hid local `/.tmp` workspace artifacts from release surfaces by adding `/.tmp/` to `.gitignore`.

## 0.10.3 - 2026-03-08

### Fixed

- Fixed `GET /agents/v1/openapi.json` response maps so OpenAPI `responses` are emitted as status-code objects (not arrays), restoring validator compatibility for GPT Actions and other OpenAPI tooling.
- Fixed OpenAPI POST operation contracts to include a minimal JSON `requestBody.content` schema where bodies are required, preventing requestBody validation errors in strict Action importers.
- Added an absolute API server URL (`https://<host>/agents/v1`) to the OpenAPI `servers` list so GPT Actions can resolve a valid server URL without manual schema edits.

## 0.10.2 - 2026-03-08

### Changed

- Unified Control CP and governed write APIs behind one gate: `PLUGIN_AGENTS_WRITES_EXPERIMENTAL` (removed separate CP override behavior).

## 0.10.1 - 2026-03-08

### Changed

- Renamed governed draft-write scope to `entries:write:draft` for clarity, and kept `entries:write` as a deprecated compatibility alias.
- Simplified governed-approval operator flow: final approval now executes immediately, and approved/apply/completed states are clearer in the Control CP.
- Relaxed dual-approval requirement when only one active CP user exists, so single-operator installs can still run governed flows.

## 0.10.0 - 2026-03-07

### Added

- Added governed write action support for `entry.updateDraft` through `POST /agents/v1/control/actions/execute`, including native draft creation/update execution in `ControlPlaneService`.
- Added action payload contract metadata for `entry.updateDraft` to OpenAPI/schema descriptors (`x-action-payloads` / `xActionPayloads`).
- Added experimental `entries:write` scope for governed entry-draft updates and exposed it in Accounts scope selection (effective only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`).
- Added reference automation fixture + docs for governed entry draft updates:
  - `docs/reference-automations/fixtures/entry-update-draft-execute.json`
  - `docs/reference-automations.md`
  - `docs/canonical-first-agent-jobs.md`
- Added template + starter-pack discoverability for `governed-entry-draft-update` across template/starter services and docs.
- Added per-account write indicator icons in CP cards: locked icon when write actions require human approval, unlocked icon when write actions are allowed without approval.
- Added per-account “Always require human approval” control for write-capable accounts in CP create/edit flows.

### Changed

- Extended QA regression scripts to assert governed entry-draft write contracts and starter-pack/reference automation coverage.
- Added a CP Settings runtime toggle (`enableWritesExperimental`) for governed writing/control API surfaces, with env/config lock handling.
- Removed legacy refund/return env-flag aliases; governed write surfaces now respond only to `PLUGIN_AGENTS_WRITES_EXPERIMENTAL` and `PLUGIN_AGENTS_WRITES_CP_EXPERIMENTAL`.
- Restricted CP visibility/effectiveness of per-account human-approval control to write-capable accounts only.

### Fixed

- Fixed CP per-account human-approval lightswitch persistence and edit-mode hydration so off/on state is saved and displayed correctly.
- Fixed write indicator rendering and status copy on account cards for write-capable credentials.

## 0.9.3 - 2026-03-07

### Changed

- Migrated sync-state naming across docs/contracts/QA from legacy `/consumers/*` wording to canonical `/sync-state/*` endpoints.
- Refined CP dashboard and accounts UX with a unified metric-strip card style, improved card filtering states, and updated discovery-doc card interactions.
- Updated Reliability Threshold settings fields to use Craft env-var-aware inputs in CP.

### Fixed

- Fixed reliability threshold parsing so numeric and env-var-backed values are persisted and evaluated consistently in runtime signals.
- Fixed reliability QA coverage for env-var-driven threshold settings.

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
- Hid CP Control tab/routes/permissions by default behind an internal CP-only flag (`PLUGIN_AGENTS_WRITES_CP_EXPERIMENTAL`) while keeping control-plane API/data internals unchanged.

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

- Fixed CP navigation state so the Agents section/subnav remains active across Dashboard, Settings, API Keys, and Control routes.
- Fixed plugin settings entry-point behavior: opening Agents from `admin/settings/plugins` now redirects to `admin/agents/dashboard/overview`.
- Added an `Agents discovery caches` option to Craft’s Clear Caches utility (`agents-discovery`) to clear cached `llms.txt` and `commerce.txt` documents.
- Added canonical CP redirects for `admin/agents` and `admin/agents/dashboard` to `admin/agents/dashboard/overview`.

## 0.3.6 - 2026-02-27

- Polished CP IA by consolidating Overview/Readiness/Discovery/Security into a Dashboard with top tabs, while preserving legacy deep links via redirects.
- Renamed and simplified the experimental approvals area to Control with clearer queue-first copy (`Now`, decisions, follow-up runs, activity) and agent-first fallback messaging.
- Improved API Keys UX with preset examples, native Craft scope selection, one-time key copy/download helpers, and a revoke+rotate shortcut action.
- Improved CP readability by default-collapsing technical JSON blocks and tightening labels/messages across settings and credential actions.
- Updated readiness diagnostics to treat CP and site web contexts as valid request context for the web-request readiness check.

## 0.3.5 - 2026-02-27

- Hid governed-write/control surfaces behind `PLUGIN_AGENTS_WRITES_EXPERIMENTAL` (default off): CP tab/routes, API routes, capabilities/OpenAPI discoverability, and related scope catalog entries are now gated.
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
