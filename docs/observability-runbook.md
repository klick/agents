# Observability Runbook

This runbook is the operator response path for `v0.6` telemetry signals.

## Primary telemetry sources

- CP: `Agents -> Dashboard -> Readiness -> Telemetry Snapshot`
- API: `GET /agents/v1/metrics`
- API health checks: `GET /agents/v1/health`, `GET /agents/v1/readiness`

## Alert thresholds and actions

### Auth failures

Trigger:

- More than `5` failures in a short window.

Actions:

1. Inspect token scope/identity with `GET /agents/v1/auth/whoami`.
2. Rotate or revoke compromised/invalid keys in `Agents -> API Keys`.
3. Confirm caller token transport (`Authorization` or `X-Agents-Token`) is correct.

### Rate-limit denials

Trigger:

- More than `10` denials in a short window.

Actions:

1. Add client-side backoff/jitter.
2. Verify poll cadence and batching behavior.
3. Tune `PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE` / `PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS` only when sustained load justifies it.

### Server errors (5xx)

Trigger:

- Any repeated `5xx` response.

Actions:

1. Check Craft logs and plugin warnings.
2. Validate readiness summary and component checks.
3. If recurring, treat as incident and capture a diagnostics bundle (logs + request IDs + relevant payload samples).

### Queue depth and DLQ

Trigger:

- Queue depth above `50` or DLQ failed events above `0`.

Actions:

1. Replay DLQ events from CP/API.
2. Confirm webhook receiver health and signature verification.
3. Verify queue workers are running and draining jobs.

### Consumer lag

Trigger:

- Max lag above `300s`.

Actions:

1. Inspect lag rows via `GET /agents/v1/consumers/lag`.
2. Re-establish checkpoint updates via `POST /agents/v1/consumers/checkpoint`.
3. Run a bounded backfill for stale resources, then return to incremental sync.

## Escalation package

When escalating, include:

- timestamp window (UTC)
- affected credential IDs/integration keys
- `X-Request-Id` samples
- current telemetry snapshot (`/metrics` output)
- readiness snapshot (`/readiness` output)
- last DLQ errors and replay attempts
