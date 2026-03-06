# Agent Lifecycle Governance (Read-Only First)

This guide explains how to monitor agent ownership and lifecycle risk without introducing new write actions.

## What this adds

- API snapshot endpoint: `GET /agents/v1/lifecycle`
- CLI report: `php craft agents/lifecycle-report [--json=1] [--strict=1]`
- Control Panel visibility in `Agents -> Agents`:
  - lifecycle summary cards (critical, warning, stale, missing owner)
  - per-agent lifecycle risk and warning detail

No auto-pause/revoke or policy mutation is performed in this track.

## Scope

Required scope:

- `lifecycle:read`

## Metadata mapping (optional)

Owner can now be set directly in CP when creating/editing an agent:

- `Agents -> Agents -> Add/Edit -> Owner`
- Create mode defaults owner to the current CP user email.

You can also map ownership/use-case/environment by credential handle with:

- `PLUGIN_AGENTS_LIFECYCLE_METADATA_MAP`

JSON shape:

```json
{
  "agent-catalog-sync": {
    "owner": "platform-team",
    "useCase": "catalog sync",
    "environment": "production"
  },
  "agent-support-ops": {
    "owner": "support-team",
    "useCase": "support context enrichment",
    "environment": "staging"
  }
}
```

If metadata is missing, the snapshot falls back to inferred values (for example `owner=unassigned`).

## Threshold tuning

All threshold vars are optional:

- `PLUGIN_AGENTS_LIFECYCLE_STALE_UNUSED_WARN_DAYS` (default `30`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_UNUSED_CRITICAL_DAYS` (default `90`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_NEVER_USED_WARN_DAYS` (default `30`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_NEVER_USED_CRITICAL_DAYS` (default `90`)
- `PLUGIN_AGENTS_LIFECYCLE_ROTATION_WARN_DAYS` (default `45`)
- `PLUGIN_AGENTS_LIFECYCLE_ROTATION_CRITICAL_DAYS` (default `120`)

## Recommended operating cadence

- Daily: check CP lifecycle cards for critical counts.
- Weekly: run `php craft agents/lifecycle-report --strict=1 --json=1` in CI/ops checks.
- Monthly: rotate long-lived credentials and update metadata ownership map.

## Interpreting risk factors

Common factors include:

- `expired` / `expiring_soon`
- `paused` / `revoked`
- `never_used` / `stale_usage`
- `rotation_due` / `rotation_overdue`
- `environment_mismatch`
- `no_expiry_policy`

The `recommendedAction` field in API/CLI output gives the primary next step.

## Deferred to write track

- In-product ownership editing APIs
- Lifecycle policy auto-enforcement
- Automatic pause/revoke actions from lifecycle rules
