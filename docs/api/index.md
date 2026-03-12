# API Overview

Base path: `/agents/v1`

Agents exposes the governed machine-access layer for Craft CMS and Craft Commerce over scoped HTTP APIs.

The API provides:

- structured read and sync surfaces for content and commerce data
- contract descriptors for capability and schema negotiation
- integration state surfaces for sync-state, schema, lifecycle, and incidents
- governed control-plane actions when experimental writes are enabled

Runtime trust boundary:

- production actions flow through scoped HTTP APIs and policy gates
- managed credentials and scopes define what machine clients can do
- plugin runtime does not execute agent shell commands
- CLI commands are operator and developer tools (`craft agents/*`)

## Contract descriptors

- `GET /agents/v1/capabilities`
- `GET /agents/v1/openapi.json`
- `GET /agents/v1/auth/whoami`
- aliases: `GET /capabilities`, `GET /openapi.json`

These describe available endpoints, scopes, auth posture, field projections, and error taxonomy for integration clients.

Agent-focused Markdown handbook:

- https://marcusscheller.com/docs/agents/agent-handbook.md

## Endpoint classes

- read endpoints (health/readiness/auth/adoption/metrics/incidents/diagnostics/products/variants/subscriptions/transfers/donations/orders/entries/assets/categories/tags/global-sets/addresses/content-blocks/users/changes/sections)
- integration state endpoints (`/sync-state/*`, `/templates`, `/starter-packs`, `/schema`, `/lifecycle`, `/incidents`)
- webhook operations (`/webhooks/dlq`, `/webhooks/dlq/replay`)
- control endpoints (`/control/*`) when experimental flag is enabled
- list projection/filtering on key list endpoints via `fields` and `filter` query params

## Operating model

- use header-based tokens for machine clients
- assign the minimum scopes needed for each integration
- rely on `capabilities` and `openapi.json` as the machine-readable contract
- use readiness, incidents, lifecycle, and sync-state endpoints to keep integrations observable in production

See:

- [Agent Bootstrap](/api/agent-bootstrap)
- [Starter Packs](/api/starter-packs)
- [Auth & Scopes](/api/auth-and-scopes)
- [Endpoints](/api/endpoints)
- [Errors & Rate Limits](/api/errors-and-rate-limits)
- [Incremental Sync](/api/incremental-sync)
- [Compatibility & Deprecations](/api/compatibility-and-deprecations)
- [Execution Model](/security/execution-model)
