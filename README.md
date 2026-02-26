# Agents Plugin

Machine-readable readiness and diagnostics API Craft CMS and Commerce.

Current plugin version: **0.3.0**

## Purpose

This plugin gives external/internal agents a stable interface for:

- health checks for automation (`/agents/v1/health`)
- readiness summaries (`/agents/v1/readiness`)
- product snapshot browsing (`/agents/v1/products`)
- read-only CLI discovery commands (`craft agents/*`)

It is intentionally scoped to **read-only, operational visibility** in this stage.

It also exposes proposal-oriented discovery files at root-level endpoints:

- `GET /llms.txt`
- `GET /commerce.txt`

## Installation

Requirements:

- PHP `^8.2`
- Craft CMS `^5.0`

After Plugin Store publication:

```bash
composer require klick/agents:^0.3.0
php craft plugin/install agents
```

For monorepo development, the package can also be installed via path repository at `plugins/agents`.

Recommended local workflow:

- develop in a dedicated Craft sandbox (not production-bound project roots)
- link plugin via local Composer path repo only in that sandbox
- use the scripted bootstrap/fixture/smoke/release steps in [DEVELOPMENT.md](DEVELOPMENT.md)
- restore production-bound projects to store-backed versions after local debugging

## Configuration

Environment variables:

- `PLUGIN_AGENTS_ENABLED` (`true`/`false`)
- `PLUGIN_AGENTS_API_TOKEN` (required when token enforcement is enabled)
- `PLUGIN_AGENTS_API_CREDENTIALS` (JSON credential set with optional per-credential scopes)
- `PLUGIN_AGENTS_REQUIRE_TOKEN` (default: `true`)
- `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD` (default: `false`, keep false)
- `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN` (default: `false`)
- `PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD` (default: `true`)
- `PLUGIN_AGENTS_TOKEN_SCOPES` (comma/space list, default scoped read set)
- `PLUGIN_AGENTS_REDACT_EMAIL` (default: `true`, applied when sensitive scope is missing)
- `PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE` (default: `60`)
- `PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS` (default: `60`)
- `PLUGIN_AGENTS_WEBHOOK_URL` (optional HTTPS endpoint for change notifications)
- `PLUGIN_AGENTS_WEBHOOK_SECRET` (required when webhook URL is set; used for HMAC signature)
- `PLUGIN_AGENTS_WEBHOOK_TIMEOUT_SECONDS` (default: `5`)
- `PLUGIN_AGENTS_WEBHOOK_MAX_ATTEMPTS` (default: `3`, max queue retry attempts)

These are documented in `.env.example`.

Enablement precedence:

- If `PLUGIN_AGENTS_ENABLED` is set, it overrides CP/plugin setting state.
- If `PLUGIN_AGENTS_ENABLED` is not set, CP/plugin setting `enabled` controls runtime on/off.

## Support

- Docs: https://github.com/klick/agents
- Issues: https://github.com/klick/agents/issues
- Source: https://github.com/klick/agents

## API Access

By default, v1 routes require token-based access (`PLUGIN_AGENTS_REQUIRE_TOKEN=true`).
Fail-closed behavior in production is enabled by default (`PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD=true`).
If `PLUGIN_AGENTS_REQUIRE_TOKEN=false` is set in production, the plugin will still enforce token auth unless `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD=true` is explicitly enabled.

Credential sources:

- `PLUGIN_AGENTS_API_CREDENTIALS` (strict JSON credential objects with per-credential scopes)
- `PLUGIN_AGENTS_API_TOKEN` (legacy single-token fallback)
- Control Panel managed credentials (Credentials tab: create/edit scopes/rotate/revoke/delete with last-used metadata)

Managed credentials are stored in plugin DB tables and participate in runtime auth alongside env credentials.

Credential lifecycle permission keys (CP):

- `agents-viewCredentials`
- `agents-manageCredentials` (create + edit scopes/display name)
- `agents-rotateCredentials`
- `agents-revokeCredentials`
- `agents-deleteCredentials`

Supported token transports:

- `Authorization: Bearer <token>` (default)
- `X-Agents-Token: <token>` (default)
- `?apiToken=<token>` only when `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN=true`

Example credential JSON:

```json
[
  {"id":"integration-a","token":"token-a","scopes":["health:read","readiness:read","products:read"]},
  {"id":"integration-b","token":"token-b","scopes":"orders:read orders:read_sensitive"}
]
```

Object-map credential JSON is also supported:

```json
{
  "integration-a": {"token":"token-a","scopes":["health:read","readiness:read"]},
  "integration-b": {"token":"token-b","scopes":"orders:read orders:read_sensitive"}
}
```

Validation notes:

- Single-object shape is accepted when it includes `token` (or `value`) and optional `id`/`scopes`.
- Scalar values in keyed-object mode are ignored to avoid accidental token expansion from malformed JSON.

### Endpoints

Base URL (this project): `/agents/v1`

- `GET /health`
- `GET /readiness`
- `GET /products`
- `GET /orders`
- `GET /orders/show` (requires exactly one of `id` or `number`)
- `GET /entries`
- `GET /entries/show` (requires exactly one of `id` or `slug`)
- `GET /changes`
- `GET /sections`
- `GET /capabilities`
- `GET /openapi.json`

Root-level discovery files:

- `GET /llms.txt` (public when enabled)
- `GET /commerce.txt` (public when enabled)

## CLI Commands

Craft-native command routes:

- `craft agents/product-list`
- `craft agents/order-list`
- `craft agents/order-show`
- `craft agents/entry-list`
- `craft agents/entry-show`
- `craft agents/section-list`
- `craft agents/discovery-prewarm`

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

# Prewarm llms.txt + commerce.txt cache
php craft agents/discovery-prewarm
php craft agents/discovery-prewarm --target=llms --json=1
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
- `cursor` (opaque cursor; legacy pagination + incremental continuation)
- `updatedSince` (RFC3339 timestamp bootstrap for incremental mode, for example `2026-02-24T12:00:00Z`)

### Orders endpoint parameters

- `/orders`: `status` (handle or `all`), `lastDays` (default 30), `limit` (1..200)
- `/orders` incremental: `cursor` (opaque), `updatedSince` (RFC3339). When incremental params are used and `lastDays` is omitted, the default window is `0` (no date-created cutoff).
- `/orders/show`: exactly one of `id` or `number`

### Entries endpoint parameters

- `/entries`: `section`, `type`, `status`, `search` (or `q`), `limit` (1..200)
- `/entries` incremental: `cursor` (opaque), `updatedSince` (RFC3339)
- `/entries/show`: exactly one of `id` or `slug`; optional `section` when using `slug`

### Changes endpoint parameters

- `/changes`: `types` (optional comma list: `products,orders,entries`), `updatedSince` (RFC3339 bootstrap), `cursor` (opaque continuation), `limit` (1..200)
- `/changes` returns normalized `data[]` items with:
  - `resourceType` (`product|order|entry`)
  - `resourceId` (string)
  - `action` (`created|updated|deleted`)
  - `updatedAt` (RFC3339 UTC)
  - `snapshot` (minimal object for `created|updated`, `null` for `deleted` tombstones)

### Incremental sync rules

- `cursor` takes precedence over `updatedSince` when both are provided.
- Incremental mode uses deterministic ordering: `updatedAt`, then `id`.
- Incremental responses include `page.syncMode=incremental`, `page.hasMore`, `page.nextCursor`, and snapshot window metadata.
- Cursor tokens are opaque and may expire; restart from a recent `updatedSince` checkpoint if needed.
- `/changes` cursor continuity also preserves the selected `types` filter.
- Invalid `updatedSince`/`cursor` inputs return `400 INVALID_REQUEST` with stable error payload fields.

### Webhook Delivery

Webhook notifications are optional and are enabled only when both `PLUGIN_AGENTS_WEBHOOK_URL` and `PLUGIN_AGENTS_WEBHOOK_SECRET` are configured.

Behavior:

- Events are queued asynchronously on `product|order|entry` create/update/delete changes.
- Event payload mirrors `/changes` items: `resourceType`, `resourceId`, `action`, `updatedAt`, `snapshot`.
- Retry behavior uses queue retries up to `PLUGIN_AGENTS_WEBHOOK_MAX_ATTEMPTS`.
- Variant changes are emitted as `product` `updated` events.

Request headers:

- `X-Agents-Webhook-Id`: unique event id
- `X-Agents-Webhook-Timestamp`: unix timestamp
- `X-Agents-Webhook-Signature`: `sha256=<hex hmac>`

Signature verification:

- signed string: `<timestamp>.<raw-request-body>`
- algorithm: `HMAC-SHA256`
- secret: `PLUGIN_AGENTS_WEBHOOK_SECRET`

Queue note:

- Webhooks are delivered by Craft queue workers; ensure `php craft queue/run` or `php craft queue/listen` is active in environments where webhook delivery is required.

### Discoverability endpoints

- `/capabilities`: machine-readable list of supported endpoints + CLI commands.
- `/openapi.json`: OpenAPI 3.1 descriptor for this API surface.

Discovery file behavior:

- `llms.txt` / `commerce.txt` are generated dynamically as plain text.
- They include `ETag` + `Last-Modified` headers and support `304 Not Modified`.
- Output is cached and invalidated on relevant content/product updates.

Example:

```bash
curl -H "Authorization: Bearer $PLUGIN_AGENTS_API_TOKEN" \
  "https://example.com/agents/v1/products?status=live&sort=title&limit=2"
```

## Response style

- JSON only
- All API responses include `X-Request-Id`.
- Guarded JSON/error responses set `Cache-Control: no-store, private`.
- Products response includes:
  - `data[]` with minimal product fields (`id`, `title`, `slug`, `status`, `updatedAt`, `url`, etc.)
  - `page` with `nextCursor`, `limit`, `count`
- Changes response includes:
  - `data[]` normalized change items (`resourceType`, `resourceId`, `action`, `updatedAt`, `snapshot`)
  - `page` with `nextCursor`, `hasMore`, `limit`, `count`, `updatedSince`, `snapshotEnd`
- Health/readiness include plugin, environment, and readiness score fields.
- Error responses use a stable schema:

```json
{
  "error": "UNAUTHORIZED",
  "message": "Missing or invalid token.",
  "status": 401,
  "requestId": "agents-9fd2b20abec4a65f"
}
```

## Security and reliability

- Rate limiting headers are returned on each guarded request:
  - `X-RateLimit-Limit`
  - `X-RateLimit-Remaining`
  - `X-RateLimit-Reset`
- Rate limiting is applied before and after auth checks to throttle invalid-token attempts.
- Exceeded limits return HTTP `429` with `RATE_LIMIT_EXCEEDED`.
- Missing/invalid credentials return HTTP `401`.
- Missing required scope returns HTTP `403` with `FORBIDDEN`.
- Non-`GET`/`HEAD` requests are rejected (`405 METHOD_NOT_ALLOWED`, or `400` when CSRF validation fails first).
- Misconfigured production token setup returns HTTP `503` with `SERVER_MISCONFIGURED`.
- Disabled runtime state returns HTTP `503` with `SERVICE_DISABLED`.
- Invalid request payload/params return HTTP `400` with `INVALID_REQUEST`.
- Missing resource lookups return HTTP `404` with `NOT_FOUND`.
- Query-token auth is disabled by default to reduce token leakage risk.
- Credential parsing is strict for `PLUGIN_AGENTS_API_CREDENTIALS` (credential objects only) and ignores malformed scalar entries.
- Sensitive order fields are scope-gated; email is redacted by default unless `orders:read_sensitive` is granted.
- Entry access to non-live statuses is scope-gated by `entries:read_all_statuses`.
- Endpoint is not meant for frontend/public user flows; token is the intended control plane.

Note: `llms.txt` and `commerce.txt` are public discovery surfaces and are not guarded by the API token.

## Troubleshooting Flow

1. Capture `X-Request-Id` from the failing response.
2. Confirm the error `status` + `error` code pair.
3. Match the code to the fix path:
   - `UNAUTHORIZED` (`401`): token missing/invalid or wrong transport.
   - `FORBIDDEN` (`403`): token missing required scope.
   - `INVALID_REQUEST` (`400`): malformed query or invalid identifier combination.
   - `NOT_FOUND` (`404`): requested resource does not exist.
   - `METHOD_NOT_ALLOWED` (`405`): endpoint only supports `GET`/`HEAD`.
   - `RATE_LIMIT_EXCEEDED` (`429`): respect `X-RateLimit-*` and retry after reset.
   - `SERVICE_DISABLED` (`503`): plugin runtime disabled by env/CP setting.
   - `SERVER_MISCONFIGURED` (`503`): token/security env configuration invalid.
4. Correlate by `X-Request-Id` in server logs for root-cause details.

## Discovery Text Config (`config/agents.php`)

These values can be overridden from your project via `config/agents.php`:

```php
<?php

return [
    'enableLlmsTxt' => true,
    'enableCommerceTxt' => true,
    'llmsTxtCacheTtl' => 86400,
    'commerceTxtCacheTtl' => 3600,
    'llmsSiteSummary' => 'Product and policy discovery for assistants.',
    'llmsIncludeAgentsLinks' => true,
    'llmsIncludeSitemapLink' => true,
    'llmsLinks' => [
        ['label' => 'Support', 'url' => '/support'],
        ['label' => 'Contact', 'url' => '/contact'],
    ],
    'commerceSummary' => 'Commerce metadata for discovery workflows.',
    'commerceCatalogUrl' => '/agents/v1/products?status=live&limit=200',
    'commercePolicyUrls' => [
        'shipping' => '/shipping-information',
        'returns' => '/returns',
        'payment' => '/payment-options',
    ],
    'commerceSupport' => [
        'email' => 'support@example.com',
        'phone' => '+1-555-0100',
        'url' => '/contact',
    ],
    'commerceAttributes' => [
        'currency' => 'USD',
        'region' => 'US',
    ],
];
```

## CP views

- `Agents` section now uses 6 deep-linkable cockpit tabs:
  - `agents/overview`
  - `agents/readiness`
  - `agents/discovery`
  - `agents/security`
  - `agents/settings`
  - `agents/credentials`
- Legacy CP paths remain valid:
  - `agents` resolves to `overview`
  - `agents/dashboard` resolves to `overview`
  - `agents/health` resolves to `readiness`
- Overview:
  - runtime enabled/disabled state and source (`env` vs CP setting)
  - env-lock aware runtime toggle
  - quick endpoint links + discovery prewarm entrypoint
  - ownership split guidance (`CP` vs `config/agents.php` vs `.env`)
- Readiness:
  - readiness score, criterion breakdown, component checks, warnings
  - health/readiness diagnostic JSON snapshots
- Discovery:
  - read-only `llms.txt`/`commerce.txt` status, metadata, preview snippets
  - operator actions: prewarm (`all|llms|commerce`) and clear cache
- Security:
  - read-only effective auth/rate-limit/redaction/webhook posture
  - centralized warning output from shared security policy logic
- Settings:
  - deep-linkable runtime plugin settings editor
  - preserves env-lock behavior for `enabled`
- Credentials:
  - managed credential lifecycle (create/edit scopes/rotate/revoke/delete)
  - one-time token reveal on create/rotate

## CP rollout regression checklist

1. Verify all six tabs load and subnav selection matches the active route.
2. Verify legacy aliases `agents`, `agents/dashboard`, and `agents/health` still resolve.
3. Verify runtime lightswitch is disabled when `PLUGIN_AGENTS_ENABLED` is set.
4. Verify discovery actions work:
   - prewarm `all`
   - prewarm `llms`
   - prewarm `commerce`
   - clear discovery cache
5. Verify security tab shows posture without exposing token/secret values.
6. Verify API and CLI behavior remains unchanged except expected `SERVICE_DISABLED` when runtime is off.

## Namespace migration

- PHP namespace root is now `Klick\\Agents` (for example `Klick\\Agents\\Plugin`).
- Plugin handle stays `agents` (CP nav, routes, and CLI command prefixes remain unchanged).

## Security Rollout Checklist

1. Set `PLUGIN_AGENTS_API_TOKEN` to a strong secret and keep `PLUGIN_AGENTS_REQUIRE_TOKEN=true`.
2. Prefer `PLUGIN_AGENTS_API_CREDENTIALS` for per-integration token/scope separation.
3. Keep `PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD=true`.
4. Keep `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD=false`.
5. Keep `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN=false` unless legacy clients require it temporarily.
6. Start with default scopes; only add elevated scopes when required:
   - `orders:read_sensitive`
   - `entries:read_all_statuses`
7. Verify `capabilities`/`openapi.json` outputs reflect active auth transport/settings.
8. Run `scripts/security-regression-check.sh` against your environment before promotion.

## Scope Migration Notes

- Prior behavior effectively granted broad read access to any valid token.
- New default scopes intentionally exclude elevated permissions.
- To preserve legacy broad reads temporarily, set:
  - `PLUGIN_AGENTS_TOKEN_SCOPES=\"health:read readiness:read products:read orders:read orders:read_sensitive entries:read entries:read_all_statuses changes:read sections:read capabilities:read openapi:read\"`

## Secure Deployment Verification

```bash
# 1) Missing token should fail
curl -i "https://example.com/agents/v1/health"

# 2) Query token should fail by default
curl -i "https://example.com/agents/v1/health?apiToken=$PLUGIN_AGENTS_API_TOKEN"

# 3) Header token should pass
curl -i -H "Authorization: Bearer $PLUGIN_AGENTS_API_TOKEN" \
  "https://example.com/agents/v1/health"

# 4) Non-live entries should require elevated scope
curl -i -H "Authorization: Bearer $PLUGIN_AGENTS_API_TOKEN" \
  "https://example.com/agents/v1/entries?status=all&limit=1"

# 5) Run local regression script
./scripts/security-regression-check.sh https://example.com "$PLUGIN_AGENTS_API_TOKEN"
```

## Roadmap

Planned improvements include:

- Expanded filtering and pagination controls for existing read-only endpoints.
- Additional diagnostics for operational readiness and integration health.
- Broader OpenAPI coverage and schema detail improvements.
- Optional export/report formats for automation workflows.
- Continued hardening of auth, rate limiting, and observability.

### Incremental Sync Contract (v0.2.0)

The formal contract for checkpoint-based sync is documented in [`INCREMENTAL_SYNC_CONTRACT.md`](INCREMENTAL_SYNC_CONTRACT.md).

Highlights:

- `cursor`-first continuation semantics with `updatedSince` bootstrap support.
- Deterministic ordering (`updatedAt`, then `id`) and at-least-once replay model.
- Tombstone/delete signaling through `GET /agents/v1/changes`.
