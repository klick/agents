# Troubleshooting

## Security warning: credentials missing

Symptom:

- guarded endpoints return `503 SERVER_MISCONFIGURED` or `401`

Check:

- token enforcement is on
- no valid credentials are configured

Fix:

- set env credentials or create managed key in CP

## Readiness warning in non-site context

Symptom:

- readiness warning for request-context check

Notes:

- check is expected to pass in CP or site web requests
- CLI execution can still show limited request-context state

## Control endpoints return 404

Symptom:

- `/agents/v1/control/*` returns `404`

Fix:

- enable `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`

## 403 with valid token

Symptom:

- token is accepted but endpoint still blocked

Cause:

- required scope is missing

Fix:

- assign scope to credential (env credential definition or managed key)

## Discovery output appears stale

Fix path:

1. Dashboard -> Discovery -> Refresh All
2. If needed, clear discovery cache
3. Use Craft Clear Caches utility -> `Agents discovery caches`

## Telemetry alert triage

Use the dedicated runbook when metric thresholds are breached:

- [Observability Runbook](/troubleshooting/observability-runbook)
- [Agent Lifecycle Governance](/troubleshooting/agent-lifecycle-governance)
