# Auth & Scopes

If you are trying to decide which scopes to give a worker in plain language, start with [Scope Guide](/api/scope-guide).

## Authentication methods

When token enforcement is enabled:

- `Authorization: Bearer <token>`
- `X-Agents-Token: <token>`
- `?apiToken=<token>` only if `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN=true`, and only for `GET`/`HEAD`

Write routes:

- must authenticate via `Authorization` or `X-Agents-Token`
- must send `Content-Type: application/json`
- reject query-token auth even when query-token mode is enabled

## How agents use API keys

- Agents do not transport keys themselves; the runtime/tool layer sends HTTP requests.
- Store the key as a runtime secret (for example `AGENTS_API_KEY`), not in prompts.
- Configure your HTTP tool/client to send either:
  - `Authorization: Bearer <token>`
  - `X-Agents-Token: <token>`
- Validate scope and identity with `GET /agents/v1/auth/whoami`.

## Credential sources

Runtime auth accepts merged credentials from:

- managed CP keys
- `PLUGIN_AGENTS_API_CREDENTIALS` JSON set
- `PLUGIN_AGENTS_API_TOKEN` legacy fallback

Capabilities/auth metadata surfaces also expose runtime profile posture:

- `environmentProfile`
- `environmentProfileSource`
- `profileDefaultsApplied`
- `effectivePolicyVersion`

## Core scopes

For an operator-facing explanation of what each scope is for and when a worker would actually need it, see [Scope Guide](/api/scope-guide).

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
- `syncstate:write`
- `templates:read`
- `entries:write:draft` (experimental; only effective when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)
- `entries:write` (deprecated alias for `entries:write:draft`)
- `consumers:read` (deprecated alias for `syncstate:read`)
- `consumers:write` (deprecated alias for `syncstate:write`)
- `schema:read`
- `capabilities:read`
- `openapi:read`
- `webhooks:dlq:read`
- `webhooks:dlq:replay`

Control scopes (experimental flag only):

- `control:policies:read`
- `control:policies:write`
- `control:approvals:read`
- `control:approvals:request`
- `control:approvals:decide`
- `control:approvals:write` (legacy combined compatibility scope)
- `control:executions:read`
- `control:actions:simulate`
- `control:actions:execute`
- `control:audit:read`

Notes:

- Control scopes are omitted from capabilities/OpenAPI when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=false`.
- `control:approvals:write` remains accepted for backward compatibility, but new integrations should use request/decide split scopes.

## Permission model in CP

Credential management permissions:

- `agents-viewCredentials`
- `agents-manageCredentials`
- `agents-rotateCredentials`
- `agents-revokeCredentials`
- `agents-deleteCredentials`
