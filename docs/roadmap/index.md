# Roadmap

Last updated: 2026-03-13  
Current release: `v0.21.6`

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

- Ship observability baseline: metrics taxonomy and naming.
- Ship observability baseline: runtime metrics collection.
- Ship observability baseline: metrics export endpoint.
- Ship observability baseline: CP telemetry snapshot.
- Ship observability baseline: runbook and alert guidance.
- Add adoption instrumentation: first-call success funnel.
- Add adoption instrumentation: time-to-first-success metric.
- Add adoption instrumentation: credential activation and weekly usage tracking.

Release outcome:

- Operators can triage incidents quickly, and product teams can see where integration adoption drops.
- `v0.6.1` hotfix closed runtime reliability issues in adoption metrics, CSRF handling for machine POST routes, and dual-approval race protection.
- `v0.6.2` corrected release-version metadata/tag alignment for plugin store ingestion.

## Done (`v0.7.0`)

- Ship one-click diagnostics bundle: contract and redaction policy.
- Ship one-click diagnostics bundle: diagnostics engine.
- Ship one-click diagnostics bundle: CP download flow.
- Ship one-click diagnostics bundle: CLI companion command.
- Improve integrator DX with schema/OpenAPI-based templates.
- Publish reference automations for the canonical jobs with tested sample payloads.

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

## Planned (`v0.11.0`)

- Add external plugin data adapters for agent accounts (read-only first).
- Introduce provider registry + registration event so integrations can be added without hardcoded controller logic.
- Add explicit plugin/resource scopes for external data (for example `plugins:seomatic:meta:read`).
- Expose registered external resources in capabilities, OpenAPI, and schema outputs.
- Ship first-party reference adapters for SEOmatic and Campaign (when installed).

Release outcome:

- Teams can let agents safely consume data from selected Craft plugins using the same governed account model.

## Success checks

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
