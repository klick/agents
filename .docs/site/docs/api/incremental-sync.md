---
title: Incremental Sync
---

# Incremental Sync

Incremental sync is supported on:

- `/agents/v1/products`
- `/agents/v1/orders`
- `/agents/v1/entries`
- `/agents/v1/changes`

## Rules

- `cursor` takes precedence over `updatedSince`.
- Ordering is deterministic: `updatedAt`, then `id`.
- Responses include continuation metadata in `page`.
- Cursors are opaque and may expire.

## Changes feed behavior

`/changes` provides a unified stream for products/orders/entries with normalized actions:

- `created`
- `updated`
- `deleted` (tombstone with `snapshot: null`)

## Cursor recovery

If a cursor expires, restart from a recent RFC3339 `updatedSince` checkpoint.

## Contract source

For deeper contract details, see:

- `INCREMENTAL_SYNC_CONTRACT.md` in the repository

