# Validation Report: v0.5.0 Release

Date: 2026-03-03  
Branch task: `release v0.5.0`

## Commands Executed

- `bash scripts/qa/release-gate.sh`

## Result

- `PASS` composer validation (with expected informational warning about `version` field).
- `PASS` PHP lint across `src/`.
- `PASS` version consistency (`composer.json` and README aligned at `0.5.0`).
- `PASS` API/scope/docs contract parity checks.
- `PASS` deterministic validation regression checks.
- `PASS` control/consumer surface regression checks.
- `PASS` migration safety checks.
- `PASS` required endpoint documentation checks.
- `PASS` webhook regression checks.
- `PASS` credential lifecycle regression checks.
- `SKIP` optional live regression checks (no `BASE_URL` and `TOKEN` provided).

## Notes

- Release includes CP UX clarity improvements, deterministic query validation hardening, adoption instrumentation endpointing (`/agents/v1/adoption/metrics`), and expanded QA gate coverage.
- Adoption onboarding assets shipped in-repo: `docs/canonical-first-agent-jobs.md` and `docs/quickstart-30min.md`.
