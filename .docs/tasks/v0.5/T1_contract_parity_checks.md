# T1 Contract Parity Checks (API/Scope/Docs)

depends_on: []
track: A
status: completed

## Objective

Add an automated gate to detect endpoint and scope drift between runtime API contracts and published docs.

## Scope

- Capabilities endpoints parity
- OpenAPI path parity
- Route registration parity
- README endpoint and scope catalog parity

## Acceptance Criteria

- CI/local gate fails on contract drift.
- Actionable diff output points to missing/extra endpoints or scopes.
- Integrated into `scripts/qa/release-gate.sh`.
