# Incremental Sync

Incremental sync is supported on list endpoints and on `/changes`.

## Supported endpoints

- `/products`
- `/variants`
- `/subscriptions`
- `/transfers`
- `/donations`
- `/orders`
- `/entries`
- `/assets`
- `/categories`
- `/tags`
- `/global-sets`
- `/addresses` (when enabled)
- `/content-blocks`
- `/users` (when enabled)
- `/changes`

## Inputs

- `updatedSince` (RFC3339 UTC timestamp)
- `cursor` (opaque continuation token)

## Rules

- `cursor` takes precedence over `updatedSince` when both are provided.
- Ordering is deterministic (`updatedAt`, then stable tie-breakers).
- Incremental responses include continuation metadata (`hasMore`, `nextCursor`).
- Cursor tokens are opaque and may expire; restart from a recent `updatedSince` checkpoint.
- `/changes` cursor continuity preserves selected `types` filter.

## Response mode hint

List endpoints expose sync mode metadata so clients can distinguish:

- full snapshot mode
- incremental mode

## Validation behavior

Malformed cursor/time values produce deterministic `400 INVALID_REQUEST` style responses.
