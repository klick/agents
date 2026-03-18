# Roadmap

Last updated: 2026-03-17
Current release: `v0.24.0`

## Direction

Run two explicit tracks in parallel:

- `Track A (70%)`: runtime reliability and operator safety
- `Track B (30%)`: agency adoption, packaged workflow UX, and agent-facing integration UX

Agents is being shaped for agencies and delivery teams responsible for operating Craft sites over time, especially where automation needs to stay explainable, governable, and safe in front of clients.

Near-term roadmap emphasis:

- safer bounded automation for approved client surfaces
- reusable workflow kits and delivery patterns
- extension into real Craft stacks where teams already work
- assistant-style operator support only after the trust boundary is stable

## Done (`v0.5.0`)

- Harden runtime contracts (API/scope/docs parity).
- Improve operator UX in CP (clearer queue/actions/warnings).
- Tighten validation and deterministic error behavior.
- Expand regression coverage for auth/control/webhook/consumer paths.
- Validate upgrade and migration safety.
- Define and document three canonical "first agent jobs" with copy/paste examples.
- Add an integration quickstart path focused on first successful action in under 30 minutes.

Release outcome:

- Runtime behavior is stable in production, and new integrators can reach first value quickly.

## Done (`v0.6.0`)

- Ship observability baseline:
  - metrics taxonomy and naming
  - runtime metrics collection
  - metrics export endpoint
  - CP telemetry snapshot
  - runbook and alert guidance
- Add adoption instrumentation:
  - first-call success funnel
  - time-to-first-success metric
  - credential activation and weekly usage tracking

Release outcome:

- Operators can triage incidents quickly, and product teams can see where integration adoption drops.
- `v0.6.1` hotfix closed runtime reliability issues in adoption metrics, machine POST CSRF handling, and dual-approval race safety.
- `v0.6.2` corrected release-version metadata/tag alignment for plugin store ingestion.

## Done (`v0.7.0`)

- Ship one-click diagnostics bundle:
  - contract + redaction policy
  - diagnostics engine
  - CP download flow
  - CLI companion command
- Improve integrator DX with schema/OpenAPI-based templates.
- Publish reference automations for the canonical jobs (with tested sample payloads).

Release outcome:

- Faster support resolution and repeatable onboarding from first call to production patterns.

## Done (`v0.8.0`)

- Expand Craft-native read coverage across remaining element families (users/assets/categories/tags/global sets/addresses/content blocks).
- Expand Commerce read coverage with variants, subscriptions, transfers, and donations surfaces.
- Extend unified incremental changes feed coverage across newly exposed resources.
- Publish canonical agent-handbook discovery link in `llms` discovery outputs.

Release outcome:

- Integrations can access a materially wider runtime surface with consistent sync semantics and discovery hints.

## Done (`v0.9.0`)

- Shipped schema/OpenAPI-based integration templates for canonical jobs.
- Shipped three tested reference automations with fixture payloads.
- Shipped copy/paste agent starter packs (`curl`, `javascript`, `python`) for onboarding.
- Expanded operator reliability pack with threshold defaults, triage signals, and richer diagnostics bundle snapshots.
- Shipped lifecycle governance controls: ownership metadata mapping, expiry/rotation reminders, and stale-key warnings.
- Removed Control CP surface (tab/routes/forms/permissions) from public operator UX; kept internal control-plane internals feature-flagged for future adapter-based execution.

Release outcome:

- Integrators can move from first call to production patterns faster, and operators get clearer reliability/lifecycle posture without exposing unfinished return workflows.

## Done (`v0.9.1`)

- Hidden Lifecycle Governance warning surfaces in the Agents CP view (summary panel + card warning strips) while keeping lifecycle APIs/services intact.

Release outcome:

- Operators get a cleaner Agent card view now, with lifecycle governance still available for future reintroduction without backend rollback.

## Done (`v0.21.x`)

- Shipped operator notifications (`F17`) with email-first delivery, recipient routing, recent-delivery visibility, and scheduled status-check support.
- Reintroduced the public operator IA around `Status`, `Approvals`, `Accounts`, and `Settings` and hardened the governed approval flow.
- Published the first-worker bootstrap path with a public guide and example worker.
- Bound approved governed entry-draft requests to exact saved drafts and blocked conflicting saved-draft creation to reduce ambiguous draft apply behavior.

Release outcome:

- Operators now have materially stronger support surfaces for notifications, account bootstrap, and governed draft approvals.

## Proposed Path to `1.0.0`

## Done (`v0.24.0`)

- Implemented `F15` production webhook probe.
- Added an admin-only `Webhook Probe` card in `Status` that sends a synthetic signed delivery against the live runtime webhook target.
- Added a dedicated probe ledger with recent run history, payload inspection, triggered-by metadata, and cooldown visibility.
- Kept the production probe separate from the dev-only `Webhook Test Sink` while reusing the same signing and outbound HTTP transport path.

Release outcome:

- Operators can validate live webhook transport safely in-place, without saving content or temporarily pointing delivery at a local sink.

## Done (`v0.23.0`)

- Reworked `Accounts` around a Craft-style managed-account registry with a default table view, an alternate card view, and modal-hosted details/actions that keep lifecycle operations coherent in both modes.
- Simplified governed diff review down to `Structured` and `Focus`, added an `After / Before` toggle inside Focus mode, and removed stale warning/toggle copy that no longer helped approval decisions.
- Published the public `Agents vs Element API` positioning page and refreshed CP/docs wording around the new Accounts registry model.

Release outcome:

- Operators can compare and manage machine identities more like a real registry, and approval review now concentrates on the two modes that actually support proofing work.

## Done (`v0.22.3`)

- Added a third `Focus` diff tab for governed approval review with muted context, emphasized changed text, and a narrow reading column aimed at proofing flows.
- Refined the diff modal chrome to feel more native in Craft, including lighter top surfaces, cleaner active-tab behavior, and monospaced Focus typography for word-for-word comparison.

Release outcome:

- Approvers now have a better text-proofing mode for high-attention review, with modal chrome that more clearly separates navigation from changed content.

## Done (`v0.22.2`)

- Restored async credential rotation in the Accounts details panel so rotate stays inline and can reveal the new one-time token without a full-page reload.
- Brightened the one-time token overlay actions and switched them to a smaller Craft-native button treatment for clearer contrast and better visual hierarchy.

Release outcome:

- Credential rotation is back to the intended inline workflow, and the token overlay actions are readable enough to support real operator use.

## Done (`v0.22.1`)

- Tightened the post-`F20` operator surfaces with cleaner Accounts, Approvals, and Status card framing built around shared muted header strips and more native Craft action treatments.
- Fixed completed approval diffs so `Applied / Completed` can still show meaningful changed rows after an approved draft has been applied and the active draft no longer exists.
- Added an operator-facing stale-status reset action and aligned the top Status verdict with the same final summary logic shown in the proof cards.

Release outcome:

- The core governed-approval UX is more trustworthy in daily use, and the surrounding CP surfaces now read more consistently as Craft-native operator tooling.

## Done (`v0.22.0`)

- Implemented `F20` approval content diff review surface.
- Added a dedicated `Diff` action next to `Review` for governed entry-draft approvals.
- Shipped the first version as changed-only, field-aware, and optimized for fast human judgment, including a text-focused redline view.

Release outcome:

- Approvers can see what changed in a few seconds instead of inferring content changes from raw payloads or metadata.

## Planned (`v0.25.x`) Bounded Client Automation and Contract Stabilization

- Implement `F21` governed write target sets and CP test helpers as the main operator-safety feature for bounded client automation.
- Add optional named target boundaries for write-capable accounts so `v1` governed entry writes can be limited to approved explicit entries and sites.
- Add operator-friendly CP helpers for generating prefilled governed write test requests or worker config from those target sets.
- Freeze the main CP IA.
- Freeze canonical routes and scope naming.
- Add full multi-site and multi-store support across the public contract:
  - explicit site/store selectors where they affect API behavior
  - predictable defaults when selectors are omitted
  - documentation that makes site/store context unambiguous for operators and integrators
- Verify Craft Cloud compatibility and document the Cloud setup path:
  - Cloud env variables
  - SMTP mail setup
  - scheduled `agents/notifications-check` command
  - Cloud-specific operator guidance where wording differs from generic server setups
- Tighten upgrade notes, deprecation rules, and compatibility discipline.
- Remove avoidable churn from user-visible contracts.

Release outcome:

- Agencies can automate approved client surfaces with clearer trust boundaries, while the public contract becomes materially safer to build against.

## Planned (`v0.26.x`) Agency Workflow Starter Kits and Companion Workers

- Implement `F19`.
- Pair strong account templates with companion guides, starter workers, and bootstrap artifacts that agencies can reuse across client work.
- Ship a shared worker scaffold for auth, preflight, pagination, output writing, and optional OpenAI narrative steps.
- Start with a small curated workflow set rather than trying to ship a production app for every template.

Release outcome:

- Agencies can package repeatable AI-assisted workflows as credible, governed service offerings instead of inventing each integration from scratch.

## Planned (`v0.27.x`) Agency Stack Extension Foundation

- Resume `F12` external plugin data access once the adapter/provider direction is reconfirmed.
- Ship provider registry + registration event.
- Add external read scopes and contract exposure in capabilities/OpenAPI/schema.
- Prefer at least one credible agency-relevant reference adapter path so the value is visible in real Craft stacks.

Release outcome:

- Agents can extend safely into the plugin ecosystems agencies already standardize on, without bloating the core plugin.

## Planned (`v0.28.x`) Agency Operator Copilot Foundation

- Implement `F22` provider-backed orchestration foundation for optional in-product LLM support.
- Start with env-only site-level provider configuration as the convenient in-product path.
- Support external assistants through the existing governed API and discovery surfaces, documented and supported in `v1` without introducing a heavy first-class external profile system.
- Support recommendation-first jobs such as scope recommendation, summaries, report drafting, and other agency-facing explanation work before broader in-product assistant behavior.
- Keep per-account BYOM out of the main path unless real demand proves the extra secret-management complexity is justified.
- Do not introduce broad autonomous operator control.

Release outcome:

- Agency teams get a constrained copilot for discovery, recommendations, and client-facing drafts without weakening the existing trust boundary.

## Planned (`v0.29.x`) Agency Fleet Operations Assist and Dependency-Led Extensibility

- Begin `F18`, phase 1 only.
- Keep it read-first and recommendation-first:
  - status visibility
  - approval queue visibility
  - account posture visibility
  - guided remediation
- Do not introduce broad delegated self-administration.
- Implement only the useful parts of `F13` if they are required by `F12`, `F21`, or later agency-facing governance work.
- Keep extensibility work dependency-led rather than turning it into a standalone roadmap narrative.

Release outcome:

- Agencies can operate more client sites with better guided insight, while extensibility grows only where it directly supports real agency workflows.

## Planned (`v0.30.x`) Pre-1.0 Consolidation

- Focus on bug fixing, upgrade safety, onboarding, and support polish.
- Avoid major IA churn.
- Validate that the core product promise and support model hold under real customer use.

Release outcome:

- The product is ready for a `1.0.0` stability commitment rather than still behaving like a moving target.

## `1.0.0` Criteria

Before `1.0.0`, the following must be stable:

- top-level CP information architecture
- canonical CP routes
- core scope catalog and naming
- core machine-readable descriptors
- settings model and config-lock behavior
- managed-account lifecycle behavior
- webhook delivery and verification model
- upgrade and migration expectations

## Parked / Not Before `1.0.0` Unless Reassessed

- `F14` agent commerce via stablecoin spend rail remains intentionally parked and should not shape the near-term core roadmap.

## Success Checks

- Support escalations reduced by at least `30%` by end of `v0.8.0` cycle.
- Time-to-triage for integration incidents below `15` minutes.
- Critical-path regression coverage at or above `90%`.
- Median time-to-first-successful integration action below `30` minutes.
- At least `3` canonical agent jobs are shipped, documented, and validated end-to-end.
- Weekly active credentials trend upward for two consecutive releases.

## Out of Scope (this horizon)

- New major action domains beyond current governed return/control model.
- Large visual redesign unrelated to operator clarity.
- Non-Craft platform expansion.
