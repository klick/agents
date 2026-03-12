# Agents - Agent Handbook (Markdown)

Last updated: 2026-03-12  
Canonical docs: https://marcusscheller.com/docs/agents/

This file is a compact, machine-friendly guide for integrating with Agents as the governed machine-access layer for Craft CMS and Craft Commerce.

## 1) Base URLs and contract descriptors

- API base: `/agents/v1`
- Contract descriptors:
  - `/agents/v1/capabilities` (scopes, endpoints, feature flags)
  - `/agents/v1/openapi.json` (OpenAPI descriptor)
  - `/agents/v1/schema` (endpoint-scoped machine schema)
- Root aliases:
  - `/capabilities` -> `/agents/v1/capabilities`
  - `/openapi.json` -> `/agents/v1/openapi.json`

## 2) Authentication and key delivery

Agents do not carry keys by themselves. The tool/runtime that makes HTTP requests sends the token.

Supported auth inputs:

- `Authorization: Bearer <token>`
- `X-Agents-Token: <token>`
- `?apiToken=<token>` only when query-token auth is enabled, and only for `GET`/`HEAD`

Write rules:

- write routes require header auth and `Content-Type: application/json`
- query-token auth is rejected on write routes even when enabled

Use `/agents/v1/auth/whoami` to validate identity and effective scopes.

## 3) Minimum scope profiles

Baseline read:

- `health:read`
- `readiness:read`
- `auth:read`
- `capabilities:read`
- `openapi:read`
- `schema:read`

Product read:

- baseline read
- `products:read`

Incremental sync:

- baseline read
- one or more domain scopes (`products:read`, `orders:read`, `entries:read`, `changes:read`)
- `syncstate:read`
- `syncstate:write`
- deprecated aliases: `consumers:read`, `consumers:write`

Reliability/ops:

- `diagnostics:read`
- `metrics:read`
- `incidents:read`
- `webhooks:dlq:read`
- `webhooks:dlq:replay`

## 4) First-call sequence

1. `GET /agents/v1/auth/whoami`
2. `GET /agents/v1/capabilities`
3. `GET /agents/v1/openapi.json`
4. `GET /agents/v1/schema?endpoint=products.list`
5. `GET /agents/v1/products?limit=5&fields=id,title,updatedAt`
6. (sync clients) `POST /agents/v1/sync-state/checkpoint`

## 5) Core endpoints

Read/sync:

- `/health`, `/readiness`, `/auth/whoami`
- `/products`
- `/orders`, `/orders/show`
- `/entries`, `/entries/show`
- `/changes`
- `/sections`
- `/adoption/metrics`
- `/metrics`
- `/incidents`
- `/diagnostics/bundle`

Integration state:

- `GET /sync-state/lag`
- `POST /sync-state/checkpoint`
- `GET /templates`
- `GET /starter-packs`
- `GET /schema`
- `GET /lifecycle`
- `GET /incidents`

Webhook reliability:

- `GET /webhooks/dlq`
- `POST /webhooks/dlq/replay`

Control-plane endpoints (`/control/*`) are experimental and feature-flagged.

Approval assurance:

- approvals persist the evaluated assurance mode (`single_approval`, `dual_control`, or `single_operator_degraded`)
- degraded single-operator fallback is recorded explicitly instead of being treated as equivalent to dual control
