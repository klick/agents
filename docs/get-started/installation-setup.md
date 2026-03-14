# Installation & Setup

## 1. Install the plugin

```bash
composer require klick/agents:^0.21.9
php craft plugin/install agents
```

## 2. Run migrations (if needed)

```bash
php craft up
```

This creates plugin tables for:

- managed accounts
- internal control policies
- approvals
- execution ledger
- audit log
- webhook test sink captures (dev-only)

## 3. Open the Control Panel

Primary route:

- `admin/agents/status`

Navigation entry points:

- Sidebar: `Agents`
- Plugin settings page (`admin/settings/plugins`) redirects to `Status`

## 4. Validate key endpoints

API:

- `GET /agents/v1/health`
- `GET /agents/v1/schema`
- `GET /agents/v1/capabilities`
- `GET /agents/v1/auth/whoami` (requires token + `auth:read`)
- `GET /capabilities` (alias to `/agents/v1/capabilities`)
- `GET /openapi.json` (alias to `/agents/v1/openapi.json`)

## 5. Configure auth before production

By default, API token auth is required.

Set at least one credential source:

- `PLUGIN_AGENTS_API_TOKEN`
- `PLUGIN_AGENTS_API_CREDENTIALS`
- Managed key from CP `Agents -> Accounts`
