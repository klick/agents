# Agents

Safe AI and automation APIs for Craft CMS and Craft Commerce.

Current plugin version: **0.10.6**

## Purpose

Agents gives Craft a safe API and control plane for AI agents, automations, and integrations. It is the governed machine-access layer for Craft CMS and Craft Commerce, combining scoped APIs, managed credentials, diagnostics, and optional approval controls so production behavior stays predictable, observable, and auditable.

Teams use Agents to:

- Connect:
  - expose structured machine access to Craft and Commerce data and actions
- Control:
  - manage credentials, scopes, policies, approvals, and audit trail in the Craft CP
- Operate:
  - monitor readiness, diagnostics, sync-state, lifecycle posture, and webhook reliability

Discovery docs (`/llms.txt`, `/llms-full.txt`, `/commerce.txt`) are optional public discovery surfaces. They are not the core product boundary.

## Execution Model & Trust Boundary

Agents is not a chatbot plugin, prompt layer, or shell execution surface. In production, machine actions execute through scoped HTTP APIs, request validation, policy controls, and auditable records.

Trust boundary summary:

- Runtime execution path: HTTP APIs + scoped auth + deterministic validation/error contracts.
- Control path (feature-flagged): policy checks + approvals + idempotent execution + audit trail.
- Operations path: readiness, incidents, lifecycle posture, sync-state, and webhook diagnostics.
- CLI path: `craft agents/*` is for operator and developer workflows, not the production trust boundary.
- Discovery docs path: `llms.txt`, `llms-full.txt`, and `commerce.txt` are optional public discovery surfaces.

The plugin does not execute agent-provided shell commands as part of production request handling.

## Surface Stability

| Surface | Status | Notes |
| --- | --- | --- |
| Read/sync API (`/health`, `/readiness`, `/auth/whoami`, `/products`, `/variants*`, `/subscriptions*`, `/transfers*`, `/donations*`, `/orders*`, `/entries*`, `/assets*`, `/categories*`, `/tags*`, `/global-sets*`, `/addresses*`, `/content-blocks*`, `/users*`, `/changes`, `/sections`) | Production stable | Structured machine access to Craft and Commerce data with scoped auth and deterministic errors. |
| Integration state API (`/sync-state/lag`, `/sync-state/checkpoint`, `/templates`, `/starter-packs`, `/schema`, `/lifecycle`, `/incidents`) | Production stable | Sync-state, schema/template contracts, lifecycle governance, and redacted runtime incident visibility. |
| Discovery descriptors (`/capabilities`, `/openapi.json`, root aliases) | Production stable | Machine-readable capability and contract discovery. |
| Webhooks + DLQ (`/webhooks/dlq`, `/webhooks/dlq/replay`) | Production stable | Signed delivery, retries, dead-letter replay, and recovery visibility. |
| Credential controls (scopes, webhook subscriptions, TTL/reminder, IP allowlists) | Production stable | Managed in the Craft CP and enforced at runtime. |
| CLI (`craft agents/*`) | Production stable (ops tooling) | Operator/dev diagnostics and automation helper surface. |
| Discovery docs (`/llms.txt`, `/llms-full.txt`, `/commerce.txt`) | Optional stable feature | Public discovery text, not the core trust boundary. |
| Control-plane actions (`/control/*`, governed write workflows) | Experimental | Enabled only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`. |

This plugin gives external/internal agents a stable interface for:

- API access to content and commerce data (`/agents/v1/products`, `/orders`, `/entries`, `/changes`)
- control-plane visibility for credentials, scopes, and approvals in the Craft CP
- readiness, diagnostics, sync-state, incidents, and lifecycle posture for production operations
- governed write workflows (`/agents/v1/control/*`) when experimental writes are enabled
- operator/developer CLI support via `craft agents/*`

The runtime includes:

- read surfaces for diagnostics and automation
- sign-and-control primitives for governed machine actions (experimental flag):
  - policy evaluation
  - approval gates
  - idempotent execution ledger
  - immutable audit events

It also exposes proposal-oriented discovery files at root-level endpoints:

- `GET /llms.txt`
- `GET /llms-full.txt`
- `GET /commerce.txt`

## Installation

Requirements:

- PHP `^8.2`
- Craft CMS `^5.0`

After Plugin Store publication:

```bash
composer require klick/agents:^0.10.6
php craft plugin/install agents
```

For monorepo development, the package can also be installed via path repository at `plugins/agents`.

Recommended local workflow:

- develop in a dedicated Craft sandbox (not production-bound project roots)
- link plugin via local Composer path repo only in that sandbox
- use the scripted bootstrap/fixture/smoke/release steps in [DEVELOPMENT.md](DEVELOPMENT.md)
- restore production-bound projects to store-backed versions after local debugging

## Configuration

Environment variables:

- `PLUGIN_AGENTS_ENV_PROFILE` (optional: `local|test|staging|production`; used for runtime default profile selection)
- `PLUGIN_AGENTS_ENABLED` (`true`/`false`)
- `PLUGIN_AGENTS_API_TOKEN` (required when token enforcement is enabled)
- `PLUGIN_AGENTS_API_CREDENTIALS` (JSON credential set with optional per-credential scopes)
- `PLUGIN_AGENTS_REQUIRE_TOKEN` (default: `true`)
- `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD` (default: `false`, keep false)
- `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN` (default: `false`)
- `PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD` (default: `true`)
- `PLUGIN_AGENTS_TOKEN_SCOPES` (comma/space list, default scoped read set)
- `PLUGIN_AGENTS_ENABLE_USERS_API` (default: `false`; enables `/users` endpoints)
- `PLUGIN_AGENTS_ENABLE_ADDRESSES_API` (default: `false`; enables `/addresses` endpoints)
- `PLUGIN_AGENTS_LIFECYCLE_METADATA_MAP` (optional JSON map keyed by credential handle for `owner`, `useCase`, `environment`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_UNUSED_WARN_DAYS` (default: `30`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_UNUSED_CRITICAL_DAYS` (default: `90`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_NEVER_USED_WARN_DAYS` (default: `30`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_NEVER_USED_CRITICAL_DAYS` (default: `90`)
- `PLUGIN_AGENTS_LIFECYCLE_ROTATION_WARN_DAYS` (default: `45`)
- `PLUGIN_AGENTS_LIFECYCLE_ROTATION_CRITICAL_DAYS` (default: `120`)
- `PLUGIN_AGENTS_REDACT_EMAIL` (default: `true`, applied when sensitive scope is missing)
- `PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE` (default: `60`)
- `PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS` (default: `60`)
- `PLUGIN_AGENTS_WEBHOOK_URL` (optional HTTPS endpoint for change notifications)
- `PLUGIN_AGENTS_WEBHOOK_SECRET` (required when webhook URL is set; used for HMAC signature)
- `PLUGIN_AGENTS_WEBHOOK_TIMEOUT_SECONDS` (default: `5`)
- `PLUGIN_AGENTS_WEBHOOK_MAX_ATTEMPTS` (default: `3`, max queue retry attempts)
- `PLUGIN_AGENTS_WRITES_EXPERIMENTAL` (default: `false`; enables governed write/control API surfaces)
- Control CP (`agents/control/*`) follows `PLUGIN_AGENTS_WRITES_EXPERIMENTAL` (single gate).

These are documented in `.env.example`.

Environment profile defaults (only when explicit env vars are unset):

| Profile | Rate limit/min | Webhook max attempts | Webhook timeout |
| --- | --- | --- | --- |
| `local` | `300` | `2` | `5s` |
| `test` | `300` | `2` | `5s` |
| `staging` | `120` | `3` | `5s` |
| `production` | `60` | `3` | `5s` |

Profile precedence for runtime security knobs:

1. explicit env var (`PLUGIN_AGENTS_*`)
2. profile default (`PLUGIN_AGENTS_ENV_PROFILE` or inferred from `ENVIRONMENT`/`CRAFT_ENVIRONMENT`)
3. legacy hardcoded fallback

Enablement precedence:

- If `PLUGIN_AGENTS_ENABLED` is set, it overrides CP/plugin setting state.
- If `PLUGIN_AGENTS_ENABLED` is not set, CP/plugin setting `enabled` controls runtime on/off.

## Quickstart, Jobs, and Runbooks

- Get started path: [docs/get-started/index.md](docs/get-started/index.md)
- Agent bootstrap and first-call flow: [docs/api/agent-bootstrap.md](docs/api/agent-bootstrap.md)
- Starter packs and template-driven examples: [docs/api/starter-packs.md](docs/api/starter-packs.md)
- Lifecycle governance posture: [docs/troubleshooting/agent-lifecycle-governance.md](docs/troubleshooting/agent-lifecycle-governance.md)
- Observability runbook and alert thresholds: [docs/troubleshooting/observability-runbook.md](docs/troubleshooting/observability-runbook.md)
- Example automation payloads: [examples/reference-automations/fixtures](examples/reference-automations/fixtures)

## Support

- Docs: https://marcusscheller.com/docs/agents/
- Get started (repo): [docs/get-started/index.md](docs/get-started/index.md)
- Agent bootstrap (repo): [docs/api/agent-bootstrap.md](docs/api/agent-bootstrap.md)
- Starter packs (repo): [docs/api/starter-packs.md](docs/api/starter-packs.md)
- Agent lifecycle governance (repo): [docs/troubleshooting/agent-lifecycle-governance.md](docs/troubleshooting/agent-lifecycle-governance.md)
- Observability runbook (repo): [docs/troubleshooting/observability-runbook.md](docs/troubleshooting/observability-runbook.md)
- Example payload fixtures (repo): [examples/reference-automations/fixtures](examples/reference-automations/fixtures)
- Issues: https://github.com/klick/agents/issues
- Source: https://github.com/klick/agents

## API Access

By default, v1 routes require token-based access (`PLUGIN_AGENTS_REQUIRE_TOKEN=true`).
Fail-closed behavior in production is enabled by default (`PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD=true`).
If `PLUGIN_AGENTS_REQUIRE_TOKEN=false` is set in production, the plugin will still enforce token auth unless `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD=true` is explicitly enabled.
Health/readiness/capabilities/schema responses include environment profile metadata (`environmentProfile`, `environmentProfileSource`, `profileDefaultsApplied`, `effectivePolicyVersion`) for posture introspection.

Credential sources:

- `PLUGIN_AGENTS_API_CREDENTIALS` (strict JSON credential objects with per-credential scopes)
- `PLUGIN_AGENTS_API_TOKEN` (legacy single-token fallback)
- Control Panel managed credentials (Agents tab: create/edit/pause/resume/rotate/revoke/delete with last-used metadata and per-agent owner field)

Managed credentials are stored in plugin DB tables and participate in runtime auth alongside env credentials.

Credential lifecycle permission keys (CP):

- `agents-viewCredentials`
- `agents-manageCredentials` (create + edit scopes/display name)
- `agents-rotateCredentials`
- `agents-revokeCredentials`
- `agents-deleteCredentials`

Supported token transports:

- `Authorization: Bearer <token>` (default)
- `X-Agents-Token: <token>` (default)
- `?apiToken=<token>` only when `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN=true`, and only for `GET`/`HEAD` requests

Write-request rules:

- `POST` write endpoints require `Authorization` or `X-Agents-Token` header auth.
- `POST` write endpoints require `Content-Type: application/json`.
- Query-token auth is rejected on write routes even when query-token mode is enabled.

Example credential JSON:

```json
[
  {"id":"integration-a","token":"token-a","scopes":["health:read","readiness:read","products:read"]},
  {"id":"integration-b","token":"token-b","scopes":"orders:read orders:read_sensitive"}
]
```

Object-map credential JSON is also supported:

```json
{
  "integration-a": {"token":"token-a","scopes":["health:read","readiness:read"]},
  "integration-b": {"token":"token-b","scopes":"orders:read orders:read_sensitive"}
}
```

Validation notes:

- Single-object shape is accepted when it includes `token` (or `value`) and optional `id`/`scopes`.
- Scalar values in keyed-object mode are ignored to avoid accidental token expansion from malformed JSON.

### Endpoints

Base URL (this project): `/agents/v1`

Read/discovery endpoints:

- `GET /health`
- `GET /readiness`
- `GET /auth/whoami`
- `GET /adoption/metrics`
- `GET /metrics`
- `GET /incidents`
- `GET /lifecycle`
- `GET /diagnostics/bundle`
- `GET /products`
- `GET /variants`
- `GET /variants/show` (requires exactly one of `id` or `sku`; optional `productId`)
- `GET /subscriptions`
- `GET /subscriptions/show` (requires exactly one of `id` or `reference`)
- `GET /transfers`
- `GET /transfers/show` (requires `id`)
- `GET /donations`
- `GET /donations/show` (requires exactly one of `id` or `sku`)
- `GET /orders`
- `GET /orders/show` (requires exactly one of `id` or `number`)
- `GET /entries`
- `GET /entries/show` (requires exactly one of `id` or `slug`)
- `GET /assets`
- `GET /assets/show` (requires exactly one of `id` or `filename`; optional `volume` filter)
- `GET /categories`
- `GET /categories/show` (requires exactly one of `id` or `slug`; optional `group` filter)
- `GET /tags`
- `GET /tags/show` (requires exactly one of `id` or `slug`; optional `group` filter)
- `GET /global-sets`
- `GET /global-sets/show` (requires exactly one of `id` or `handle`)
- `GET /addresses` (only when `PLUGIN_AGENTS_ENABLE_ADDRESSES_API=true`)
- `GET /addresses/show` (requires exactly one of `id` or `uid`; optional `ownerId`; only when `PLUGIN_AGENTS_ENABLE_ADDRESSES_API=true`)
- `GET /content-blocks`
- `GET /content-blocks/show` (requires exactly one of `id` or `uid`; optional `ownerId` and `fieldId`)
- `GET /users` (only when `PLUGIN_AGENTS_ENABLE_USERS_API=true`)
- `GET /users/show` (requires exactly one of `id` or `username`; only when `PLUGIN_AGENTS_ENABLE_USERS_API=true`)
- `GET /changes`
- `GET /sections`
- `GET /sync-state/lag`
- `POST /sync-state/checkpoint`
- Legacy aliases (deprecated, still supported during transition):
  - `GET /consumers/lag`
  - `POST /consumers/checkpoint`
- `GET /templates`
- `GET /starter-packs`
- `GET /schema`
- `GET /capabilities`
- `GET /openapi.json`

Control-plane endpoints (only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`):

- `GET /control/policies`
- `POST /control/policies/upsert`
- `GET /control/approvals`
- `POST /control/approvals/request`
- `POST /control/approvals/decide`
- `GET /control/executions`
- `POST /control/policy-simulate`
- `POST /control/actions/execute`
- `GET /control/audit`

Webhook reliability endpoints:

- `GET /webhooks/dlq`
- `POST /webhooks/dlq/replay`

Root-level discovery files:

- `GET /llms.txt` (public when enabled)
- `GET /llms-full.txt` (public when enabled)
- `GET /commerce.txt` (public when enabled)
- Discovery aliases:
  - `GET /capabilities` -> `GET /agents/v1/capabilities`
  - `GET /openapi.json` -> `GET /agents/v1/openapi.json`

### Scope catalog

Read scopes:

- `health:read`
- `readiness:read`
- `auth:read`
- `adoption:read`
- `metrics:read`
- `incidents:read`
- `lifecycle:read`
- `diagnostics:read`
- `products:read`
- `variants:read`
- `subscriptions:read`
- `transfers:read`
- `donations:read`
- `orders:read`
- `orders:read_sensitive`
- `entries:read`
- `entries:read_all_statuses`
- `assets:read`
- `categories:read`
- `tags:read`
- `globalsets:read`
- `addresses:read` (only when `PLUGIN_AGENTS_ENABLE_ADDRESSES_API=true`)
- `addresses:read_sensitive` (only when `PLUGIN_AGENTS_ENABLE_ADDRESSES_API=true`)
- `contentblocks:read`
- `changes:read`
- `sections:read`
- `users:read` (only when `PLUGIN_AGENTS_ENABLE_USERS_API=true`)
- `users:read_sensitive` (only when `PLUGIN_AGENTS_ENABLE_USERS_API=true`)
- `syncstate:read`
- `templates:read`
- `schema:read`
- `capabilities:read`
- `openapi:read`
- `webhooks:dlq:read`
- `webhooks:dlq:replay`
- `control:policies:read` (only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)
- `control:approvals:read` (only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)
- `control:executions:read` (only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)
- `control:audit:read` (only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)

Write scopes:

- `syncstate:write`
- `entries:write:draft` (experimental; required by governed draft updates like `entry.updateDraft` and only useful when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)
- Legacy scope aliases (deprecated, still accepted during transition):
  - `entries:write` -> `entries:write:draft`
  - `consumers:read` -> `syncstate:read`
  - `consumers:write` -> `syncstate:write`
- `control:policies:write` (only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)
- `control:approvals:request` (only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)
- `control:approvals:decide` (only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)
- `control:approvals:write` (legacy combined scope, backward-compatible; only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)
- `control:actions:simulate` (only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)
- `control:actions:execute` (only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)

## CLI Commands

Craft-native command routes:

- `craft agents/product-list`
- `craft agents/order-list`
- `craft agents/order-show`
- `craft agents/entry-list`
- `craft agents/entry-show`
- `craft agents/section-list`
- `craft agents/discovery-prewarm`
- `craft agents/auth-check`
- `craft agents/discovery-check`
- `craft agents/readiness-check`
- `craft agents/reliability-check`
- `craft agents/lifecycle-report`
- `craft agents/template-catalog`
- `craft agents/starter-packs`
- `craft agents/diagnostics-bundle`
- `craft agents/smoke`

Examples:

```bash
# Product discovery (text output)
php craft agents/product-list --status=live --limit=10

# Product discovery (JSON output)
php craft agents/product-list --status=all --search=emboss --limit=5 --json=1

# Low stock view
php craft agents/product-list --low-stock=1 --low-stock-threshold=10 --limit=25

# Orders from last 14 days
php craft agents/order-list --status=shipped --last-days=14 --limit=20

# Show a single order
php craft agents/order-show --number=A1B2C3D4
php craft agents/order-show --resource-id=12345

# Entries
php craft agents/entry-list --section=termsConditionsB2b --status=live --limit=20
php craft agents/entry-show --slug=shipping-information
php craft agents/entry-show --resource-id=123

# Sections
php craft agents/section-list

# Prewarm llms.txt + llms-full.txt + commerce.txt cache
php craft agents/discovery-prewarm
php craft agents/discovery-prewarm --target=llms --json=1
php craft agents/discovery-prewarm --target=llms-full --json=1

# Auth posture check
php craft agents/auth-check
php craft agents/auth-check --strict=1 --json=1

# Discovery/readiness/diagnostics/smoke checks
php craft agents/discovery-check --json=1
php craft agents/readiness-check --json=1
php craft agents/reliability-check --json=1
php craft agents/reliability-check --strict=1 --json=1
php craft agents/lifecycle-report --json=1
php craft agents/lifecycle-report --strict=1 --json=1
php craft agents/template-catalog --json=1
php craft agents/template-catalog --template-id=catalog-sync-loop
php craft agents/starter-packs --json=1
php craft agents/starter-packs --template-id=catalog-sync-loop --json=1
php craft agents/diagnostics-bundle --json=1
php craft agents/smoke --json=1
```

CLI output defaults to human-readable text. Add `--json=1` for machine consumption.

Identifier notes for show commands:

- `agents/order-show`: use exactly one of `--number` or `--resource-id`.
- `agents/entry-show`: use exactly one of `--slug` or `--resource-id`.

### Products endpoint parameters

- `q` (search text)
- `status` (`live|pending|disabled|expired|all`, default `live`)
- `sort` (`updatedAt|createdAt|title`, default `updatedAt`)
- `limit` (1..200, default 50)
- `cursor` (opaque cursor; legacy pagination + incremental continuation)
- `updatedSince` (RFC3339 timestamp bootstrap for incremental mode, for example `2026-02-24T12:00:00Z`)
- `lowStock` (`1|0|true|false|yes|no|on|off`; when true returns only products at/below threshold in full-sync mode)
- `lowStockThreshold` (integer >= 0, default `10`, used with `lowStock=true`)
- Product response items include aggregated inventory fields: `hasUnlimitedStock`, `totalStock`
- `lowStock` cannot be combined with incremental sync (`cursor`/`updatedSince`)

### Variants endpoint parameters

- `/variants`: `status` (`live|pending|disabled|expired|all`), `q`, `sku`, `productId`, `limit` (1..200)
- `/variants` incremental: `cursor` (opaque), `updatedSince` (RFC3339)
- `/variants/show`: exactly one of `id` or `sku`; optional `productId`
- `/variants` list and `/variants/show` include inventory fields: `stock`, `hasUnlimitedStock`, `isAvailable`

### Subscriptions endpoint parameters

- `/subscriptions`: `status` (`active|expired|suspended|canceled|all`), `q`, `reference`, `userId`, `planId`, `limit` (1..200)
- `/subscriptions` incremental: `cursor` (opaque), `updatedSince` (RFC3339)
- `/subscriptions/show`: exactly one of `id` or `reference`

### Transfers endpoint parameters

- `/transfers`: `status`, `q`, `originLocationId`, `destinationLocationId`, `limit` (1..200)
- `/transfers` incremental: `cursor` (opaque), `updatedSince` (RFC3339)
- `/transfers/show`: `id`

### Donations endpoint parameters

- `/donations`: `status` (`live|pending|disabled|expired|all`), `q`, `sku`, `limit` (1..200)
- `/donations` incremental: `cursor` (opaque), `updatedSince` (RFC3339)
- `/donations/show`: exactly one of `id` or `sku`

### Orders endpoint parameters

- `/orders`: `status` (handle or `all`), `lastDays` (default 30), `limit` (1..200)
- `/orders` incremental: `cursor` (opaque), `updatedSince` (RFC3339). When incremental params are used and `lastDays` is omitted, the default window is `0` (no date-created cutoff).
- `/orders/show`: exactly one of `id` or `number`

### Entries endpoint parameters

- `/entries`: `section`, `type`, `status`, `search` (or `q`), `limit` (1..200)
- `/entries` incremental: `cursor` (opaque), `updatedSince` (RFC3339)
- `/entries/show`: exactly one of `id` or `slug`; optional `section` when using `slug`

### Addresses endpoint parameters

- `/addresses`: `q`, `ownerId`, `countryCode`, `postalCode`, `limit` (1..200)
- `/addresses` incremental: `cursor` (opaque), `updatedSince` (RFC3339)
- `/addresses/show`: exactly one of `id` or `uid`; optional `ownerId`

### Content Blocks endpoint parameters

- `/content-blocks`: `q`, `ownerId`, `fieldId`, `limit` (1..200)
- `/content-blocks` incremental: `cursor` (opaque), `updatedSince` (RFC3339)
- `/content-blocks/show`: exactly one of `id` or `uid`; optional `ownerId`, `fieldId`

### Changes endpoint parameters

- `/changes`: `types` (optional comma list: `products,variants,subscriptions,transfers,donations,orders,entries,assets,categories,tags,globalsets,addresses,contentblocks,users`), `updatedSince` (RFC3339 bootstrap), `cursor` (opaque continuation), `limit` (1..200)
- `/changes` returns normalized `data[]` items with:
  - `resourceType` (`product|variant|subscription|transfer|donation|order|entry|asset|category|tag|globalset|address|contentblock|user`)
  - `resourceId` (string)
  - `action` (`created|updated|deleted`)
  - `updatedAt` (RFC3339 UTC)
  - `snapshot` (minimal object for `created|updated`, `null` for `deleted` tombstones)

### Incidents endpoint parameters

- `/incidents`: `severity` (`all|warn|critical`, default `all`), `limit` (1..200, default `50`)
- `/incidents` returns strict-redacted runtime incident summaries derived from reliability signals

### Control endpoint parameters and behavior

- `POST /control/policies/upsert` body:
  - `handle` (required)
  - `actionPattern` (required, wildcard-compatible)
  - `displayName`, `riskLevel`, `enabled`, `requiresApproval`, `config`
- `GET /control/approvals` query: `status`, `actionType`, `limit`
- `POST /control/approvals/request` body:
  - `actionType` (required)
  - `actionRef`, `reason`, `payload`, `metadata`
  - `metadata.source`, `metadata.agentId`, `metadata.traceId` are required (agent provenance)
  - idempotency via `X-Idempotency-Key` header (or `idempotencyKey` body field)
  - approvals persist their evaluated assurance mode (`single_approval`, `dual_control`, or `single_operator_degraded`) and downgrade reason at request time
- `POST /control/approvals/decide` body:
  - `approvalId` (required)
  - `decision` (`approved|rejected`)
  - `decisionReason`
  - requester/approver separation is enforced whenever the recorded assurance mode is not degraded single-operator fallback
- `GET /control/executions` query: `status`, `actionType`, `limit`
- `POST /control/actions/execute` body:
  - `actionType` (required)
  - `idempotencyKey` (required, header or body)
  - `actionRef`, `approvalId`, `payload`
  - response may include `idempotentReplay=true` when the key was already processed
- `GET /control/audit` query: `category`, `actorId`, `limit`

### Incremental sync rules

- `cursor` takes precedence over `updatedSince` when both are provided.
- Incremental mode uses deterministic ordering: `updatedAt`, then `id`.
- Incremental responses include `page.syncMode=incremental`, `page.hasMore`, `page.nextCursor`, and snapshot window metadata.
- Cursor tokens are opaque and may expire; restart from a recent `updatedSince` checkpoint if needed.
- `/changes` cursor continuity also preserves the selected `types` filter.
- Invalid `updatedSince`/`cursor` inputs return `400 INVALID_REQUEST` with stable error payload fields.
- Dedicated sync-state credentials are credential-bound: `integrationKey` may be omitted and will default to the authenticated credential id, or it must exactly match that credential id.
- Legacy `PLUGIN_AGENTS_API_TOKEN` (`credentialId=default`) may continue writing arbitrary `integrationKey` values only when it is the sole configured runtime credential.

### Webhook Delivery

Webhook notifications are optional and are enabled only when both `PLUGIN_AGENTS_WEBHOOK_URL` and `PLUGIN_AGENTS_WEBHOOK_SECRET` are configured.

Behavior:

- Events are queued asynchronously on `product|order|entry` create/update/delete changes.
- Event payload mirrors `/changes` items: `resourceType`, `resourceId`, `action`, `updatedAt`, `snapshot`.
- Retry behavior uses queue retries up to `PLUGIN_AGENTS_WEBHOOK_MAX_ATTEMPTS`.
- Variant changes are emitted as `product` `updated` events.

Request headers:

- `X-Agents-Webhook-Id`: unique event id
- `X-Agents-Webhook-Timestamp`: unix timestamp
- `X-Agents-Webhook-Signature`: `sha256=<hex hmac>`

Signature verification:

- signed string: `<timestamp>.<raw-request-body>`
- algorithm: `HMAC-SHA256`
- secret: `PLUGIN_AGENTS_WEBHOOK_SECRET`

Queue note:

- Webhooks are delivered by Craft queue workers; ensure `php craft queue/run` or `php craft queue/listen` is active in environments where webhook delivery is required.

### Discoverability endpoints

- `/capabilities`: machine-readable list of supported endpoints + CLI commands.
- `/openapi.json`: OpenAPI 3.1 descriptor for this API surface.

Discovery file behavior:

- `llms.txt` / `commerce.txt` are generated dynamically as plain text by default.
- `llms.txt` and `commerce.txt` can optionally be served from custom CP-configured body content.
- `llms-full.txt` is an optional extended discovery export.
- They include `ETag` + `Last-Modified` headers and support `304 Not Modified`.
- Output is cached and invalidated on relevant content/product updates.

Example:

```bash
curl -H "Authorization: Bearer $PLUGIN_AGENTS_API_TOKEN" \
  "https://example.com/agents/v1/products?status=live&sort=title&limit=2"
```

## Response style

- JSON only
- All API responses include `X-Request-Id`.
- Guarded JSON/error responses set `Cache-Control: no-store, private`.
- Products response includes:
  - `data[]` with minimal product fields (`id`, `title`, `slug`, `status`, `updatedAt`, `url`, etc.)
  - `page` with `nextCursor`, `limit`, `count`
- Changes response includes:
  - `data[]` normalized change items (`resourceType`, `resourceId`, `action`, `updatedAt`, `snapshot`)
  - `page` with `nextCursor`, `hasMore`, `limit`, `count`, `updatedSince`, `snapshotEnd`
- Health/readiness include plugin, environment, and readiness score fields.
- Error responses use a stable schema:

```json
{
  "error": "UNAUTHORIZED",
  "message": "Missing or invalid token.",
  "status": 401,
  "requestId": "agents-9fd2b20abec4a65f"
}
```

## Security and reliability

- Rate limiting headers are returned on each guarded request:
  - `X-RateLimit-Limit`
  - `X-RateLimit-Remaining`
  - `X-RateLimit-Reset`
- Rate limiting is applied before and after auth checks to throttle invalid-token attempts.
- Exceeded limits return HTTP `429` with `RATE_LIMIT_EXCEEDED`.
- Missing/invalid credentials return HTTP `401`.
- Missing required scope returns HTTP `403` with `FORBIDDEN`.
- Read/discovery endpoints are `GET`/`HEAD` only.
- Control-plane write endpoints accept `POST` and are token-authenticated API actions.
- Write endpoints require header-based auth and `Content-Type: application/json`.
- Misconfigured production token setup returns HTTP `503` with `SERVER_MISCONFIGURED`.
- Disabled runtime state returns HTTP `503` with `SERVICE_DISABLED`.
- Invalid request payload/params return HTTP `400` with `INVALID_REQUEST`.
- Missing resource lookups return HTTP `404` with `NOT_FOUND`.
- Unexpected server errors return HTTP `500` with `INTERNAL_ERROR`.
- Query-token auth is disabled by default to reduce token leakage risk, and remains read-only even when enabled.
- Credential parsing is strict for `PLUGIN_AGENTS_API_CREDENTIALS` (credential objects only) and ignores malformed scalar entries.
- Sensitive order fields are scope-gated; email is redacted by default unless `orders:read_sensitive` is granted.
- Entry access to non-live statuses is scope-gated by `entries:read_all_statuses`.
- Endpoint is not meant for frontend/public user flows; token is the intended control plane.

Note: `llms.txt`, `llms-full.txt`, and `commerce.txt` are public discovery surfaces and are not guarded by the API token.

## Troubleshooting Flow

1. Capture `X-Request-Id` from the failing response.
2. Confirm the error `status` + `error` code pair.
3. Match the code to the fix path:
   - `UNAUTHORIZED` (`401`): token missing/invalid or wrong transport.
   - `FORBIDDEN` (`403`): token missing required scope.
   - `INVALID_REQUEST` (`400`): malformed query or invalid identifier combination.
   - `NOT_FOUND` (`404`): requested resource does not exist.
   - `METHOD_NOT_ALLOWED` (`405`): endpoint does not support the HTTP method used.
   - `RATE_LIMIT_EXCEEDED` (`429`): respect `X-RateLimit-*` and retry after reset.
   - `SERVICE_DISABLED` (`503`): plugin runtime disabled by env/CP setting.
   - `SERVER_MISCONFIGURED` (`503`): token/security env configuration invalid.
   - `INTERNAL_ERROR` (`500`): unexpected server-side failure.
4. Correlate by `X-Request-Id` in server logs for root-cause details.

## Discovery Text Config (`config/agents.php`)

These values can be overridden from your project via `config/agents.php`:

```php
<?php

return [
    'enableLlmsTxt' => true,
    'enableLlmsFullTxt' => false,
    'enableCommerceTxt' => true,
    'llmsTxtCacheTtl' => 86400,
    'commerceTxtCacheTtl' => 3600,
    'llmsTxtBody' => '',
    'llmsSiteSummary' => 'Product and policy discovery for assistants.',
    'llmsIncludeAgentsLinks' => true,
    'llmsIncludeSitemapLink' => true,
    'llmsLinks' => [
        ['label' => 'Support', 'url' => '/support'],
        ['label' => 'Contact', 'url' => '/contact'],
    ],
    'commerceSummary' => 'Commerce metadata for discovery workflows.',
    'commerceTxtBody' => '',
    'commerceCatalogUrl' => '/agents/v1/products?status=live&limit=200',
    'commercePolicyUrls' => [
        'shipping' => '/shipping-information',
        'returns' => '/returns',
        'payment' => '/payment-options',
    ],
    'commerceSupport' => [
        'email' => 'support@example.com',
        'phone' => '+1-555-0100',
        'url' => '/contact',
    ],
    'commerceAttributes' => [
        'currency' => 'USD',
        'region' => 'US',
    ],
    'reliabilityConsumerLagWarnSeconds' => 300,
    'reliabilityConsumerLagCriticalSeconds' => 900,
];
```

## CP views

- `Agents` section now uses 3 primary subnav views by default:
  - `agents/dashboard/overview` (`Dashboard`)
  - `agents/credentials` (`Agents`)
  - `agents/settings` (`Settings`)
- Dashboard includes top tabs:
  - `Overview` (`agents/dashboard/overview`)
  - `Readiness` (`agents/dashboard/readiness`)
  - `Discovery Docs` (`agents/dashboard/discovery`)
  - `Security` (`agents/dashboard/security`)
- Legacy CP paths remain valid and resolve to Dashboard tabs:
  - `agents` -> `dashboard/overview`
  - `agents/overview` -> `dashboard/overview`
  - `agents/readiness` -> `dashboard/readiness`
  - `agents/discovery` -> `dashboard/discovery`
  - `agents/security` -> `dashboard/security`
  - `agents/health` -> `dashboard/readiness`
- Dashboard/Overview:
  - runtime enabled/disabled state and source (`env` vs CP setting)
  - env-lock aware runtime toggle
  - quick endpoint links + discovery docs refresh entrypoint
  - ownership split guidance (`CP` vs `config/agents.php` vs `.env`)
- Settings:
  - runtime toggles for `llms.txt`, `llms-full.txt`, and `commerce.txt`
  - editable custom body fields for `llms.txt` and `commerce.txt` (optional)
  - one-click reset actions for custom discovery bodies (revert to generated defaults)
  - config lock-state visibility when keys are overridden via `config/agents.php`
- Dashboard/Readiness:
  - readiness score, criterion breakdown, component checks, warnings
  - health/readiness diagnostic JSON snapshots
- Dashboard/Discovery Docs:
  - read-only `llms.txt`/`llms-full.txt`/`commerce.txt` status, metadata, preview snippets
  - operator actions: refresh (`all|llms|llms-full|commerce`) and clear cache
- Dashboard/Security:
  - read-only effective auth/rate-limit/redaction/webhook posture
  - centralized warning output from shared security policy logic
- Agents:
  - managed credential lifecycle (create/edit/pause/resume/rotate/revoke/delete)
  - lifecycle governance snapshot cards (critical/warn/stale/owner-missing counts)
  - per-agent lifecycle warnings (owner/use-case/environment + risk signals)
  - one-time API token reveal on create/rotate

## CP rollout regression checklist

1. Verify Dashboard subnav loads and each top tab (`overview`, `readiness`, `discovery` label = "Discovery Docs", `security`) switches correctly.
2. Verify legacy aliases (`agents`, `agents/overview`, `agents/readiness`, `agents/discovery`, `agents/security`, `agents/health`) still resolve to the expected Dashboard tab.
3. Verify runtime lightswitch is disabled when `PLUGIN_AGENTS_ENABLED` is set.
4. Verify discovery docs actions work:
   - refresh `all`
   - refresh `llms`
   - refresh `llms-full`
   - refresh `commerce`
   - clear discovery cache
   - when custom body content is configured in Settings, confirm endpoint output reflects those edits
5. Verify security tab shows posture without exposing token/secret values.
6. Verify API and CLI behavior remains unchanged except expected `SERVICE_DISABLED` when runtime is off.

## Namespace migration

- PHP namespace root is now `Klick\\Agents` (for example `Klick\\Agents\\Plugin`).
- Plugin handle stays `agents` (CP nav, routes, and CLI command prefixes remain unchanged).

## Security Rollout Checklist

1. Set `PLUGIN_AGENTS_API_TOKEN` to a strong secret and keep `PLUGIN_AGENTS_REQUIRE_TOKEN=true`.
2. Prefer `PLUGIN_AGENTS_API_CREDENTIALS` for per-integration token/scope separation.
3. Keep `PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD=true`.
4. Keep `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD=false`.
5. Keep `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN=false` unless legacy read-only clients require it temporarily.
6. Start with default scopes; only add elevated scopes when required:
   - `orders:read_sensitive`
   - `entries:read_all_statuses`
   - `control:policies:write` (experimental flag only)
   - `control:approvals:request` (experimental flag only)
   - `control:approvals:decide` (experimental flag only)
   - `control:approvals:write` (legacy compatibility; experimental flag only)
   - `control:actions:execute` (experimental flag only)
7. Verify `capabilities`/`openapi.json` outputs reflect active auth transport/settings.
8. Run `scripts/security-regression-check.sh` against your environment before promotion.

## Scope Migration Notes

- Prior behavior effectively granted broad read access to any valid token.
- New default scopes intentionally exclude elevated permissions.
- To preserve legacy broad reads temporarily, set:
- `PLUGIN_AGENTS_TOKEN_SCOPES=\"health:read readiness:read auth:read adoption:read metrics:read lifecycle:read diagnostics:read products:read orders:read orders:read_sensitive entries:read entries:read_all_statuses changes:read sections:read capabilities:read openapi:read\"`
  - If `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`, optionally append control read scopes: `control:policies:read control:approvals:read control:executions:read control:audit:read`

## Secure Deployment Verification

```bash
# 1) Missing token should fail
curl -i "https://example.com/agents/v1/health"

# 2) Query token should fail by default
curl -i "https://example.com/agents/v1/health?apiToken=$PLUGIN_AGENTS_API_TOKEN"

# 3) Header token should pass
curl -i -H "Authorization: Bearer $PLUGIN_AGENTS_API_TOKEN" \
  "https://example.com/agents/v1/health"

# 4) Non-live entries should require elevated scope
curl -i -H "Authorization: Bearer $PLUGIN_AGENTS_API_TOKEN" \
  "https://example.com/agents/v1/entries?status=all&limit=1"

# 5) Control policy list (only when PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true)
curl -i -H "Authorization: Bearer $PLUGIN_AGENTS_API_TOKEN" \
  "https://example.com/agents/v1/control/policies"

# 6) Run local regression script
./scripts/security-regression-check.sh https://example.com "$PLUGIN_AGENTS_API_TOKEN"
```

## Roadmap

Planned improvements include:

- Expanded filtering and pagination controls for existing read-only endpoints.
- Additional diagnostics for operational readiness and integration health.
- Broader OpenAPI coverage and schema detail improvements.
- Optional export/report formats for automation workflows.
- Action-adapter integration for control-plane execution side effects.
- Continued hardening of auth, rate limiting, and observability.

### Incremental Sync Contract (v0.2.0)

The formal contract for checkpoint-based sync is documented in the public docs (`API` + `Roadmap` sections): https://marcusscheller.com/docs/agents/

Highlights:

- `cursor`-first continuation semantics with `updatedSince` bootstrap support.
- Deterministic ordering (`updatedAt`, then `id`) and at-least-once replay model.
- Tombstone/delete signaling through `GET /agents/v1/changes`.
