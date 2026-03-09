# Observability Runbook

Use this runbook when telemetry signals indicate runtime degradation.

## Signals to watch

- `GET /agents/v1/metrics`
- `GET /agents/v1/incidents` (`incidents:read`, strict-redacted)
- Dashboard -> Readiness -> Telemetry Snapshot
- `GET /agents/v1/readiness`
- `php craft agents/reliability-check --json=1`

Key metrics:

- `agents_auth_failures_total`
- `agents_consumer_lag_max_seconds`

## Alert guidance

### Auth failures > 5

- Inspect caller identity/scopes with `GET /agents/v1/auth/whoami`.
- Rotate/revoke affected credentials.

### Rate-limit denials > 10

- Add backoff/jitter to callers.
- Tune `PLUGIN_AGENTS_RATE_LIMIT_*` only when sustained demand requires it.

### Repeated 5xx responses

- Check Craft logs and readiness diagnostics.
- Inspect `GET /agents/v1/incidents?severity=critical&limit=20` for correlated runtime signals.
- Escalate with request IDs and sample payloads.

### Queue depth > 50 or DLQ > 0

- Replay DLQ events.
- Verify queue workers and webhook receiver availability.

### Sync-state lag > 300s

- Inspect lag rows in `/agents/v1/sync-state/lag`.
- Restore checkpoint writes in `/agents/v1/sync-state/checkpoint`.
- Run `php craft agents/reliability-check --json=1` to confirm the current lag posture and threshold state.
