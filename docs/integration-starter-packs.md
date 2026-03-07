# Integration Starter Packs (curl + JavaScript + Python)

Starter packs are copy/paste-ready runtime snippets for canonical jobs. They are derived from the template catalog and kept aligned with:

- `GET /agents/v1/templates`
- `GET /agents/v1/starter-packs`
- `GET /agents/v1/schema`
- `GET /agents/v1/openapi.json`

Set runtime variables:

```bash
export SITE_URL="https://example.test"
export BASE_URL="$SITE_URL/agents/v1"
export AGENTS_TOKEN="replace-with-token"
```

## Where to access starter packs

- API: `GET /agents/v1/starter-packs` (`templates:read`)
- CLI: `craft agents/starter-packs`

Fetch all packs:

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs" | jq
```

Fetch one pack:

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=catalog-sync-loop" | jq
```

Extract runtime snippet to a local file:

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=catalog-sync-loop" \
  | jq -r '.starterPack.runtimes.curl.snippet' > catalog-sync-loop.sh
```

## Canonical starter packs

### 1) Catalog + Content Sync Loop (`catalog-sync-loop`)

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

### 2) Support Context Lookup (`support-context-lookup`)

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=support-context-lookup" \
  | jq -r '.starterPack.runtimes.curl.snippet'
```

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=support-context-lookup" \
  | jq -r '.starterPack.runtimes.javascript.snippet'
```

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=support-context-lookup" \
  | jq -r '.starterPack.runtimes.python.snippet'
```

### 3) Governed Return Approval Run (`governed-return-approval-run`)

Requires: `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=governed-return-approval-run" \
  | jq -r '.starterPack.runtimes.curl.snippet'
```

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=governed-return-approval-run" \
  | jq -r '.starterPack.runtimes.javascript.snippet'
```

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=governed-return-approval-run" \
  | jq -r '.starterPack.runtimes.python.snippet'
```

### 4) Governed Entry Draft Update (`governed-entry-draft-update`)

Requires: `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=governed-entry-draft-update" \
  | jq -r '.starterPack.runtimes.curl.snippet'
```

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=governed-entry-draft-update" \
  | jq -r '.starterPack.runtimes.javascript.snippet'
```

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/starter-packs?id=governed-entry-draft-update" \
  | jq -r '.starterPack.runtimes.python.snippet'
```
