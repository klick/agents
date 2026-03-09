# Compatibility & Deprecations

This policy defines what integration clients can rely on.

## Stability classes

- Production stable:
  - read/sync endpoints (`/health`, `/readiness`, `/auth/whoami`, `/products`, `/variants*`, `/subscriptions*`, `/transfers*`, `/donations*`, `/orders*`, `/entries*`, `/assets*`, `/categories*`, `/tags*`, `/global-sets*`, `/addresses*`, `/content-blocks*`, `/users*`, `/changes`, `/sections`)
  - integration-state endpoints (`/sync-state/lag`, `/sync-state/checkpoint`, `/templates`, `/starter-packs`, `/schema`, `/lifecycle`, `/incidents`)
  - descriptors (`/capabilities`, `/openapi.json`, root aliases)
  - webhook reliability endpoints (`/webhooks/dlq`, `/webhooks/dlq/replay`)
- Optional stable:
  - discovery docs (`/llms.txt`, `/llms-full.txt`, `/commerce.txt`)
- Experimental:
  - `/control/*` behind `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`

## Compatibility guarantees (stable surfaces)

- Additive-first change model:
  - new optional fields, endpoints, scopes, or enum values may be added in minor releases
  - existing documented fields and error codes are not removed or redefined in patch/minor releases
- Deterministic error envelope remains stable:
  - `error.code`, `error.message`, `requestId`
- Scope names remain stable once published for stable surfaces.
- Existing behavior may tighten only for security/reliability reasons (for example stricter validation), and will be documented in changelog notes.

## Deprecation process

- Deprecations are announced in docs + changelog before removal.
- Stable-surface removals happen only in a major release, except urgent security fixes.
- Target notice window:
  - at least `90` days and at least one minor release before a stable-surface removal
- Deprecated fields/endpoints should continue to function during the notice window.

### Active deprecations

- Canonical integration-state surface now uses:
  - `/sync-state/lag`
  - `/sync-state/checkpoint`
  - `syncstate:read`
  - `syncstate:write`
- Legacy aliases remain supported during the notice window:
  - `/consumers/lag`
  - `/consumers/checkpoint`
  - `consumers:read`
  - `consumers:write`
- Planned removal target for legacy aliases: **after two minor releases** from the rename introduction.

## Experimental surface policy

- Experimental features can change faster and may receive breaking changes in minor releases.
- Experimental scopes/routes are omitted from discovery descriptors when the feature flag is disabled.

## Client implementation guidance

- Poll `GET /agents/v1/capabilities` and `GET /agents/v1/openapi.json` during startup or CI to detect contract drift.
- Build tolerant parsers:
  - ignore unknown JSON fields
  - do not assume enum sets are closed
- Treat documented required fields as strict; treat optional fields as best-effort.
- Pin plugin major versions for production and validate against staging before upgrades.

## Related pages

- [API Overview](/api/)
- [Agent Bootstrap](/api/agent-bootstrap)
- [Execution Model](/security/execution-model)
