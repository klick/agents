# Agent Bootstrap

Use this page for a deterministic "first working call" path.

Base path: `/agents/v1`

## Minimum scope profiles

### Baseline read profile

- `health:read`
- `readiness:read`
- `auth:read`
- `capabilities:read`
- `openapi:read`
- `schema:read`

### First domain read (example: products)

- Baseline read profile
- `products:read`

### Incremental sync profile

- Baseline read profile
- one or more domain scopes (`products:read`, `orders:read`, `entries:read`, `changes:read`)
- `syncstate:read`
- `syncstate:write`
- deprecated aliases: `consumers:read`, `consumers:write`

### Reliability/ops profile

- `diagnostics:read`
- `metrics:read`
- `incidents:read`
- `webhooks:dlq:read`
- `webhooks:dlq:replay`

## Recommended first-call sequence

Set env once:

```bash
export BASE_URL="https://your-site.tld"
export AGENTS_API_KEY="<token>"
```

### 1. Validate token identity and scopes

```bash
curl -sS "$BASE_URL/agents/v1/auth/whoami" \
  -H "Authorization: Bearer $AGENTS_API_KEY"
```

Expected: `200` with credential identity and effective scopes.

### 2. Pull machine contract descriptors

```bash
curl -sS "$BASE_URL/agents/v1/capabilities" \
  -H "Authorization: Bearer $AGENTS_API_KEY"

curl -sS "$BASE_URL/agents/v1/openapi.json" \
  -H "Authorization: Bearer $AGENTS_API_KEY"
```

Expected: endpoint/scope metadata and API schema for client generation.

### 3. Pull endpoint schema for one surface

```bash
curl -sS "$BASE_URL/agents/v1/schema?endpoint=products.list" \
  -H "Authorization: Bearer $AGENTS_API_KEY"
```

Expected: machine-readable request/response schema for that endpoint.

### 4. Run first data read with projection

```bash
curl -sS "$BASE_URL/agents/v1/products?limit=5&fields=id,title,updatedAt" \
  -H "Authorization: Bearer $AGENTS_API_KEY"
```

Expected: `200` with bounded payload and deterministic list envelope.

### 5. (If syncing) write a sync-state checkpoint

```bash
curl -sS -X POST "$BASE_URL/agents/v1/sync-state/checkpoint" \
  -H "Authorization: Bearer $AGENTS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "resourceType": "product",
    "cursor": "cursor_123"
  }'
```

Expected: `200` and persisted checkpoint metadata.
If you send `integrationKey`, it must match the authenticated credential id; otherwise omit it and let the runtime bind it automatically.

## Common bootstrap failures

- `401 UNAUTHORIZED`: token missing/invalid.
- `403 FORBIDDEN`: token valid, but missing required scope.
- `400 INVALID_REQUEST`: malformed query/body (for example invalid `limit` or missing required fields).
- `503 SERVER_MISCONFIGURED`: token enforcement enabled without valid runtime credentials configured.

## Next links

- [Auth & Scopes](/api/auth-and-scopes)
- [Endpoints](/api/endpoints)
- [Errors & Rate Limits](/api/errors-and-rate-limits)
- [Compatibility & Deprecations](/api/compatibility-and-deprecations)
