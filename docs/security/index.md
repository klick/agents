# Security

Security posture is visible in Dashboard -> Security and enforced in runtime services.

## Execution trust boundary

- Production runtime actions are API/policy-governed.
- Plugin runtime does not execute agent shell commands.
- CLI commands (`craft agents/*`) are for operator/developer usage.

See [Execution Model](/security/execution-model) for surface stability and trust-boundary details.

## Defaults

- token auth required by default
- fail-closed behavior in production by default
- query-token transport disabled by default and limited to `GET`/`HEAD` when enabled
- email redaction enabled by default for low-scope callers

## Production safety guardrail

If `PLUGIN_AGENTS_REQUIRE_TOKEN=false` in production, token enforcement is still forced unless:

- `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD=true`

## Credential posture

Runtime merges env and managed credentials and reports:

- total active credential count
- env credential count
- managed CP credential count
- effective token scopes
- managed key expiry status (expiring soon / expired)

Managed API keys can enforce:

- expiry policies (TTL + reminder window)
- IP allowlists (CIDR), enforced at runtime auth

## Webhook posture

Security view also reports:

- URL/secret configured state
- destination host
- timeout and max attempts
- delivery mode (`firehose` vs `targeted` subscriptions)

## Warnings

Security warnings/errors are surfaced in CP and startup logs for:

- invalid credential JSON shape
- invalid `PLUGIN_AGENTS_ENV_PROFILE` fallback
- token enforcement bypass in production
- token required but no usable credentials
- partial webhook configuration

## Environment profile posture

Security posture now includes profile metadata for runtime introspection:

- `environmentProfile`
- `environmentProfileSource` (`env` or `inferred`)
- `profileDefaultsApplied`
- `effectivePolicyVersion`

These are visible in:

- Dashboard -> Security
- `GET /agents/v1/capabilities` authentication metadata
- `GET /agents/v1/health` / `GET /agents/v1/readiness`
- diagnostics bundle output
