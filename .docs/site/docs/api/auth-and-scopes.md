---
title: Auth & Scopes
---

# Auth & Scopes

## Auth requirement

By default, all `/agents/v1/*` endpoints require token auth (`PLUGIN_AGENTS_REQUIRE_TOKEN=true`).

Production remains fail-closed by default (`PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD=true`).

## Token transports

- `Authorization: Bearer <token>`
- `X-Agents-Token: <token>`
- `?apiToken=<token>` only if `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN=true`

## Credential sources

- `PLUGIN_AGENTS_API_CREDENTIALS` (JSON object/array, supports per-credential scopes)
- `PLUGIN_AGENTS_API_TOKEN` (single-token fallback)

## Default scopes

- `health:read`
- `readiness:read`
- `products:read`
- `orders:read`
- `entries:read`
- `changes:read`
- `sections:read`
- `capabilities:read`
- `openapi:read`

Elevated scopes:

- `orders:read_sensitive`
- `entries:read_all_statuses`

## Example credentials JSON

```json
[
  {"id":"integration-a","token":"token-a","scopes":["health:read","readiness:read","products:read"]},
  {"id":"integration-b","token":"token-b","scopes":"orders:read orders:read_sensitive"}
]
```

