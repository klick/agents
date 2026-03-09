# Discovery Files

Agents can serve proposal-style discovery docs at the site root:

- `/llms.txt`
- `/llms-full.txt` (extended export, optional)
- `/commerce.txt`

## Placement

- Keep `llms.txt`, `llms-full.txt`, and `commerce.txt` at the web root (`/<file>`).
- Do not move these to `/docs/agents/`; many crawlers/agents check root paths first.
- Use docs paths for human/operator docs and optional vendor handbook content.

Agent-focused Markdown handbook (vendor docs):

- `https://marcusscheller.com/docs/agents/agent-handbook.md`

API discovery aliases:

- `/capabilities` -> `/agents/v1/capabilities`
- `/openapi.json` -> `/agents/v1/openapi.json`

## Runtime behavior

- files are generated and cached
- responses include `ETag` and `Last-Modified`
- caches are invalidated on relevant content/commerce element changes
- `llms.txt` includes auth/scope hints for guarded API endpoints
- `llms-full.txt` provides extended API/capability context for richer crawler/agent bootstrapping

## CP discovery operations

Dashboard -> Discovery Docs tab provides:

- refresh all discovery docs
- refresh only `llms.txt`
- refresh only `llms-full.txt`
- refresh only `commerce.txt`
- clear discovery cache
- status/preview for all enabled docs

## Editable custom bodies

Settings -> discovery fields provide direct body overrides for:

- `llms.txt custom body`
- `commerce.txt custom body`

If a custom body is non-empty, that exact body is served. Use reset buttons in Settings to return to generated output.

## Content source

Generation settings come from plugin settings/config model, including:

- discovery toggles (`enableLlmsTxt`, `enableLlmsFullTxt`, `enableCommerceTxt`)
- TTL values
- custom body overrides (`llmsTxtBody`, `commerceTxtBody`)
- optional summary/links/policy/support metadata fields

## Clear Caches utility integration

Craft `Utilities -> Clear Caches` includes:

- `Agents discovery caches`

This clears cached `llms.txt`, `llms-full.txt`, and `commerce.txt` payloads.
