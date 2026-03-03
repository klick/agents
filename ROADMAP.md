# Agents Plugin Roadmap

Date: 2026-03-03  
Current release: `v0.4.0`

## Direction

Focus on reliability first, then adoption:

- `70%` polish/hardening
- `30%` net-new features

## Now (`v0.5.0`)

Target: April 2026

- Harden runtime contracts (API/scope/docs parity).
- Improve operator UX in CP (clearer queue/actions/warnings).
- Tighten validation and deterministic error behavior.
- Expand regression coverage for auth/control/webhook/consumer paths.
- Validate upgrade and migration safety.

Release outcome:

- Current governed-runtime features are stable and predictable in production.

## Next (`v0.6.0`)

Target: May 2026

- Ship observability baseline:
  - metrics taxonomy and naming
  - runtime metrics collection
  - metrics export endpoint
  - CP telemetry snapshot
  - runbook and alert guidance

Release outcome:

- Operators can detect and triage common incidents quickly from metrics.

## Later (`v0.7.0`)

Target: July 2026

- Ship one-click diagnostics bundle:
  - contract + redaction policy
  - diagnostics engine
  - CP download flow
  - CLI companion command
- Improve integrator DX with schema/OpenAPI-based templates.

Release outcome:

- Faster support resolution and faster integration onboarding.

## Success Checks

- Support escalations reduced by at least `30%` by end of `v0.7.0` cycle.
- Time-to-triage for integration incidents below `15` minutes.
- Critical-path regression coverage at or above `90%`.

## Out of Scope (this horizon)

- New major action domains beyond current governed return/control model.
- Large visual redesign unrelated to operator clarity.
- Non-Craft platform expansion.
