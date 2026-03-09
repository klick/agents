# Configuration

Agents uses a combination of environment variables and plugin settings.

Runtime model:

- API routes are the production execution surface.
- CLI commands are operator/developer tools.
- Discovery docs are optional public discovery features.

## Environment Variables

### Core runtime

- `PLUGIN_AGENTS_ENV_PROFILE` (optional: `local|test|staging|production`)
- `PLUGIN_AGENTS_ENABLED`
- `PLUGIN_AGENTS_REQUIRE_TOKEN` (default `true`)
- `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD` (default `false`)
- `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN` (default `false`)
- `PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD` (default `true`)

### Credentials and scopes

- `PLUGIN_AGENTS_API_TOKEN`
- `PLUGIN_AGENTS_API_CREDENTIALS` (JSON)
- `PLUGIN_AGENTS_TOKEN_SCOPES`
- `PLUGIN_AGENTS_ENABLE_USERS_API` (default `false`)

### Lifecycle governance (optional)

- `PLUGIN_AGENTS_LIFECYCLE_METADATA_MAP` (JSON map keyed by credential handle)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_UNUSED_WARN_DAYS` (default `30`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_UNUSED_CRITICAL_DAYS` (default `90`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_NEVER_USED_WARN_DAYS` (default `30`)
- `PLUGIN_AGENTS_LIFECYCLE_STALE_NEVER_USED_CRITICAL_DAYS` (default `90`)
- `PLUGIN_AGENTS_LIFECYCLE_ROTATION_WARN_DAYS` (default `45`)
- `PLUGIN_AGENTS_LIFECYCLE_ROTATION_CRITICAL_DAYS` (default `120`)

### Privacy and rate limiting

- `PLUGIN_AGENTS_REDACT_EMAIL` (default `true`)
- `PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE` (default `60`)
- `PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS` (default `60`)

### Webhooks

- `PLUGIN_AGENTS_WEBHOOK_URL`
- `PLUGIN_AGENTS_WEBHOOK_SECRET`
- `PLUGIN_AGENTS_WEBHOOK_TIMEOUT_SECONDS` (default `5`)
- `PLUGIN_AGENTS_WEBHOOK_MAX_ATTEMPTS` (default `3`)

### Experimental surfaces

- `PLUGIN_AGENTS_WRITES_EXPERIMENTAL` (default `false`)
- Control CP (`agents/control/*`) follows `PLUGIN_AGENTS_WRITES_EXPERIMENTAL` (single gate).

## Environment profile defaults

When explicit `PLUGIN_AGENTS_*` posture vars are unset, runtime defaults are sourced from the active profile:

- `local`: `rateLimitPerMinute=300`, `webhookMaxAttempts=2`, `webhookTimeoutSeconds=5`
- `test`: `rateLimitPerMinute=300`, `webhookMaxAttempts=2`, `webhookTimeoutSeconds=5`
- `staging`: `rateLimitPerMinute=120`, `webhookMaxAttempts=3`, `webhookTimeoutSeconds=5`
- `production`: `rateLimitPerMinute=60`, `webhookMaxAttempts=3`, `webhookTimeoutSeconds=5`

Profile resolution:

1. `PLUGIN_AGENTS_ENV_PROFILE` when set
2. otherwise inferred from `ENVIRONMENT`/`CRAFT_ENVIRONMENT`

Runtime precedence:

1. explicit env var
2. profile default
3. built-in fallback

## CP Settings

`Agents -> Settings` controls:

- API availability (`enabled`) unless env-locked by `PLUGIN_AGENTS_ENABLED`
- live agent usage indicator (`enableCredentialUsageIndicator`)
- discovery file switches (`llms.txt`, `llms-full.txt`, `commerce.txt`)
- custom discovery body overrides (`llmsTxtBody`, `commerceTxtBody`) with reset actions

`config/agents.php` can override these settings; when overridden, CP fields are shown as locked.

## Agent policy controls (CP)

`Agents -> Agents` supports per-agent controls in addition to scopes:

- webhook resource/action subscriptions
- optional credential TTL (days) and reminder window
- IP allowlist (CIDR/IP entries)

## Enablement precedence

1. If `PLUGIN_AGENTS_ENABLED` is set, it is the source of truth.
2. Otherwise the plugin setting `enabled` controls runtime state.
