# Agents Plugin Roadmap

Date: 2026-03-06  
Current release: `v0.9.1`

## Direction

Run two explicit tracks in parallel:

- `Track A (70%)`: runtime reliability and operator safety
- `Track B (30%)`: adoption and agent-facing integration UX

## Done (`v0.5.0`)

Target: April 2026

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

Target: May 2026

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

Target: July 2026

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

Target: August 2026

- Expand Craft-native read coverage across remaining element families (users/assets/categories/tags/global sets/addresses/content blocks).
- Expand Commerce read coverage with variants, subscriptions, transfers, and donations surfaces.
- Extend unified incremental changes feed coverage across newly exposed resources.
- Publish canonical agent-handbook discovery link in `llms` discovery outputs.

Release outcome:

- Integrations can access a materially wider runtime surface with consistent sync semantics and discovery hints.

## Done (`v0.9.0`)

Target: September 2026

- Shipped schema/OpenAPI-based integration templates for canonical jobs.
- Shipped three tested reference automations with fixture payloads.
- Shipped copy/paste agent starter packs (`curl`, `javascript`, `python`) for onboarding.
- Expanded operator reliability pack with threshold defaults, triage signals, and richer diagnostics bundle snapshots.
- Shipped lifecycle governance controls: ownership metadata mapping, expiry/rotation reminders, and stale-key warnings.
- Removed Return Requests CP surface (tab/routes/forms/permissions) from public operator UX; kept internal control-plane internals feature-flagged for future adapter-based execution.

Release outcome:

- Integrators can move from first call to production patterns faster, and operators get clearer reliability/lifecycle posture without exposing unfinished return workflows.

## Done (`v0.9.1`)

Target: September 2026

- Hidden Lifecycle Governance warning surfaces in the Agents CP view (summary panel + card warning strips) while keeping lifecycle APIs/services intact.

Release outcome:

- Operators get a cleaner Agent card view now, with lifecycle governance still available for future reintroduction without backend rollback.

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
