# Agents

Safe AI and automation APIs for Craft CMS and Craft Commerce.

Agents gives Craft a safe API and control plane for AI agents, automations, and integrations. It provides one governed machine-access layer with scoped APIs, managed credentials, diagnostics, and optional approval controls, so production behavior stays predictable, observable, and auditable.

Current plugin version: **0.10.6**

## Why Teams Use Agents

- Connect:
  - structured machine access to Craft CMS and Craft Commerce data and actions
- Control:
  - managed credentials, scopes, policies, approvals, and audit trail in the Craft CP
- Operate:
  - readiness, diagnostics, sync-state visibility, webhook reliability, and lifecycle posture

## What Agents Provides

- Structured API access:
  - `/agents/v1/health`
  - `/agents/v1/readiness`
  - `/agents/v1/auth/whoami`
  - `/agents/v1/adoption/metrics`
  - `/agents/v1/metrics`
  - `/agents/v1/incidents`
  - `/agents/v1/lifecycle`
  - `/agents/v1/diagnostics/bundle`
  - `/agents/v1/products`
  - `/agents/v1/variants`, `/agents/v1/variants/show`
  - `/agents/v1/subscriptions`, `/agents/v1/subscriptions/show`
  - `/agents/v1/transfers`, `/agents/v1/transfers/show`
  - `/agents/v1/donations`, `/agents/v1/donations/show`
  - `/agents/v1/orders`, `/agents/v1/orders/show`
  - `/agents/v1/entries`, `/agents/v1/entries/show`
  - `/agents/v1/assets`, `/agents/v1/assets/show`
  - `/agents/v1/categories`, `/agents/v1/categories/show`
  - `/agents/v1/tags`, `/agents/v1/tags/show`
  - `/agents/v1/global-sets`, `/agents/v1/global-sets/show`
  - `/agents/v1/addresses`, `/agents/v1/addresses/show` (flag-gated)
  - `/agents/v1/content-blocks`, `/agents/v1/content-blocks/show`
  - `/agents/v1/users`, `/agents/v1/users/show` (flag-gated)
  - `/agents/v1/changes`
  - `/agents/v1/sections`
  - `/agents/v1/sync-state/lag`
  - `/agents/v1/sync-state/checkpoint`
  - `/agents/v1/templates`
  - `/agents/v1/starter-packs`
  - `/agents/v1/schema`
- Discovery docs:
  - `/llms.txt`
  - `/llms-full.txt`
  - `/commerce.txt`
  - Vendor agent handbook (Markdown): `https://marcusscheller.com/docs/agents/agent-handbook.md`
- API contract discovery:
  - `/agents/v1/capabilities`
  - `/agents/v1/openapi.json`
  - aliases: `/capabilities`, `/openapi.json`
- Webhook reliability:
  - signed webhook delivery with retries
  - per-key resource/action subscriptions
  - dead-letter queue visibility + replay (`/agents/v1/webhooks/dlq*`)
- Governed control-plane flows (feature-flagged):
  - policies
  - approvals with explicit assurance modes and audit trail
  - dry-run policy simulation
  - idempotent action execution
  - immutable audit trail
- Craft CP operations:
  - Dashboard tabs (Overview, Readiness, Discovery Docs, Security)
  - Settings (including editable `llms.txt` and `commerce.txt` custom bodies)
  - Agents (scopes, webhook subscriptions, TTL/reminder, IP allowlists, pause/resume)

## Trust Boundary

- Production actions flow through scoped HTTP APIs, request validation, policy gates, and audit records.
- The plugin is not a shell execution layer for production agents.
- `craft agents/*` commands are operator and developer tooling, not the production trust boundary.
- Discovery docs (`llms.txt`, `llms-full.txt`, `commerce.txt`) are optional public discovery surfaces when enabled.
- Experimental governed-write surfaces remain behind `PLUGIN_AGENTS_WRITES_EXPERIMENTAL`.

See [Execution Model](/security/execution-model) for explicit trust-boundary and stability details.

## Start Here

1. [Installation & Setup](/get-started/installation-setup)
2. [Configuration](/get-started/configuration)
3. [Get Started](/get-started/)
4. [Dashboard & Control Panel](/cp/)
5. [API Overview](/api/)
6. [Agent Bootstrap](/api/agent-bootstrap)
7. [Starter Packs](/api/starter-packs)
8. [Security](/security/)
