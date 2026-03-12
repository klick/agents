# Endpoints

Base path: `/agents/v1`

## Core read endpoints

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
- `GET /variants/show`
- `GET /subscriptions`
- `GET /subscriptions/show`
- `GET /transfers`
- `GET /transfers/show`
- `GET /donations`
- `GET /donations/show`
- `GET /orders`
- `GET /orders/show`
- `GET /entries`
- `GET /entries/show`
- `GET /assets`
- `GET /assets/show`
- `GET /categories`
- `GET /categories/show`
- `GET /tags`
- `GET /tags/show`
- `GET /global-sets`
- `GET /global-sets/show`
- `GET /addresses` (only when `PLUGIN_AGENTS_ENABLE_ADDRESSES_API=true`)
- `GET /addresses/show` (only when `PLUGIN_AGENTS_ENABLE_ADDRESSES_API=true`)
- `GET /content-blocks`
- `GET /content-blocks/show`
- `GET /users` (only when `PLUGIN_AGENTS_ENABLE_USERS_API=true`)
- `GET /users/show` (only when `PLUGIN_AGENTS_ENABLE_USERS_API=true`)
- `GET /changes`
- `GET /sections`
- `GET /sync-state/lag`
- `POST /sync-state/checkpoint`
- Deprecated aliases (still supported during transition):
  - `GET /consumers/lag`
  - `POST /consumers/checkpoint`
- `GET /templates`
- `GET /starter-packs`
- `GET /schema`
- `GET /capabilities`
- `GET /openapi.json`

Root aliases (guarded, same auth/scope behavior):

- `GET /capabilities` -> `/agents/v1/capabilities`
- `GET /openapi.json` -> `/agents/v1/openapi.json`

## Webhook reliability endpoints

- `GET /webhooks/dlq`
- `POST /webhooks/dlq/replay`

## Control endpoints (experimental)

Available only when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`:

- `GET /control/policies`
- `POST /control/policies/upsert`
- `GET /control/approvals`
- `POST /control/approvals/request`
- `POST /control/approvals/decide`
- `GET /control/executions`
- `POST /control/policy-simulate`
- `POST /control/actions/execute`
- `GET /control/audit`

## Key parameter notes

### Products

- `status=live|pending|disabled|expired|all`
- `sort=updatedAt|createdAt|title`
- `q`
- `limit` (1..200)
- `cursor`, `updatedSince`
- `lowStock=true|false|1|0|yes|no|on|off` (full-sync mode only)
- `lowStockThreshold` (integer >= 0, default `10`; used with `lowStock=true`)
- `fields` (comma-separated projection paths)
- `filter` (comma-separated `path:value` expressions; supports `~value` contains and `*` wildcard)
- Product items include `hasUnlimitedStock` and `totalStock`

### Variants

- `status=live|pending|disabled|expired|all`
- `q`, `sku`, `productId`
- `limit`
- `cursor`, `updatedSince`
- `fields`
- `filter`
- `/variants/show` requires exactly one of `id` or `sku` (optional `productId`)
- Variant list and detail payloads include `stock`, `hasUnlimitedStock`, and `isAvailable`

### Subscriptions

- `status=active|expired|suspended|canceled|all`
- `q`, `reference`, `userId`, `planId`
- `limit`
- `cursor`, `updatedSince`
- `fields`
- `filter`
- `/subscriptions/show` requires exactly one of `id` or `reference`

### Transfers

- `status`, `q`, `originLocationId`, `destinationLocationId`
- `limit`
- `cursor`, `updatedSince`
- `fields`
- `filter`
- `/transfers/show` requires `id`

### Donations

- `status=live|pending|disabled|expired|all`
- `q`, `sku`
- `limit`
- `cursor`, `updatedSince`
- `fields`
- `filter`
- `/donations/show` requires exactly one of `id` or `sku`

### Orders

- `status`
- `lastDays`
- `limit`
- `cursor`, `updatedSince`
- `fields`
- `filter`
- `/orders/show` requires exactly one of `id` or `number`

### Entries

- `section`, `type`, `status`, `search`/`q`
- `limit`
- `cursor`, `updatedSince`
- `fields`
- `filter`
- `/entries/show` requires exactly one of `id` or `slug`

### Assets / Categories / Tags / Global Sets

- `/assets`: `q`, `volume`, `kind`, `limit`, `cursor`, `updatedSince`, `fields`, `filter`
- `/assets/show`: exactly one of `id` or `filename` (optional `volume`)
- `/categories`: `q`, `group`, `limit`, `cursor`, `updatedSince`, `fields`, `filter`
- `/categories/show`: exactly one of `id` or `slug` (optional `group`)
- `/tags`: `q`, `group`, `limit`, `cursor`, `updatedSince`, `fields`, `filter`
- `/tags/show`: exactly one of `id` or `slug` (optional `group`)
- `/global-sets`: `q`, `limit`, `cursor`, `updatedSince`, `fields`, `filter`
- `/global-sets/show`: exactly one of `id` or `handle`

### Addresses / Content Blocks

- `/addresses` (flag-gated): `q`, `ownerId`, `countryCode`, `postalCode`, `limit`, `cursor`, `updatedSince`, `fields`, `filter`
- `/addresses/show`: exactly one of `id` or `uid` (optional `ownerId`)
- `/content-blocks`: `q`, `ownerId`, `fieldId`, `limit`, `cursor`, `updatedSince`, `fields`, `filter`
- `/content-blocks/show`: exactly one of `id` or `uid` (optional `ownerId`, `fieldId`)

### Users

- `status=active|inactive|pending|suspended|locked|credentialed|all`
- `group`, `q`
- `limit`
- `cursor`, `updatedSince`
- `fields`
- `filter`
- `/users/show` requires exactly one of `id` or `username`

### Changes

- `types` (`products,variants,subscriptions,transfers,donations,orders,entries,assets,categories,tags,globalsets,addresses,contentblocks,users`)
- `cursor`, `updatedSince`
- `limit`
- `fields`
- `filter`

### Incidents

- `severity` (`all|warn|critical`, default `all`)
- `limit` (1..200, default `50`)
- payload is strict-redacted and derived from runtime reliability signals

### Sync-state checkpoint request body

`POST /sync-state/checkpoint` accepts:

- `integrationKey` (optional for dedicated credentials; when provided it must match the authenticated credential id)
- `resourceType` (required)
- `cursor` (optional)
- `updatedSince` (optional RFC3339-like datetime)
- `checkpointAt` (optional; defaults to now)
- `metadata` (optional object)

Notes:

- dedicated sync-state credentials default `integrationKey` to their credential id when omitted
- the legacy default token may only write arbitrary `integrationKey` values when it is the sole configured runtime credential

### Schema catalog query params

`GET /schema` supports:

- `version` (defaults to `v1`)
- `endpoint` (optional endpoint key like `products.list`)

### Webhook DLQ query/body params

- `GET /webhooks/dlq`: `status=failed|queued`, `limit`
- `POST /webhooks/dlq/replay`: either `id` or `mode=all` (+ optional `limit` for all-mode)

### Approvals request body requirements

`POST /control/approvals/request` requires:

- `actionType`
- provenance metadata fields:
  - `metadata.source`
  - `metadata.agentId`
  - `metadata.traceId`

## Request/response examples

Set once:

```bash
export BASE_URL="https://your-site.tld"
export AGENTS_API_KEY="<token>"
```

### `GET /auth/whoami`

Happy path:

```bash
curl -sS "$BASE_URL/agents/v1/auth/whoami" \
  -H "Authorization: Bearer $AGENTS_API_KEY"
```

Example response (`200`):

```json
{
  "ok": true,
  "data": {
    "credentialId": "cred_8f9f4d",
    "label": "integration-bot",
    "scopes": ["auth:read", "products:read", "schema:read"]
  },
  "requestId": "req_01HSW2Y9Q6X"
}
```

Common error (`403 FORBIDDEN`, missing scope):

```json
{
  "error": {
    "code": "FORBIDDEN",
    "message": "Missing required scope: auth:read"
  },
  "requestId": "req_01HSW2YB9T0"
}
```

### `GET /products`

Happy path:

```bash
curl -sS "$BASE_URL/agents/v1/products?status=live&limit=2&fields=id,title,updatedAt" \
  -H "Authorization: Bearer $AGENTS_API_KEY"
```

Example response (`200`):

```json
{
  "ok": true,
  "data": [
    { "id": 101, "title": "Trail Shoes", "updatedAt": "2026-03-04T10:11:12Z" },
    { "id": 102, "title": "City Backpack", "updatedAt": "2026-03-04T10:09:30Z" }
  ],
  "pagination": {
    "hasMore": true,
    "nextCursor": "cursor_01HSW30FA8"
  },
  "requestId": "req_01HSW30M1FE"
}
```

Common error (`400 INVALID_REQUEST`, invalid `limit`):

```json
{
  "error": {
    "code": "INVALID_REQUEST",
    "message": "Query parameter `limit` must be between 1 and 200"
  },
  "requestId": "req_01HSW31AQ9J"
}
```

### `POST /sync-state/checkpoint`

Happy path:

```bash
curl -sS -X POST "$BASE_URL/agents/v1/sync-state/checkpoint" \
  -H "Authorization: Bearer $AGENTS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "resourceType": "product",
    "cursor": "cursor_01HSW33Y1K",
    "metadata": { "source": "nightly-sync" }
  }'
```

Example response (`200`):

```json
{
  "ok": true,
  "data": {
    "integrationKey": "acme-ingestor",
    "resourceType": "product",
    "cursor": "cursor_01HSW33Y1K",
    "checkpointAt": "2026-03-04T11:12:13Z"
  },
  "requestId": "req_01HSW34W21A"
}
```

If you send `integrationKey`, it must match the authenticated credential id; otherwise omit it and let the runtime bind it automatically.

Common error (`403 FORBIDDEN`, missing `syncstate:write`):

```json
{
  "error": {
    "code": "FORBIDDEN",
    "message": "Missing required scope: syncstate:write"
  },
  "requestId": "req_01HSW35J6TV"
}
```

### `POST /webhooks/dlq/replay`

Happy path (single item):

```bash
curl -sS -X POST "$BASE_URL/agents/v1/webhooks/dlq/replay" \
  -H "Authorization: Bearer $AGENTS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"id": 42}'
```

Example response (`200`):

```json
{
  "ok": true,
  "data": {
    "replayed": 1,
    "mode": "single",
    "ids": [42]
  },
  "requestId": "req_01HSW37F8DG"
}
```

Common error (`400 INVALID_REQUEST`, invalid replay body):

```json
{
  "error": {
    "code": "INVALID_REQUEST",
    "message": "Provide either `id` or `mode=all`"
  },
  "requestId": "req_01HSW3859NE"
}
```

### `GET /diagnostics/bundle`

Happy path:

```bash
curl -sS "$BASE_URL/agents/v1/diagnostics/bundle" \
  -H "Authorization: Bearer $AGENTS_API_KEY"
```

Example response (`200`, abbreviated):

```json
{
  "ok": true,
  "data": {
    "summary": {
      "status": "ok",
      "warningCount": 0,
      "errorCount": 0
    },
    "checks": {
      "auth": { "status": "ok" },
      "readiness": { "status": "ok" },
      "smoke": { "status": "ok" }
    }
  },
  "requestId": "req_01HSW39YE6W"
}
```

Common error (`403 FORBIDDEN`, missing scope):

```json
{
  "error": {
    "code": "FORBIDDEN",
    "message": "Missing required scope: diagnostics:read"
  },
  "requestId": "req_01HSW3ATMWF"
}
```
