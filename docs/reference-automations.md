# Reference Automations (Schema/OpenAPI-Based)

These automations map directly to machine contracts exposed by:

- `GET /agents/v1/openapi.json`
- `GET /agents/v1/schema`
- `GET /agents/v1/templates`
- `GET /agents/v1/starter-packs`

Set runtime variables:

```bash
export BASE_URL="https://example.test/agents/v1"
export AGENTS_TOKEN="replace-with-token"
```

## 1) Catalog + Content Sync Loop

Template id: `catalog-sync-loop`

Contract lookups:

- `GET $BASE_URL/schema?endpoint=products.list`
- `GET $BASE_URL/schema?endpoint=entries.list`
- `GET $BASE_URL/schema?endpoint=changes.feed`
- `GET $BASE_URL/schema?endpoint=syncstate.checkpoint`

Example execution:

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/auth/whoami"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/products?status=live&limit=100"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/entries?status=live&limit=100"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/changes?types=products,entries&limit=100"
curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" "$BASE_URL/sync-state/checkpoint" \
  -d @docs/reference-automations/fixtures/catalog-sync-checkpoint.json
```

## 2) Support Context Lookup

Template id: `support-context-lookup`

Contract lookups:

- `GET $BASE_URL/schema?endpoint=orders.list`
- `GET $BASE_URL/schema?endpoint=orders.show`
- `GET $BASE_URL/schema?endpoint=entries.list`

Example execution:

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/orders?status=all&lastDays=14&limit=20"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/orders/show?number=A1B2C3D4"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/entries?section=support&status=live&limit=20"
```

## 3) Governed Return Approval Run

Template id: `governed-return-approval-run`

Requires: `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`

Contract lookups:

- `GET $BASE_URL/schema?endpoint=control.approvals.request`
- `GET $BASE_URL/schema?endpoint=control.approvals.decide`
- `GET $BASE_URL/schema?endpoint=control.actions.execute`

Example execution:

```bash
curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" "$BASE_URL/control/approvals/request" \
  -d @docs/reference-automations/fixtures/return-approval-request.json

curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" "$BASE_URL/control/approvals/decide" \
  -d @docs/reference-automations/fixtures/return-approval-decide.json

curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" -H "X-Idempotency-Key: return-ret-100045-v1" "$BASE_URL/control/actions/execute" \
  -d @docs/reference-automations/fixtures/return-action-execute.json
```

## 4) Governed Entry Draft Update

Template id: `governed-entry-draft-update`

Requires: `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`

Contract lookups:

- `GET $BASE_URL/schema?endpoint=control.approvals.request`
- `GET $BASE_URL/schema?endpoint=control.actions.execute`

Recommended scopes:

- `control:actions:execute`
- `entries:write:draft`
- `control:approvals:request`
- `control:approvals:decide`

Example execution:

```bash
curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" "$BASE_URL/control/approvals/request" \
  -d '{"actionType":"entry.updateDraft","actionRef":"ENTRY-OPS-1001","reason":"Prepare draft update for editorial review","metadata":{"source":"agent-runtime","agentId":"content-ops","traceId":"trace-entry-1001"},"payload":{"entryId":1001,"siteId":1}}'

curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" -H "X-Idempotency-Key: entry-ops-1001-v1" "$BASE_URL/control/actions/execute" \
  -d @docs/reference-automations/fixtures/entry-update-draft-execute.json
```
