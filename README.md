# Agents Plugin

Machine-readable readiness and diagnostics API Craft CMS and Commerce.

Current plugin version: **0.1.1**

## Purpose

This plugin gives external/internal agents a stable interface for:

- health checks for automation (`/agents/v1/health`)
- readiness summaries (`/agents/v1/readiness`)
- product snapshot browsing (`/agents/v1/products`)
- read-only CLI discovery commands (`craft agents/*`)

It is intentionally scoped to **read-only, operational visibility** in this stage.

## Installation

Requirements:

- PHP `^8.2`
- Craft CMS `^5.0`

After Plugin Store publication:

```bash
composer require klick/agents:^0.1.1
php craft plugin/install agents
```

Before publication (or for development), install from source:

```bash
composer config repositories.klick-agents vcs https://github.com/klick/agents
composer require klick/agents:dev-main
php craft plugin/install agents
```

For monorepo development, the package can also be installed via path repository at `plugins/agents`.

## Configuration

Environment variables:

- `PLUGIN_AGENTS_ENABLED` (`true`/`false`)
- `PLUGIN_AGENTS_API_TOKEN` (required in prod for access control)
- `PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE` (default: `60`)
- `PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS` (default: `60`)

These are documented in `.env.example`.

## Support

- Docs: https://github.com/klick/agents/blob/main/README.md
- Issues: https://github.com/klick/agents/issues
- Source: https://github.com/klick/agents

## API Access

All v1 routes require token-based access unless `PLUGIN_AGENTS_API_TOKEN` is empty.

- `Authorization: Bearer <token>`
- `X-Agents-Token: <token>`
- `?apiToken=<token>` (query fallback)

### Endpoints

Base URL (this project): `/agents/v1`

- `GET /health`
- `GET /readiness`
- `GET /products`
- `GET /orders`
- `GET /orders/show` (requires exactly one of `id` or `number`)
- `GET /entries`
- `GET /entries/show` (requires exactly one of `id` or `slug`)
- `GET /sections`
- `GET /capabilities`
- `GET /openapi.json`

## CLI Commands

Craft-native command routes:

- `craft agents/product-list`
- `craft agents/order-list`
- `craft agents/order-show`
- `craft agents/entry-list`
- `craft agents/entry-show`
- `craft agents/section-list`

Examples:

```bash
# Product discovery (text output)
php craft agents/product-list --status=live --limit=10

# Product discovery (JSON output)
php craft agents/product-list --status=all --search=emboss --limit=5 --json=1

# Low stock view
php craft agents/product-list --low-stock=1 --low-stock-threshold=10 --limit=25

# Orders from last 14 days
php craft agents/order-list --status=shipped --last-days=14 --limit=20

# Show a single order
php craft agents/order-show --number=A1B2C3D4
php craft agents/order-show --resource-id=12345

# Entries
php craft agents/entry-list --section=termsConditionsB2b --status=live --limit=20
php craft agents/entry-show --slug=shipping-information
php craft agents/entry-show --resource-id=123

# Sections
php craft agents/section-list
```

CLI output defaults to human-readable text. Add `--json=1` for machine consumption.

Identifier notes for show commands:

- `agents/order-show`: use exactly one of `--number` or `--resource-id`.
- `agents/entry-show`: use exactly one of `--slug` or `--resource-id`.

### Products endpoint parameters

- `q` (search text)
- `status` (`live|pending|disabled|expired|all`, default `live`)
- `sort` (`updatedAt|createdAt|title`, default `updatedAt`)
- `limit` (1..200, default 50)
- `cursor` (opaque pagination cursor)

### Orders endpoint parameters

- `/orders`: `status` (handle or `all`), `lastDays` (default 30), `limit` (1..200)
- `/orders/show`: exactly one of `id` or `number`

### Entries endpoint parameters

- `/entries`: `section`, `type`, `status`, `search` (or `q`), `limit` (1..200)
- `/entries/show`: exactly one of `id` or `slug`; optional `section` when using `slug`

### Discoverability endpoints

- `/capabilities`: machine-readable list of supported endpoints + CLI commands.
- `/openapi.json`: OpenAPI 3.1 descriptor for this API surface.

Example:

```bash
curl -H "Authorization: Bearer $PLUGIN_AGENTS_API_TOKEN" \
  "https://example.com/agents/v1/products?status=live&sort=title&limit=2"
```

## Response style

- JSON only
- Products response includes:
  - `data[]` with minimal product fields (`id`, `title`, `slug`, `status`, `updatedAt`, `url`, etc.)
  - `page` with `nextCursor`, `limit`, `count`
- Health/readiness include plugin, environment, and readiness score fields.

## Security and reliability

- Rate limiting headers are returned on each request:
  - `X-Ratelimit-Limit`
  - `X-Ratelimit-Remaining`
  - `X-Ratelimit-Reset`
- Exceeded limits return HTTP `429` with `RATE_LIMIT_EXCEEDED`.
- Missing/invalid credentials return HTTP `401`.
- Endpoint is not meant for frontend/public user flows; token is the intended control plane.

## CP views

- `Agents` section appears in Craft CP for quick inspection.

## Roadmap

Planned improvements include:

- Expanded filtering and pagination controls for existing read-only endpoints.
- Additional diagnostics for operational readiness and integration health.
- Broader OpenAPI coverage and schema detail improvements.
- Optional export/report formats for automation workflows.
- Continued hardening of auth, rate limiting, and observability.
