# Accounts, Jobs, Boundaries, Approvals, Status & Settings

The Craft control plane is the operator surface for Agents. It is where teams define governed machine identities, attach them to recurring jobs, review approvals, and monitor agent posture.

Managed accounts are used by agents.

You set the boundary in the CP. Your agent works inside it. Agents enforces the rules.

Agents CP is not a shell execution layer. Production behavior still flows through scoped APIs, request validation, policy controls, and audit records.

## Top-Level CP Architecture

The Agents CP subnav currently exposes:

- **Accounts**
  - URL: `admin/agents/accounts`
  - managed machine-account lifecycle, access controls, and reusable backing identities
- **Jobs**
  - URL: `admin/agents/workflows`
  - operator-managed read-only job instances with external execution and bundle export
- **Boundaries**
  - URL: `admin/agents/target-sets`
  - reusable governed-write boundaries for write-capable accounts
- **Approvals**
  - URL: `admin/agents/approvals`
  - shown only when governed-write CP is enabled
- **Status**
  - URL: `admin/agents/status`
- **Settings**
  - URL: `admin/agents/settings`
  - agent switches, operator notifications, webhook transport, reliability thresholds, and config-lock visibility

The section root `admin/agents` opens `Accounts`.

## Status

`Status` is the primary operator surface for agent health.

Current responsibilities:

- overall verdict and readiness posture
- account health and governed-write boundary warnings
- job health, run visibility, and broader-than-needed account warnings
- approval workload visibility
- operator-notification visibility and checks
- webhook readiness, probe, and delivery recovery tools
- diagnostics and observability details

Fresh installs stay `Ready` when Agents is healthy but there is not enough live traffic yet to prove confidence. In that state, missing accounts are setup guidance rather than a hard failure.

## Approvals

`Approvals` appears only when the governed-write CP is enabled.

Current sections:

- `Pending`
- `Approved`
- `Applied / Completed`
- `Runs That Need Follow-up`
- `Activity Log`
- embedded `Rules`
- embedded `Create or Update Rule`

Notable current behavior:

- pending approvals can be reviewed, approved, or rejected from the CP
- governed entry-draft approvals expose separate `Review` and `Diff` actions
- high-risk dual-approval rows show two explicit approval buttons so progress toward the second approval stays visible
- rules can be edited inline from the rules table
- manual request creation now lives in `Status` as an operator fallback/test tool

## Accounts

`Accounts` is the managed machine-identity surface.

This is where operators define the broad governed boundary for each agent.

Current responsibilities:

- lifecycle summary strip (`Managed accounts`, `Paused accounts`, `Need attention`)
- one-time token reveal after create/rotate
- create and edit flows for:
  - display name and handle
  - short operator-facing description
  - scopes, grouped by purpose
  - owner user assignment with legacy-owner fallback
  - pause/resume state
  - force-human-approval mode for write-capable accounts
  - account-specific approval recipients
  - reusable governed-write boundary assignments for bounded draft-write accounts
  - event routing interests
  - TTL and reminder policy
  - IP allowlist
- usage metadata and optional live activity indicator
- account handoff bundle with `.env`, `account.json`, smoke test, and external output-storage guidance
- account templates for common agency jobs
- visibility into job fit and whether an account is broader than its attached jobs

Important boundary:

- scopes and tokens still live on accounts
- accounts are the hard machine-identity and trust-domain layer
- jobs sit on top of those accounts; they do not mint separate tokens in this version

Reusable boundary management lives at `admin/agents/target-sets`, but it no longer appears as a separate `Status` card.

## Jobs

`Jobs` is the recurring work surface.

Current responsibilities:

- registry of configured job instances
- job-type-based creation
- job detail/edit surface
- schedule intent and agent binding visibility
- managed account binding for the job
- explicit job boundary summary
- visible handoff downloads from the registry and detail surface
- handoff bundle with `README.md`, `.env.example`, example script files, explicit API paths, cron example, and output-storage guidance
- recent-run visibility backed by the polling and run-reporting contract

Important boundary:

- Agents stores job intent, account binding, and handoff export
- the actual schedule runner, fetch/reasoning loop, and execution still happen in the agent
- `Latest Run` and `Recent runs` are populated when the bound agent reports lifecycle state through the job API contract
- the first stable slice is read-only and job-type-based, not a generic builder
- write-oriented jobs can land later, but they are intentionally out of scope for this first official surface

The visible page title is **Jobs**, while the route stays `admin/agents/workflows` for now.

## Boundaries

`Boundary` is now the operator term for allowed scope inside a job or governed write lane.

Current behavior:

- read boundaries live on Jobs
- governed-write reusable boundaries are managed through `admin/agents/target-sets`
- Accounts can be assigned those reusable write boundaries for `entry.updateDraft`

This is a surface-first abstraction pass. The underlying target-set storage and enforcement stay intact until a later backend migration is warranted.

## Settings

`Settings` is an admin-only screen.

Current sections:

- **Agent Switches**
- **Operator Notifications**
- **Webhooks**
- **Reliability Thresholds**
- **Configuration Locks**

## Permissions

The current permission model is split into three groups:

- **Agents Access**
  - view managed accounts tab
  - create and edit managed accounts
  - rotate managed account tokens
  - revoke managed account tokens
  - delete managed accounts
- **Agents Jobs**
  - view jobs tab
  - create and edit jobs
- **Agents Approvals**
  - shown only when Approvals is enabled
  - view approvals tab
  - create and edit approval rules
  - approve and reject governed requests
  - run approved governed actions

In addition:

- `Settings` actions require admin access
- `Jobs` requires the corresponding job permissions
- `Approvals` requires the corresponding approvals permissions
- `Accounts` actions require the corresponding Agents Access permissions
- boundary management inherits the same Accounts visibility/manage permissions

## Deep Links

Primary current routes:

- `admin/agents`
- `admin/agents/accounts`
- `admin/agents/workflows`
- `admin/agents/approvals`
- `admin/agents/status`
- `admin/agents/settings`

Secondary route:

- `admin/agents/target-sets`
  - reusable governed-write boundary management

## Agent Boundary Reminder

- `craft agents/*` remains operator/developer tooling
- CP actions are operator jobs and approval flows on top of the governed API/agent model
- machine integrations should still use the HTTP API and contract descriptors, not the CP UI
