# Status, Accounts, Discovery Docs & Approvals

The Craft control plane is the operator surface for Agents. It is where teams monitor runtime posture, manage machine accounts, configure discovery and reliability settings, and, when experimental writes are enabled, review governed approval and rule flows.

Agents CP is not a shell execution layer. Production behavior still flows through scoped APIs, request validation, policy controls, and audit records.

## Top-Level CP Architecture

The Agents CP subnav currently exposes:

- **Status**
  - URL: `admin/agents/readiness`
- **Approvals**
  - URL: `admin/agents/control`
  - shown only when governed-write CP is enabled
  - local sidebar tabs: `Approvals`, `Rules`
- **Accounts**
  - URL: `admin/agents/credentials`
  - managed machine-account lifecycle and access controls
- **Discovery Docs**
  - URL: `admin/agents/discovery`
  - discovery-doc editing, cache refresh, enable/disable, and technical status JSON
- **Settings**
  - URL: `admin/agents/settings`
  - runtime switches, reliability thresholds, and config-lock visibility

Legacy aliases still redirect for compatibility:

- `admin/agents`
- `admin/agents/dashboard`
- `admin/agents/overview`
- `admin/agents/dashboard/overview`
- `admin/agents/dashboard/readiness`
- `admin/agents/discovery`
- `admin/agents/credentials/discovery` redirects to `Discovery Docs`
- `admin/agents/dashboard/discovery`
- `admin/agents/security` redirects to the security section inside `Readiness`
- `admin/agents/dashboard/security` redirects to the security section inside `Readiness`
- `admin/agents/health` redirects to Readiness

## Status

`Status` is the primary runtime-operator surface:

- **Readiness**
  - state card with overall verdict (`Ready`, `Degraded`, `Blocked`, `Unproven`)
  - combined summary strip with shared operator dimensions:
    - `Hard Gates`
    - `Traffic / Access`
    - `Delivery / Webhooks`
    - `Integration / Capacity`
    - `Credentials / Policy`
    - `Confidence / Observability`
  - `Details +` disclosure with merged proof panels:
    - `Hard Gates`
    - `Traffic / Access`
    - `Delivery / Webhooks`
    - `Integration / Capacity`
    - `Credentials / Policy`
    - `Confidence / Observability`
  - legacy readiness/security deep-link anchors are preserved inside the merged cards for action-map compatibility
  - in-card `Action Mapping` table that renders only problematic signals
  - security-origin follow-up actions stay in the main readiness `Action Mapping` table
  - `Diagnostics Bundle` download
  - separate dead-letter queue replay section for operational recovery

This page is driven from runtime services, not hardcoded static status:

- readiness and health summaries
- sync-state lag summary
- observability metrics snapshot
- security posture
- webhook dead-letter events

## Approvals

`Approvals` is a separate CP section that appears only when the governed-write CP is enabled. In the current plugin, that follows the same experimental gate as governed-write APIs.

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

`Accounts` replaces the older “Agents” wording in the CP subnav.

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

The visible page title is **Accounts**, and the route is `admin/agents/credentials`.

## Discovery Docs

Current responsibilities:

- `Quick Actions` for refresh and cache clear
- per-document cards for `llms.txt`, `llms-full.txt`, and `commerce.txt`
- inline preview/edit support where allowed
- per-document `Save`, `Reset`, `Refresh`, and `Enable/Disable` actions
- `Editing Path`
- technical status JSON

This view is driven from discovery runtime services and settings:

- discovery document status
- discovery cache freshness
- per-document enable/disable posture
- inline custom-body overrides where allowed

## Settings

`Settings` is an admin-only screen.

Current sections:

- **Runtime Switches**
  - enable/disable Agents API
  - enable governed writing APIs (experimental)
  - enable live usage indicator on Account cards
  - enable/disable `llms.txt`
  - enable/disable `llms-full.txt`
  - enable/disable `commerce.txt`
- **Reliability Thresholds**
  - sync-state lag warn threshold
  - sync-state lag critical threshold
- **Manual Fallback**
  - `Allow manual approval requests in Approvals`
  - shown only when Approvals is available
- **Configuration Locks**
  - explains when values are locked by env vars or `config/agents.php`

The page is also where lock-state is surfaced for values controlled outside the CP.

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
- `admin/agents/readiness`
- `admin/agents/credentials`
- `admin/agents/discovery`
- `admin/agents/credentials/discovery` redirects to `admin/agents/discovery`
- `admin/agents/dashboard/security` redirects to `admin/agents/readiness#securitySnapshotSection`
- `admin/agents/control/approvals`
- `admin/agents/control/rules`
- `admin/agents/settings`

## Runtime Boundary Reminder

- `craft agents/*` remains operator/developer tooling
- CP actions are operator workflows on top of the governed API/runtime model
- machine integrations should still use the HTTP API and contract discovery surfaces, not the CP UI
