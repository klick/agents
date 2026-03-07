# Observability Runbook

This runbook is the operator response path for the reliability pack signals.

## Primary telemetry sources

- CP: `Agents -> Dashboard -> Readiness -> Telemetry Snapshot`
- API: `GET /agents/v1/metrics`
- API health checks: `GET /agents/v1/health`, `GET /agents/v1/readiness`
- CLI: `php craft agents/reliability-check` (add `--strict=1` to fail on warnings)

## Alert thresholds and actions

### Auth failures (`agents_auth_failures_total`)

Trigger:

- Warn: `> 5` in a short window
- Critical: `> 20` in a short window

Actions:

1. Inspect token scope/identity with `GET /agents/v1/auth/whoami`.
2. Rotate or revoke compromised/invalid keys in `Agents -> Agents`.
3. Confirm caller token transport (`Authorization` or `X-Agents-Token`) is correct.

### Scope denials (`agents_forbidden_total`)

Trigger:

- Warn: `> 5`
- Critical: `> 20`

Actions:

1. Review missing-scope responses and affected credential IDs.
2. Confirm least-privilege scopes still cover active automation paths.
3. Reduce unnecessary endpoint fan-out.

### Rate-limit denials (`agents_rate_limit_exceeded_total`)

Trigger:

- Warn: `> 10` in a short window
- Critical: `> 40` in a short window

Actions:

1. Add client-side backoff/jitter.
2. Verify poll cadence and batching behavior.
3. Tune `PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE` / `PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS` only when sustained load justifies it.

### Server errors (5xx) (`agents_errors_5xx_total`)

Trigger:

- Warn: `>= 1`
- Critical: `>= 5`

Actions:

1. Check Craft logs and plugin warnings.
2. Validate readiness summary and component checks.
3. If recurring, treat as incident and capture a diagnostics bundle (logs + request IDs + relevant payload samples).

### Queue depth (`agents_queue_depth`)

Trigger:

- Warn: `> 50`
- Critical: `> 150`

Actions:

1. Verify queue workers are running and draining jobs.
2. Check for blocked jobs and upstream webhook receiver issues.
3. Scale worker throughput before backlog turns into DLQ growth.

### DLQ failed events (`agents_webhook_dlq_failed`)

Trigger:

- Warn: `> 1`
- Critical: `> 10`

Actions:

1. Replay DLQ events from CP/API.
2. Confirm webhook receiver health and signature verification.
3. Correlate repeated failures with endpoint/network incidents.

### Sync-state lag (`agents_consumer_lag_max_seconds`)

Trigger:

- Warn: `> 300s`
- Critical: `> 900s`

Actions:

1. Inspect lag rows via `GET /agents/v1/sync-state/lag`.
2. Re-establish checkpoint updates via `POST /agents/v1/sync-state/checkpoint`.
3. Run a bounded backfill for stale resources, then return to incremental sync.

## Escalation package

When escalating, include:

- timestamp window (UTC)
- affected credential IDs/integration keys
- `X-Request-Id` samples
- current telemetry snapshot (`/metrics` output)
- reliability snapshot (`/metrics` -> `reliability`)
- readiness snapshot (`/readiness` output)
- last DLQ errors and replay attempts
