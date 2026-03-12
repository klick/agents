# Status, Accounts, Settings & Approvals

The Craft control plane is the operator surface for Agents. It is where teams monitor runtime posture, manage machine accounts, configure runtime behavior, and, when experimental writes are enabled, review governed approval and rule flows.

Agents CP is not a shell execution layer. Production behavior still flows through scoped APIs, request validation, policy controls, and audit records.

## Top-Level CP Architecture

The Agents CP subnav currently exposes:

- **Status**
  - URL: `admin/agents/status`
- **Approvals**
  - URL: `admin/agents/approvals`
  - shown only when governed-write CP is enabled
  - local sidebar tabs: `Approvals`, `Rules`
- **Accounts**
  - URL: `admin/agents/accounts`
  - managed machine-account lifecycle and access controls
- **Settings**
  - URL: `admin/agents/settings`
  - runtime switches, webhook transport, reliability thresholds, and config-lock visibility

The section root `admin/agents` opens `Status`.

## Status

`Status` is the primary runtime-operator surface:

- **Readiness**
  - state card with overall verdict (`Ready`, `Ready to Connect`, `Degraded`, `Blocked`, `Unproven`)
  - combined summary strip with shared operator dimensions:
    - `Hard Gates`
    - `Traffic / Access`
    - `Delivery / Webhooks`
    - `Integration / Capacity`
    - `Credentials / Policy`
    - `Confidence / Observability`
  - `Details +` disclosure with merged proof panels for the same six domains
  - in-card `Action Mapping` table that renders only problematic signals
  - `Diagnostics Bundle` download
  - separate dead-letter queue replay section for operational recovery
  - dev-only `Webhook Test Sink` section when local sink capture is enabled

Fresh installs bias toward `Ready to Connect` when the runtime is healthy but there is not enough live traffic yet to prove readiness. `Confidence / Observability` can still read `Unproven`, and sync-state remains optional until a worker starts reporting checkpoints.

This page is driven from runtime services, not hardcoded status:

- readiness and health summaries
- sync-state lag summary
- observability metrics snapshot
- security posture
- webhook dead-letter events

## Approvals

`Approvals` appears only when the governed-write CP is enabled.

Local tabs:

- **Approvals**
  - metric strip for pending, expired, blocked, completed, and activity counts
  - `Waiting for Decision`
  - `Approved`
  - `Applied / Completed`
  - `Runs That Need Follow-up`
  - `Activity Log`
- **Rules**
  - `Policy Simulator (Dry Run)`
  - `Latest Dry-Run Result`
  - `Rules`
  - inline rule create/update form when permitted

Notable current behavior:

- pending approvals can be reviewed, approved, or rejected from the CP
- review UI includes action labels, rule/risk context, assurance mode, SLA metadata, and review guards
- approved draft-entry flows can expose `Apply Draft` actions when the underlying state allows it
- rules support wildcard action matching, risk levels, approval requirements, enable/disable state, and advanced JSON config

## Accounts

`Accounts` is the managed machine-identity surface.

Current responsibilities:

- lifecycle summary strip (`Managed accounts`, `Paused accounts`, `Need attention`)
- one-time token reveal after create/rotate
- card-based managed-account overview
- create and edit flows for:
  - display name and handle
  - scopes
  - owner metadata
  - pause/resume state
  - force-human-approval mode for write-capable accounts
  - event routing interests (resource/action subscriptions)
  - TTL and reminder policy
  - IP allowlist
- lifecycle risk details per account
- usage metadata and optional live activity indicator
- suggested account profiles for common integration shapes

The visible page title is **Accounts**, and the route is `admin/agents/accounts`.

## Settings

`Settings` is an admin-only screen.

Current sections:

- **Runtime Switches**
  - enable/disable Agents API
  - enable governed writing APIs (experimental)
  - enable live usage indicator on Account cards
- **Webhooks**
  - env-aware runtime webhook target field
  - env-aware webhook signing secret field
  - intended for env variable references, not inline production secrets
- **Reliability Thresholds**
  - sync-state lag warn threshold
  - sync-state lag critical threshold
- **Manual Fallback**
  - `Allow manual approval requests in Approvals`
  - shown only when Approvals is available
- **Configuration Locks**
  - explains when values are locked by env vars or `config/agents.php`

## Permissions

The current permission model is split into two groups:

- **Agents Access**
  - view managed accounts tab
  - create and edit managed accounts
  - rotate managed account tokens
  - revoke managed account tokens
  - delete managed accounts
- **Agents Approvals**
  - shown only when Approvals is enabled
  - view approvals tab
  - create and edit approval rules
  - approve and reject governed requests
  - run approved governed actions

In addition:

- `Settings` actions require admin access
- `Approvals` requires the corresponding approvals permissions
- `Accounts` actions require the corresponding Agents Access permissions

## Deep Links

Primary current routes:

- `admin/agents`
- `admin/agents/status`
- `admin/agents/accounts`
- `admin/agents/approvals/approvals`
- `admin/agents/approvals/rules`
- `admin/agents/settings`

## Runtime Boundary Reminder

- `craft agents/*` remains operator/developer tooling
- CP actions are operator workflows on top of the governed API/runtime model
- machine integrations should still use the HTTP API and contract descriptors, not the CP UI
