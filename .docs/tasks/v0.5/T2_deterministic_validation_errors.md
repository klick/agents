# T2 Deterministic Validation and Errors

depends_on: [T1]
track: A
status: pending

## Objective

Ensure malformed requests and unsupported values consistently return deterministic status/code/message envelopes.

## Acceptance Criteria

- Common invalid query/body branches return stable 400 envelopes.
- Forbidden and not-found branches use stable code/message families.
- Regression checks cover representative invalid requests.
