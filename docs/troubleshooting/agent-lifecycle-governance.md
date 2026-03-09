# Agent Lifecycle Governance

Use lifecycle governance to answer two questions quickly:

- Who owns this agent?
- Is this agent healthy to keep in production use?

This surface is read-only-first in v0.9 Step 6.

## Surfaces

- API: `GET /agents/v1/lifecycle`
- CLI: `php craft agents/lifecycle-report [--json=1] [--strict=1]`
- CP: `Agents -> Agents` lifecycle summary cards and per-agent warnings

Required scope:

- `lifecycle:read`

## Ownership mapping

Optional env var:

- `PLUGIN_AGENTS_LIFECYCLE_METADATA_MAP`

JSON format:

```json
{
  "agent-catalog-sync": {
    "owner": "platform-team",
    "useCase": "catalog sync",
    "environment": "production"
  }
}
```

If no metadata is present, owner defaults to `unassigned`.

## Risk factors reported

- expired / expiring soon
- paused / revoked
- stale usage / never used
- rotation due / rotation overdue
- no expiry policy
- environment mismatch

Each agent includes a `recommendedAction` string for first response.

## Threshold tuning

- `PLUGIN_AGENTS_LIFECYCLE_STALE_UNUSED_WARN_DAYS` (default `30`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_UNUSED_CRITICAL_DAYS` (default `90`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_NEVER_USED_WARN_DAYS` (default `30`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_NEVER_USED_CRITICAL_DAYS` (default `90`)
- `PLUGIN_AGENTS_LIFECYCLE_ROTATION_WARN_DAYS` (default `45`)
- `PLUGIN_AGENTS_LIFECYCLE_ROTATION_CRITICAL_DAYS` (default `120`)

## Suggested cadence

- Daily: check CP critical counts.
- Weekly: run `php craft agents/lifecycle-report --strict=1 --json=1`.
- Monthly: rotate long-lived credentials and refresh ownership metadata.

## Deferred (write track)

- lifecycle policy auto-enforcement
- automatic pause/revoke actions
- in-product ownership write workflows
