# 30-Minute Quickstart (First Successful Action)

Goal: complete your first successful machine action in under 30 minutes.

## 1. Set base variables

```bash
export SITE_URL="https://example.test"
export BASE_URL="$SITE_URL/agents/v1"
export AGENTS_TOKEN="replace-with-token"
```

## 2. Configure runtime token + scopes

Set at minimum:

- `PLUGIN_AGENTS_ENABLED=true`
- `PLUGIN_AGENTS_REQUIRE_TOKEN=true`
- `PLUGIN_AGENTS_API_TOKEN=<token>`
- `PLUGIN_AGENTS_TOKEN_SCOPES=health:read,auth:read,products:read`

## 3. Verify runtime health

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/health"
```

Expected: JSON with `status` and readiness checks.

## 4. Verify caller identity and scopes

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/auth/whoami"
```

Expected: JSON with `principal` and `authorization.grantedScopes`.

## 5. Run first successful business action

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/products?status=live&limit=10"
```

Expected: JSON with `data` array.

## 6. Confirm deterministic validation behavior

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/products?limit=abc"
```

Expected: HTTP `400` and error payload with:

- `error: INVALID_REQUEST`
- `status: 400`
- `details` with query validation reason

## Troubleshooting

- `401 UNAUTHORIZED`: token missing/invalid; re-check `Authorization` header and configured token.
- `403 FORBIDDEN`: token valid but scope missing; add required scope and retry.
- `503 SERVICE_DISABLED`: set `PLUGIN_AGENTS_ENABLED=true`.
- `400 INVALID_REQUEST`: fix query parameter format/range as indicated in `details`.
