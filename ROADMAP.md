# Agents Plugin Roadmap

Date: 2026-03-03  
Current release: `v0.4.0`

## Direction

Run two explicit tracks in parallel:

- `Track A (70%)`: runtime reliability and operator safety
- `Track B (30%)`: adoption and agent-facing integration UX

## Now (`v0.5.0`)

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

## Next (`v0.6.0`)

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

## Later (`v0.7.0`)

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

## Success Checks

- Support escalations reduced by at least `30%` by end of `v0.7.0` cycle.
- Time-to-triage for integration incidents below `15` minutes.
- Critical-path regression coverage at or above `90%`.
- Median time-to-first-successful integration action below `30` minutes.
- At least `3` canonical agent jobs are shipped, documented, and validated end-to-end.
- Weekly active credentials trend upward for two consecutive releases.

## Out of Scope (this horizon)

- New major action domains beyond current governed return/control model.
- Large visual redesign unrelated to operator clarity.
- Non-Craft platform expansion.
