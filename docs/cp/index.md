# Dashboard & Control Panel

The Craft control plane is where teams manage the trust boundary for machine access: credentials, scopes, readiness, discovery posture, webhook reliability, and governed write workflows.

## Information Architecture

Agents CP has these subnav items:

- **Dashboard**
- **Settings**
- **Agents**

Trust boundary reminder:

- CP is an operator control surface for governed runtime actions.
- It is where managed credentials, scopes, approvals, and auditability are administered.
- It is not a shell execution layer for arbitrary agent commands.

## Dashboard Tabs

Dashboard groups four operational panes:

- **Overview**
  - service state snapshot
  - diagnostics bundle download action
  - quick links to endpoints
- **Readiness**
  - readiness score and weighted checks
  - component checks and warnings
  - telemetry snapshot (runtime counters, queue, DLQ, lag, active credentials)
  - runbook + alert guidance for first-response triage
- **Discovery Docs**
  - `llms.txt` and `commerce.txt` status
  - `llms-full.txt` status when enabled
  - cache metadata, previews, and refresh actions
- **Security**
  - auth posture
  - credential counts
  - rate limit and privacy posture
  - webhook posture and warnings
  - approval posture and governed-write status when enabled

## Settings

`Agents -> Settings` controls runtime toggles and discovery behavior, including:

- live usage indicator toggle for agent activity dots
- discovery enablement toggles
- editable custom body fields for `llms.txt` and `commerce.txt`
- reset-to-generated actions for both custom body fields

## Agents

`Agents -> Agents` provides:

- managed agent create/edit
- scope selection
- one-time token reveal on create/rotate
- pause / resume / rotate / revoke / revoke+rotate / delete actions
- live card-dot activity indicator for recent read/write usage (when enabled)
- usage metadata (last used time/ip/auth method)
- lifecycle governance summary cards (critical/warn/stale/owner-missing)
- per-agent lifecycle risk detail (owner/use-case/environment + warning factor)
- per-key webhook resource/action subscriptions
- optional key expiry + reminder policy
- optional IP allowlist (CIDR)
- explicit assurance modes for approval records when governed writes are enabled

## CLI vs runtime

- `craft agents/*` is for operator/developer workflows (diagnostics, checks, local automation).
- Production runtime behavior is governed by API scopes, policies, approvals (when enabled), and audit trail.

## Deep links

Canonical Dashboard deep links:

- `admin/agents/dashboard/overview`
- `admin/agents/dashboard/readiness`
- `admin/agents/dashboard/discovery`
- `admin/agents/dashboard/security`

Legacy aliases are still redirected for compatibility.
