# Validation Report: v0.4.0 Release

Date: 2026-03-02  
Branch task: `release v0.4.0`

## Commands Executed

- `bash scripts/qa/release-gate.sh`

## Result

- `PASS` composer validation (with expected informational warning about `version` field).
- `PASS` PHP lint across `src/`.
- `PASS` version consistency (`composer.json` and README aligned at `0.4.0`).
- `PASS` required endpoint documentation checks.
- `PASS` webhook regression checks.
- `PASS` credential lifecycle regression checks.
- `SKIP` optional live regression checks (no `BASE_URL` and `TOKEN` provided).

## Notes

- Release includes control-plane governance expansions (dry-run simulation, dual approvals, SLA escalation/expiry), credential hardening (expiry + CIDR allowlists), webhook DLQ replay, consumer lag telemetry, response projection/filtering, and versioned schema catalog endpoint.
