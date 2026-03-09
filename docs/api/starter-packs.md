# Starter Packs

Starter packs are copy/paste snippets for canonical integration jobs. They are intended for developers and technical operators who want a fast first successful run.

Base path: `/agents/v1`

## Access

- API: `GET /agents/v1/starter-packs` (`templates:read`)
- API (single pack): `GET /agents/v1/starter-packs?id=<template-id>`
- CLI: `php craft agents/starter-packs`

## Why use this

- Avoid building integration flows from scratch.
- Keep code examples aligned with the live template/schema/openapi contract.
- Start from known-good snippets in `curl`, `javascript`, and `python`.

## Quick usage

```bash
export SITE_URL="https://example.test"
export BASE_URL="$SITE_URL/agents/v1"
export AGENTS_TOKEN="replace-with-token"
```

List starter packs:

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs" | jq
```

Fetch one pack:

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=catalog-sync-loop" | jq
```

Extract runtime snippets:

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=catalog-sync-loop" \
  | jq -r '.starterPack.runtimes.curl.snippet'
```

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=catalog-sync-loop" \
  | jq -r '.starterPack.runtimes.javascript.snippet'
```

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=catalog-sync-loop" \
  | jq -r '.starterPack.runtimes.python.snippet'
```

## Canonical template ids

- `catalog-sync-loop`
- `support-context-lookup`
- `governed-return-approval-run` (requires `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)
- `governed-entry-draft-update` (requires `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`)

## Related

- [Agent Bootstrap](/api/agent-bootstrap)
- [Endpoints](/api/endpoints)
- [CLI](/cli/)
