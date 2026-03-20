# External Plugin Adapters

Agents can expose read-only resources from other Craft plugins through standalone adapter plugins.

## How it works

- Agents core owns the provider contract, scope format, endpoints, and contract descriptors.
- Adapter plugins register external resource providers during bootstrap.
- Registered resources appear automatically in:
  - `GET /agents/v1/capabilities`
  - `GET /agents/v1/openapi.json`
  - `GET /agents/v1/schema`
  - `Agents -> Accounts` external plugin scope groups

## Scope format

External resources use explicit per-resource scopes:

- `plugins:{plugin}:{resource}:read`

Example:

- `plugins:retour:redirects:read`

## Endpoints

When a provider is registered, Agents exposes:

- `GET /agents/v1/plugins/{plugin}/{resource}`
- `GET /agents/v1/plugins/{plugin}/{resource}/{id}`

Example:

- `GET /agents/v1/plugins/retour/redirects`
- `GET /agents/v1/plugins/retour/redirects/123`

If no provider is registered for a plugin/resource pair, Agents returns `404 NOT_FOUND` and does not advertise the resource in capabilities, OpenAPI, or schema.

## Reference adapter: Retour

The first reference adapter in this repo is the standalone `Retour` adapter package:

- package path: `adapters/retour`
- resource scope: `plugins:retour:redirects:read`

Its purpose is to let agents and operators inspect redirect rules for:

- redirect hygiene reviews
- migration audits
- broken-link follow-up
- redirect recommendation workflows

## User setup flow

1. Install `Agents`.
2. Install the standalone adapter plugin.
3. Ensure the target plugin is installed.
4. Open `Agents -> Accounts`.
5. Assign the new external scope to the relevant managed account.
6. Validate the surfaced contract in `GET /agents/v1/capabilities`.

## Real install validation

For the reference adapter, the real acceptance test is not just a registry smoke check. Validate it in a Craft app with:

1. `klick/agents` installed
2. `nystudio107/craft-retour` installed and enabled
3. the local adapter package installed from `adapters/retour`
4. a managed account carrying `plugins:retour:redirects:read`
5. live checks against:
   - `GET /agents/v1/capabilities`
   - `GET /agents/v1/plugins/retour/redirects`
   - `GET /agents/v1/plugins/retour/redirects/{id}`

This repo includes an opt-in real-install QA script for that path:

- `scripts/qa/retour-adapter-real-install-check.sh`

Enable it in the release gate with:

- `AGENTS_RUN_RETOUR_REAL_INSTALL=1 bash scripts/qa/release-gate.sh`

## Developer adapter flow

Adapter plugins should:

1. implement `ExternalResourceProviderInterface`
2. define one or more `ExternalResourceDefinition` resources
3. register the provider on `Plugin::EVENT_REGISTER_EXTERNAL_RESOURCE_PROVIDERS`
4. stay read-only first
5. avoid coupling Agents core to the target plugin

## Packaging guidance

Keep adapters as standalone packages/plugins:

- good: `klick/agents-retour`
- not recommended: bundling third-party plugin support directly into Agents core

This keeps release cadence, dependencies, and support boundaries cleaner.
