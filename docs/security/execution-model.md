# Execution Model

Agents is the governed machine-access layer for Craft CMS and Craft Commerce.

## Trust boundary

- Production actions execute through scoped API routes and policy controls.
- Runtime behavior is deterministic: request validation, stable error codes, auditable records.
- Managed credentials, scopes, and optional approvals define the production control boundary.
- The plugin does not execute agent-provided shell commands as part of production action handling.
- CLI commands (`craft agents/*`) are operator/developer tools for diagnostics and workflow support.

## Surface stability matrix

| Surface | Status | Notes |
| --- | --- | --- |
| Read/sync API (`/health`, `/readiness`, `/auth/whoami`, `/products`, `/variants*`, `/subscriptions*`, `/transfers*`, `/donations*`, `/orders*`, `/entries*`, `/assets*`, `/categories*`, `/tags*`, `/global-sets*`, `/addresses*`, `/content-blocks*`, `/users*`, `/changes`, `/sections`) | Production stable | Token/scopes + deterministic error contract. |
| Integration state API (`/sync-state/lag`, `/sync-state/checkpoint`, `/templates`, `/starter-packs`, `/schema`, `/lifecycle`, `/incidents`) | Production stable | Checkpoint/lag, schema/template contracts, lifecycle governance, and redacted runtime incident visibility. |
| Contract descriptors (`/capabilities`, `/openapi.json`, root aliases) | Production stable | Canonical machine contract discovery. |
| Webhook delivery + DLQ replay (`/webhooks/dlq`, `/webhooks/dlq/replay`) | Production stable | Signed payloads, retries, dead-letter replay. |
| Credential controls (scopes, targeted event-routing interests, TTL/reminders, IP allowlists) | Production stable | Managed in CP, enforced at runtime. |
| CLI (`craft agents/*`) | Production stable (ops tooling) | Operator/dev workflows; not runtime control plane. |
| Control-plane execution (`/control/*`, governed-write workflows) | Experimental | Enabled only by `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`. |

## Why this model

- Keeps production behavior auditable and policy-constrained.
- Gives AI agents, automations, and integrations one consistent access surface instead of custom endpoint sprawl.
- Avoids broad shell-execution risk in multi-tenant/production environments.
- Preserves CLI velocity for operators without making CLI the runtime trust boundary.
- Makes readiness, sync-state, lifecycle posture, and incident visibility part of the operating model instead of afterthoughts.

See [Compatibility & Deprecations](/api/compatibility-and-deprecations) for upgrade and contract-change policy.
