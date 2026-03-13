# Agents Plugin Roadmap

Date: 2026-03-13  
Current release: `v0.21.7`

## Direction

Run two explicit tracks in parallel:

- `Track A (70%)`: runtime reliability and operator safety
- `Track B (30%)`: adoption and agent-facing integration UX

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

## Proposed Path to `1.0.0`

## Planned (`v0.21.x`) External Adapter Foundation

- Implement `F12` external plugin data access.
- Ship provider registry + registration event.
- Add external read scopes and contract exposure in capabilities/OpenAPI/schema.
- Ship first standalone reference adapter.

Release outcome:

- Agents proves it can extend safely beyond core Craft/Commerce data without bloating the core plugin.

## Planned (`v0.22.x`) Operator Notifications

- Implement the core of `F17`.
- Ship email-first notifications for:
  - degraded or blocked status
  - approval pending decision
  - approval decided or execution failed
  - webhook delivery failures / DLQ growth
- Keep additional channels behind a clean channel abstraction.

Release outcome:

- Operators no longer need to sit inside the CP to catch important runtime and approval events.

## Planned (`v0.23.x`) Production Validation and Supportability

- Implement `F15` production webhook probe.
- Tighten diagnostics and support flows around webhook delivery and runtime verification.
- Add lightweight operator visibility for recent probe/notification outcomes if useful.

Release outcome:

- Production environments can validate webhook transport safely without content mutation or dev-only tooling.

## Planned (`v0.24.x`) Contract and Upgrade Stabilization

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

- The product becomes materially safer for external adopters to build against, including teams running multi-site or multi-store Craft installs.

## Planned (`v0.25.x`) Extensibility Hardening

- Implement the useful parts of `F13`.
- Add registry-backed scope extension and field-profile governance where needed.
- Only expand after the adapter/provider direction is proven.

Release outcome:

- Agents gains controlled extensibility without collapsing into arbitrary scope sprawl.

## Planned (`v0.26.x`) Agent-Assisted Operations

- Begin `F18`, phase 1 only.
- Start with read-only insight and recommendation support.
- Do not introduce broad autonomous operator control.

Release outcome:

- Agents can assist operators inside the product without weakening the trust boundary.

## Planned (`v0.27.x`) Pre-1.0 Consolidation

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
