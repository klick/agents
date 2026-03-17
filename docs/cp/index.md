# Accounts, Approvals, Status & Settings

The Craft control plane is the operator surface for Agents. It is where teams monitor runtime posture, manage machine accounts, configure runtime behavior, and, when experimental writes are enabled, review governed approval and rule flows.

Agents CP is not a shell execution layer. Production behavior still flows through scoped APIs, request validation, policy controls, and audit records.

## Top-Level CP Architecture

The Agents CP subnav currently exposes:

- **Accounts**
  - URL: `admin/agents/accounts`
  - managed machine-account lifecycle and access controls
- **Approvals**
  - URL: `admin/agents/approvals`
  - shown only when governed-write CP is enabled
- **Status**
  - URL: `admin/agents/status`
- **Settings**
  - URL: `admin/agents/settings`
  - runtime switches, operator notifications, webhook transport, reliability thresholds, and config-lock visibility

The section root `admin/agents` opens `Accounts`.

## Status

`Status` is the primary runtime-operator surface:

- **Readiness**
  - state card with overall verdict (`Ready`, `Degraded`, `Blocked`, `Unproven`)
  - combined summary strip with shared operator dimensions:
    - `Hard Gates`
    - `Traffic / Access`
    - `Delivery / Webhooks`
    - `Integration / Capacity`
    - `Accounts / Policy`
    - `Confidence / Observability`
  - `Details +` disclosure with merged proof panels for the same six domains
  - in-card `Action Mapping` table that renders only problematic signals
  - `Diagnostics Bundle` download
  - production `Webhook Probe` card for synthetic signed delivery against the live receiver
  - separate dead-letter queue replay section for operational recovery
  - dev-only `Webhook Test Sink` section when local sink capture is enabled
  - `Operator Notifications` card when notifications are enabled, with:
    - resolved recipient visibility
    - recent delivery outcomes
    - manual `Run status check` action

Fresh installs now stay `Ready` when the runtime is healthy but there is not enough live traffic yet to prove readiness. In that state, `Confidence / Observability` can read `Building`, sync-state remains optional until a worker starts reporting checkpoints, and missing accounts are treated as setup guidance rather than a hard failure. `Unproven` is reserved for actual monitoring gaps such as stale metrics or missing reliability evaluation.

This page is driven from runtime services, not hardcoded status:

- readiness and health summaries
- sync-state lag summary
- observability metrics snapshot
- security posture
- webhook probe ledger
- webhook dead-letter events

## Approvals

`Approvals` appears only when the governed-write CP is enabled.

Current sections:

- `Waiting for Decision`
- `Approved`
- `Applied / Completed`
- `Runs That Need Follow-up`
- `Activity Log`
- embedded `Rules`
- embedded `Create or Update Rule`

Notable current behavior:

- pending approvals can be reviewed, approved, or rejected from the CP
- governed entry-draft approvals expose separate `Review` and `Diff` actions so content inspection is not overloaded onto the generic review flow
- `Diff` opens a changed-only review modal with:
  - `Structured` field-aware before/after rows
  - `Focus` text-proofing review with `After / Before` switching for canonical-vs-requested reading
- high-risk dual-approval rows show two explicit approval buttons so operators can see progress toward the second approval at a glance
- decision buttons stack vertically at a consistent width for clearer review actions
- review UI includes action labels, rule/risk context, assurance mode, SLA metadata, and review guards
- when a saved draft is linked, the diff surface targets that exact draft; otherwise it falls back quietly to requested-versus-canonical comparison
- approved draft-entry flows bind to the exact saved draft created by execution so `Review` and `Apply Draft` target a stable draft identity
- governed draft execution is blocked when the canonical entry already has another saved draft and the follow-up surfaces the conflicting draft ids and draft links for operator cleanup
- rules can be edited and deleted inline from the embedded rules table
- rule forms use a human-readable governed-action selector for the current core action set

## Accounts

`Accounts` is the managed machine-identity surface.

Current responsibilities:

- lifecycle summary strip (`Managed accounts`, `Paused accounts`, `Need attention`)
- one-time token reveal after create/rotate as an in-card overlay on the affected account
- Craft-style managed-account registry with:
  - default table view for comparison and operations
  - alternate card view for lower-count overview and onboarding
- create and edit flows for:
  - display name and handle
  - short operator-facing description shown on the account card
  - scopes, grouped by purpose with short operator guidance
  - owner user assignment with legacy-owner fallback
  - pause/resume state
  - force-human-approval mode for write-capable accounts
  - account-specific approval recipients
  - event routing interests (resource/action subscriptions)
  - TTL and reminder policy
  - IP allowlist
- lifecycle risk details per account
- usage metadata and optional live activity indicator
- dedicated account-template section below the create form
- suggested account profiles for common core-Craft integration shapes
- Commerce-only scopes appear only when Craft Commerce is installed

The visible page title is **Accounts**, and the route is `admin/agents/accounts`.

## Settings

`Settings` is an admin-only screen.

Current sections:

- **Runtime Switches**
  - enable/disable Agents API
  - enable governed writing APIs (experimental)
  - enable live usage indicator on Account cards
- **Operator Notifications**
  - email-first recipient list
  - approval requested / approval decided toggles
  - execution issue toggle
  - webhook DLQ failure toggle
  - scheduled system-status transition toggle
  - cron entry point: `php craft agents/notifications-check`
  - `Status` shows recent notification deliveries and lets admins run the status check manually
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
- `admin/agents/accounts`
- `admin/agents/approvals`
- `admin/agents/status`
- `admin/agents/approvals/rules`
- `admin/agents/settings`

## Runtime Boundary Reminder

- `craft agents/*` remains operator/developer tooling
- CP actions are operator workflows on top of the governed API/runtime model
- machine integrations should still use the HTTP API and contract descriptors, not the CP UI
