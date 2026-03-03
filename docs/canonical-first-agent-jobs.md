# Canonical First Agent Jobs

These are the three recommended first jobs for new integrations.

## Prerequisites

- Agents plugin enabled.
- API token/credential configured.
- Base URL set (for example `https://example.test/agents/v1`).

## Job 1: Catalog + Content Sync Loop

Purpose: keep a downstream index current with products and content entries.

Required scopes:

- `auth:read`
- `products:read`
- `entries:read`
- `changes:read`
- `consumers:write`

Flow:

1. Verify token and scopes.
2. Pull an initial products snapshot.
3. Pull an initial entries snapshot.
4. Continue with the changes feed.
5. Persist your cursor/checkpoint.

Example calls:

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/auth/whoami"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/products?status=live&limit=100"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/entries?status=live&limit=100"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/changes?types=products,entries&limit=100"
```

Checkpoint payload example:

```json
{
  "integrationKey": "catalog-sync",
  "resourceType": "changes",
  "cursor": "opaque-cursor-token",
  "updatedSince": "2026-03-03T00:00:00Z",
  "checkpointAt": "2026-03-03T12:00:00Z",
  "metadata": {"worker": "sync-a", "version": "1"}
}
```

## Job 2: Support Context Lookup

Purpose: retrieve order and content context for support workflows.

Required scopes:

- `auth:read`
- `orders:read`
- `entries:read`
- `entries:read_all_statuses` (optional, if drafts/non-live are required)

Flow:

1. Pull recent orders.
2. Resolve one order by `number` or `id`.
3. Pull support content entries by section/search.

Example calls:

```bash
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/orders?status=all&lastDays=14&limit=20"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/orders/show?number=A1B2C3D4"
curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/entries?section=support&status=live&limit=20"
```

## Job 3: Governed Return/Refund Approval Run

Purpose: execute a high-risk action only after policy and approval checks.

This flow requires `PLUGIN_AGENTS_REFUND_APPROVALS_EXPERIMENTAL=true`.

Required scopes (requester):

- `control:approvals:request`
- `control:approvals:read`

Required scopes (approver/executor):

- `control:approvals:decide`
- `control:actions:execute`
- `control:executions:read`

Flow:

1. Request approval for the action.
2. Review pending approvals.
3. Decide approval.
4. Execute action against approved request.
5. Audit result in execution ledger.

Approval request payload example:

```json
{
  "actionType": "return.request",
  "actionRef": "RET-100045",
  "reason": "Customer return requested",
  "metadata": {
    "source": "agent-runtime",
    "agentId": "returns-orchestrator",
    "traceId": "trace-100045"
  },
  "payload": {
    "orderNumber": "A1B2C3D4",
    "amount": 49.9,
    "currency": "GBP",
    "lineItems": [{"sku": "SKU-123", "qty": 1}]
  }
}
```

Execute payload example:

```json
{
  "actionType": "return.request",
  "actionRef": "RET-100045",
  "approvalId": 123,
  "idempotencyKey": "return-ret-100045-v1",
  "payload": {
    "orderNumber": "A1B2C3D4",
    "amount": 49.9,
    "currency": "GBP"
  }
}
```
