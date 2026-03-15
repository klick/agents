# Changelog Highlights

See full source changelog in repository root: `CHANGELOG.md`.

## 0.22.2 (2026-03-16)

- Restored inline async `Rotate` behavior in `Accounts` so token rotation no longer drops back to a full postback.
- Brightened the one-time token overlay actions and switched them to the smaller Craft button treatment for clearer contrast against the dark overlay.

## 0.22.1 (2026-03-15)

- Fixed completed approval diffs for governed `entry.updateDraft` requests so `Applied / Completed` can compare the applied revision against the previous revision even after the active draft is gone.
- Added a stale-status reset action and aligned the top `Status` verdict with the same final summary logic shown in the proof cards.
- Refined Accounts, Approvals, and Status card framing so more of the control-plane surface now uses shared muted strip headers and Craft-native action treatments.

## 0.22.0 (2026-03-15)

- Added a dedicated `Diff` action next to `Review` for governed `entry.updateDraft` approvals.
- Added a changed-only `Structured` diff view plus a `Redline` tab for text-focused approval review with surrounding context.
- Bound approval diffs to the exact saved draft when one is linked and clarified canonical-request fallback when no readable saved draft is available yet.
- Refined the `Approvals` tables and diff modal framing so the review surface feels more native in the Craft CP.

## 0.21.12 (2026-03-15)

- Published a public operator-facing `Scope Guide` with plain-language explanations of what each scope unlocks, when a worker would need it, and when it usually should not be assigned.
- Published a governed `entry.updateDraft` worker example plus matching workflow docs so operators and developers can test the full approval-driven draft-write path end to end.
- Added an `Entry Translation Drafts` account template and workflow guide for bounded localization-draft workflows that stay inside approvals.
- Made `Accounts` the canonical Agents landing page, updated the Settings -> Plugins entry to open `Agents -> Settings` inside the Agents CP, and added direct header links to the first-worker guide and scope guide.

## 0.21.11 (2026-03-15)

- Bound approved governed entry-draft requests to the exact saved draft created by execution so later review/apply surfaces no longer have to reconstruct draft identity from loose payload fragments.
- Blocked governed draft creation when a canonical entry already has a saved draft and surfaced the resulting conflict details directly in `Approvals`, including the conflicting draft ids and draft links for operator follow-up.

## 0.21.10 (2026-03-14)

- Published the `First Worker` guide and bootstrap example so operators and developers now have a stable public path from account creation to a working scheduled worker.
- Reworked the Accounts bootstrap flow around a lighter direct edit trigger, one-time worker `.env` export, async `Test Account` / `Rotate` / `Revoke` actions, and clearer in-product guidance for first-worker setup.
- Added `F19` workflow starter kits and companion workers to the roadmap, and refreshed the public marketing banner asset.

## 0.21.9 (2026-03-14)

- Reworked `Settings` around native Craft CP tabs with one shared top-right `Save Settings` action instead of per-panel save actions and custom tab chrome.
- Refined the `Status` surface with explicit card ordering, calmer detail toggles, and consistent human-readable timestamps across operator-facing tables and summaries.
- Enriched `Operator Notifications` recent-delivery rows so recipients resolve to clickable CP user names plus delivery channels when possible.
- Polished `Approvals` journey cards by muting empty stages, hiding empty-state chevrons, and keeping the staged control view aligned with the rest of the CP.

## 0.21.8 (2026-03-14)

- Reworked `Approvals` into a max-width journey of card-based stages with centered divider dots, embedded rules management, and toggleable sections that align with the rest of the CP.
- Added inline `Edit` / `Delete` actions for approval rules and replaced the stale free-text action-pattern example with a human-readable governed-action selector.
- Stacked pending decision buttons vertically with consistent widths for clearer high-risk approval actions.

## 0.21.7 (2026-03-13)

- Hid Commerce-only scopes from runtime defaults, capabilities, and the Accounts scope picker when Craft Commerce is not installed.
- Reworked the Accounts scope picker into a responsive multi-column layout with group guidance so operators can evaluate access decisions more easily on wider viewports.

## 0.21.6 (2026-03-13)

- Treated Commerce availability as optional in readiness diagnostics so CMS-only installs no longer surface a degraded `Status` state after update.

## 0.21.5 (2026-03-13)

- Removed the remaining bootstrap-only missing-account warning from the `Status` degradation path so healthy fresh installs no longer render `Degraded` after update.
- Applied the muted notice background treatment to the `Status` summary strip while keeping the summary items themselves transparent.

## 0.21.4 (2026-03-13)

- Softened the fresh-install `Status` posture so healthy environments without any accounts now read as `Ready` instead of `Blocked`.
- Reframed the main `Status` surface around operator-facing account language instead of internal credential terminology for the core readiness and action-mapping flow.
- Hid the `Operator Notifications` card from `Status` when operator notifications are disabled.
- Updated the internal roadmap with explicit pre-1.0 milestones for full multi-site/multi-store support and Craft Cloud compatibility.

## 0.21.3 (2026-03-13)

- Added an account-scoped API token reveal overlay for create/rotate flows so copy/download actions stay anchored to the affected account card.
- Added finer Accounts pulse-simulation controls for local demo and QA flows.

## 0.21.2 (2026-03-13)

- Softened the `Status` readiness verdict for healthy low-traffic environments so they stay `Ready` while confidence is still building, instead of defaulting to `Unproven`.
- Reframed `Traffic / Access` and `Confidence / Observability` messaging to keep quiet-but-healthy installs calm and positive without hiding real monitoring gaps.

## 0.21.1 (2026-03-13)

- Added a persisted short description field for managed accounts so account cards and the add/edit form can carry a concise operator-facing purpose note.
- Added a dedicated compact `Account Templates` section with broader starter profiles, including a `Legal & Consent Checker` template for core site/compliance review.
- Reordered the Agents CP IA to `Status`, `Accounts`, `Approvals`, and `Settings`.
- Grouped account scopes by type and reworked dual-control approvals to show two explicit approval buttons with consumed-slot disable states.
- Removed the temporary waiting-table rejection/note inputs and dropped legacy case-specific account templates.

## 0.21.0 (2026-03-13)

- Added queue-backed operator email notifications for approval requests, approval decisions, execution issues, webhook delivery failures, and scheduled system-status checks.
- Added per-account `Approval recipients` routing so governed-write alerts can target selected CP users instead of only a global recipient list.
- Switched managed account ownership to a native Craft user relation with safe legacy-owner fallback for existing installs.
- Added explicit last-handoff details to the `Operator Notifications` Status card so operators can verify recent SMTP delivery attempts in the CP.

## 0.20.0 (2026-03-12)

- Added a dev-only `Webhook Test Sink` with local capture history, signature verification, CP inspection, and a one-click `Send test webhook` flow for local webhook validation.
- Added env-aware webhook URL and secret fields in Settings so the runtime transport can be configured from the CP with normal Craft env-var handling.
- Realigned the CP IA and canonical routes around `Status`, `Approvals`, `Accounts`, and `Settings`, using `/status`, `/approvals`, and `/accounts` as the visible paths.
- Improved first-run Status posture with `Ready to Connect`, optional sync-state until configured, and a dedicated diagnostics bundle card.
- Removed Discovery Docs from the core plugin surface, routes, generated files, diagnostics bundle, and public docs.

## 0.10.9 (2026-03-11)

- Reworked the top-level CP IA to `Status`, `Approvals`, `Accounts`, `Discovery Docs`, and `Settings`, removing local sidebars from the primary operator surfaces.
- Added a `Ready to Connect` bootstrap verdict for fresh installs so healthy but inactive environments no longer default to `Unproven`.
- Renamed the CP-facing `Control` surface to `Approvals` while keeping governed-write routes and redirects compatible.
- Reframed account webhook subscriptions as event-interest routing and added a `Monthly Report Agent` starter template.

## 0.10.8 (2026-03-11)

- Merged dashboard security posture fully into `Readiness`, with one combined state card, one action-mapping table, and a shared proof-card grid.
- Added proof-card detail dialogs for `Traffic / Access`, `Delivery / Webhooks`, `Integration / Capacity`, `Credentials / Policy`, and `Confidence / Observability`.
- Removed the standalone `Security` dashboard tab while keeping legacy route compatibility through readiness redirects and preserved anchors.
- Removed the readiness-page security technical JSON section in favor of the merged proof-card/detail-dialog model.

## 0.10.7 (2026-03-11)

- Reworked Dashboard `Readiness` into a state-card-driven operator surface with integrated action mapping.
- Reworked Dashboard `Security` to match the same operator model while keeping dead-letter queue replay actions below the summary card.
- Added account-card focus deep links so remediation links can jump operators into the relevant Accounts context.
- Fixed the Security dashboard dead-letter queue summary so an empty queue no longer causes a Twig render error.

## 0.10.6 (2026-03-09)

- Refined Control CP tables to use a more consistent Waiting for Decision-derived header and body treatment across approvals, follow-up, activity, and rules views.
- Added collapsed-by-default disclosure toggles for Approved, Applied / Completed, Runs That Need Follow-up, Activity Log, and inline Proposed changes details.
- Tightened Control CP spacing and card-strip behavior for a more consistent Craft-native operator experience across desktop and mobile.

## 0.10.5 (2026-03-09)

- Hardened machine-write auth: query-token transport stays read-only, while write routes now require header auth and `Content-Type: application/json`.
- Bound sync-state checkpoint writes to the authenticated credential id to prevent cross-credential checkpoint overwrites.
- Persisted explicit approval assurance modes and downgrade reasons, and now surface them in Control CP/audit messaging.
- Managed credential generation now fails closed if secure entropy is unavailable.

## 0.10.4 (2026-03-09)

- Added guarded incident snapshot endpoint: `GET /agents/v1/incidents` with `incidents:read` scope.
- Added `severity`/`limit` filtering and strict-redacted incident payloads derived from runtime reliability signals.
- Updated scope/docs/release parity for incidents across README, runbooks, and QA checks.

## 0.10.2 (2026-03-08)

- Unified Control CP and governed write APIs behind one gate: `PLUGIN_AGENTS_WRITES_EXPERIMENTAL`.

## 0.10.1 (2026-03-08)

- Renamed governed draft-write scope to `entries:write:draft` and kept `entries:write` as a deprecated compatibility alias.
- Simplified governed approval flow: final approval executes immediately, with clearer approved/apply/completed states in Control CP.
- Dual-approval now degrades to single approval when only one active CP user exists.

## 0.10.0 (2026-03-07)

- Added governed write action support for `entry.updateDraft` via `POST /agents/v1/control/actions/execute` with schema/OpenAPI payload metadata.
- Added experimental `entries:write` scope (gated by `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`) and per-account write indicators in CP cards.
- Added per-account human-approval control for write-capable accounts and fixed its CP persistence/edit hydration behavior.
- Removed legacy refund/return experimental aliases; governed write surfaces now use writes-specific experimental flags only.

## 0.9.3 (2026-03-07)

- Migrated sync-state naming across docs/contracts/QA from legacy `/consumers/*` wording to canonical `/sync-state/*`.
- Refined CP dashboard/accounts UX with unified metric-strip cards, improved filter states, and updated discovery-doc card interactions.
- Upgraded reliability threshold settings to Craft env-var-aware inputs and fixed env-var threshold parsing/evaluation behavior.

## 0.9.0 (2026-03-06)

- Shipped schema/OpenAPI-based templates and tested reference automations for canonical first jobs.
- Shipped integration starter packs (`curl`, `javascript`, `python`) for faster onboarding.
- Expanded reliability and diagnostics surfaces with threshold-driven triage and richer bundle payloads.
- Shipped lifecycle governance endpoint/CLI/CP visibility for ownership, stale usage, expiry, and rotation risk posture.
- Removed Control CP surface from public/operator UX while keeping internal control-plane internals feature-flagged.

## 0.8.7 (2026-03-05)

- Reordered CP subnavigation so `Agents` appears directly below `Dashboard`.

## 0.8.6 (2026-03-05)

- Reworked CP agent management to card-based create/edit workflows and agent-first wording.
- Added managed-agent pause/resume lifecycle support with persisted pause state.
- Added live per-agent usage activity indicators with runtime setting toggle and query-param simulation support.

## 0.8.5 (2026-03-04)

- Added environment profile resolver with optional `PLUGIN_AGENTS_ENV_PROFILE` (`local|test|staging|production`) and inferred profile fallback.
- Added profile-based runtime defaults for auth/rate-limit/webhook posture when explicit `PLUGIN_AGENTS_*` values are unset.
- Added runtime profile metadata across health/readiness/capabilities/schema/diagnostics outputs (`environmentProfile`, `environmentProfileSource`, `profileDefaultsApplied`, `effectivePolicyVersion`).
- Added read-only CP Environment Profile posture visibility in Security views.

## 0.8.1 (2026-03-04)

- Added inventory-aware product snapshots: `GET /agents/v1/products` now includes `hasUnlimitedStock` and `totalStock` per item.
- Added low-stock filtering on `GET /agents/v1/products` via `lowStock` and `lowStockThreshold` query parameters (full-sync mode).
- Added inventory fields to variant list payloads so `GET /agents/v1/variants` now exposes `stock`, `hasUnlimitedStock`, and `isAvailable`.

## 0.8.0 (2026-03-04)

- Added read APIs for additional Craft and Commerce resources: users (flag-gated), assets, categories, tags, global sets, addresses (flag-gated), content blocks, variants, subscriptions, transfers, and donations.
- Expanded `GET /agents/v1/changes` coverage to include newly exposed resources for broader incremental-sync parity.
- Added canonical agent handbook link exposure in discovery outputs (`/llms.txt`, `/llms-full.txt`).

## 0.7.0 (2026-03-04)

- Added one-click diagnostics bundle surfaces across API (`GET /agents/v1/diagnostics/bundle`), CP download flow, and CLI (`craft agents/diagnostics-bundle`).
- Added `diagnostics:read` scope with capabilities/OpenAPI/schema contract coverage.

## 0.6.2 (2026-03-04)

- Fixed release metadata/tag alignment by shipping a fresh immutable patch release for plugin-store ingestion.
- Fixed plugin-version fallback constants in API/readiness telemetry to match the current release.

## 0.6.1 (2026-03-03)

- Fixed adoption metrics runtime fatal by using the existing security posture API.
- Fixed machine-client POST compatibility by disabling CSRF on token-authenticated API routes.
- Fixed dual-approval decision race conditions with optimistic concurrency guards.
- Fixed stale version fallback constants and added release-gate checks to prevent regression.

## 0.6.0 (2026-03-03)

- Added observability metrics endpoint: `GET /agents/v1/metrics` (`metrics:read`).
- Added CP Readiness telemetry snapshot and runbook/alert guidance.
- Added adoption instrumentation endpoint: `GET /agents/v1/adoption/metrics` (`adoption:read`).

## 0.5.0 (2026-03-03)

- Hardened API contract parity and deterministic request validation paths.
- Improved CP UX grouping and metric color coding.
- Added canonical first-agent jobs, quickstart guidance, and stronger regression gates.
