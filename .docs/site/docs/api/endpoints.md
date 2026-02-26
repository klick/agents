---
title: Endpoints
---

# Endpoints

## Health & Readiness

- `GET /agents/v1/health`
- `GET /agents/v1/readiness`

## Products

- `GET /agents/v1/products`
- Params:
  - `q`
  - `status` (`live|pending|disabled|expired|all`, default `live`)
  - `sort` (`updatedAt|createdAt|title`, default `updatedAt`)
  - `limit` (1..200, default 50)
  - `cursor`
  - `updatedSince` (RFC3339)

## Orders

- `GET /agents/v1/orders`
- `GET /agents/v1/orders/show`
- `/orders` params:
  - `status`
  - `lastDays` (default 30)
  - `limit`
  - `cursor`
  - `updatedSince`
- `/orders/show`: exactly one of `id` or `number`

## Entries

- `GET /agents/v1/entries`
- `GET /agents/v1/entries/show`
- `/entries` params:
  - `section`
  - `type`
  - `status`
  - `search` or `q`
  - `limit`
  - `cursor`
  - `updatedSince`
- `/entries/show`: exactly one of `id` or `slug`; optional `section` with `slug`

## Changes

- `GET /agents/v1/changes`
- Params:
  - `types` (comma list: `products,orders,entries`)
  - `updatedSince`
  - `cursor`
  - `limit`

`data[]` item shape:

- `resourceType` (`product|order|entry`)
- `resourceId`
- `action` (`created|updated|deleted`)
- `updatedAt`
- `snapshot` (`null` for tombstones)

## Other API endpoints

- `GET /agents/v1/sections`
- `GET /agents/v1/capabilities`
- `GET /agents/v1/openapi.json`

## Public discovery endpoints

- `GET /llms.txt`
- `GET /commerce.txt`

