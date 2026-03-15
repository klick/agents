# Scope Guide

Use this page when you are creating a managed account and need to decide which scopes a worker actually needs.

This page is intentionally operator-facing:

- what a scope unlocks
- why you would assign it
- when a worker usually does **not** need it

If you want the technical auth transport details, see [Auth & Scopes](/api/auth-and-scopes).

## How To Choose Scopes

Start from the worker's job, not from the full scope list.

Ask four questions:

1. What does the worker need to read?
2. Does it need any sensitive data, or only normal redacted/list data?
3. Does it need to request or execute governed actions?
4. Does it need runtime operations visibility, or is it only reading content/commerce data?

Good default rule:

- start with the smallest useful set
- add scopes only when the worker has a clear reason to call the matching endpoint
- avoid sensitive scopes until the use case is proven

## Common Starter Sets

### First bootstrap worker

Use when you only want to prove the account/token works.

- `health:read`
- `readiness:read`
- `auth:read`

### Content-reading worker

Use when a worker reads entries, sections, assets, or taxonomy.

- `entries:read`
- `assets:read`
- `sections:read`
- optional: `categories:read`, `tags:read`, `globalsets:read`
- useful support scopes: `capabilities:read`, `openapi:read`

### Draft-writing worker

Use when a worker prepares drafts but must not publish directly.

- `entries:read`
- `entries:read_all_statuses`
- `sections:read`
- `entries:write:draft`
- `control:approvals:request`
- `control:approvals:read`
- if the same worker executes after approval:
  - `control:actions:execute`

### Sync worker

Use when a worker consumes incremental changes and stores checkpoints.

- `changes:read`
- `syncstate:read`
- `syncstate:write`
- optional: resource-specific read scopes like `entries:read` or `orders:read`

### Operations / monitoring worker

Use when a worker reports on system state, reliability, or account posture.

- `health:read`
- `readiness:read`
- optional: `metrics:read`, `incidents:read`, `lifecycle:read`, `diagnostics:read`

## Scope Catalog

## Core diagnostics and contract scopes

| Scope | What it means in plain language | Give it when | Usually not needed when |
| --- | --- | --- | --- |
| `health:read` | Lets a worker check whether the Agents service is up. | You want a bootstrap, smoke-check, or uptime worker. | The worker only reads content and you already know connectivity is stable. |
| `readiness:read` | Lets a worker read the overall readiness score and warnings. | You want a worker to report whether the site is operationally ready. | The worker only needs business/content data. |
| `auth:read` | Lets a worker inspect its own identity and active scopes via `/auth/whoami`. | You want easy debugging of “who am I and what can I do?”. | The worker is already stable and you do not need self-diagnostics. |
| `adoption:read` | Lets a worker read adoption and usage instrumentation for Agents itself. | You want internal reporting on how Agents features are being used. | The worker is about content, commerce, or approvals. Most workers do not need this. |
| `metrics:read` | Lets a worker read runtime metrics snapshots from `/metrics`. | You are building observability dashboards or operational summaries. | The worker is not doing monitoring or runtime reporting. |
| `incidents:read` | Lets a worker read a redacted runtime incident summary. | You want alerting or operational issue reporting. | The worker is focused on content, catalog, or approvals. |
| `lifecycle:read` | Lets a worker read machine-account lifecycle posture and risk signals. | You want to monitor account expiry, pause/revoke posture, or governance hygiene. | The worker is not responsible for account operations or governance reporting. |
| `diagnostics:read` | Lets a worker download the diagnostics/support bundle. | You are building a support or operator troubleshooting workflow. | The worker is not a support or troubleshooting tool. |
| `capabilities:read` | Lets a worker discover what the site supports. | You want the worker to adapt to installed features and contract shape. | The worker is fully hardcoded and does not need feature discovery. |
| `openapi:read` | Lets a worker read the OpenAPI contract. | You want generated clients, schema-aware tooling, or integration introspection. | The worker never needs to inspect the API contract itself. |
| `schema:read` | Lets a worker read machine-readable endpoint schemas. | You are building schema-driven tooling or validation. | The worker just calls known endpoints directly. |
| `templates:read` | Lets a worker read canonical integration templates and starter material. | You want tooling that bootstraps itself from provided templates. | The worker already knows exactly what to do. |

## Content and site structure scopes

| Scope | What it means in plain language | Give it when | Usually not needed when |
| --- | --- | --- | --- |
| `entries:read` | Read live entries and entry detail endpoints. | The worker reads published content. | The worker never touches entries. |
| `entries:read_all_statuses` | Read drafts, disabled, pending, expired, and other non-live entry states. | The worker needs to inspect drafts or non-live content states. | The worker only needs live published entries. |
| `entries:write:draft` | Create or update entry drafts through governed actions. | The worker prepares drafts for human review. | The worker is read-only or should never change content. |
| `entries:write` | Deprecated alias for `entries:write:draft`. | Only for backward compatibility with older integrations. | New workers should not use it. |
| `assets:read` | Read asset lists and asset details. | The worker needs images, documents, or media metadata. | The worker only needs entries or commerce records. |
| `categories:read` | Read categories and category details. | The worker reasons about taxonomy or category-driven navigation. | The worker does not use categories. |
| `tags:read` | Read tags and tag details. | The worker needs tag taxonomy. | The site does not use tags or the worker does not need them. |
| `globalsets:read` | Read global sets. | The worker needs shared site-wide content such as footer/legal/cookie text. | The worker only needs entry-specific content. |
| `contentblocks:read` | Read content block records. | The worker needs direct access to reusable/nested content blocks. | Entry-level content is enough. |
| `sections:read` | Read the section catalog. | The worker needs to understand site structure or restrict itself to certain sections. | The worker is hardcoded to one known entry or endpoint and never inspects structure. |
| `users:read` | Read user lists and basic user detail endpoints. | The worker needs assignee/author/operator user information. | The workflow never touches users. |
| `users:read_sensitive` | Read unredacted user profile detail such as email or sensitive fields. | The worker has a strong operational reason to handle user PII. | Most workers. Avoid unless clearly required. |

## Commerce and address scopes

| Scope | What it means in plain language | Give it when | Usually not needed when |
| --- | --- | --- | --- |
| `products:read` | Read product records. | The worker analyzes or reports on catalog products. | The site is content-only or the worker does not care about products. |
| `variants:read` | Read product variants. | The worker needs SKU/variant-level inventory or merchandising data. | Product-level data is enough. |
| `subscriptions:read` | Read subscriptions. | The worker reports on or syncs subscription records. | The site does not use subscriptions. |
| `transfers:read` | Read transfer records. | The worker needs stock/inventory transfer data. | The business does not use transfers. |
| `donations:read` | Read donation records. | The worker analyzes donations. | The site does not use donations. |
| `orders:read` | Read order metadata. | The worker builds sales reports or order-level summaries. | The worker is unrelated to orders. |
| `orders:read_sensitive` | Read unredacted order PII and financial details. | The worker must handle customer/order sensitive data with a clear business need. | Most reporting workers. Avoid unless essential. |
| `addresses:read` | Read addresses in the dedicated addresses API. | The worker needs address objects and the addresses API is enabled. | The site does not expose the addresses API or the worker does not need addresses. |
| `addresses:read_sensitive` | Read unredacted address PII. | A very specific workflow requires full address detail. | Almost always. Avoid unless necessary. |

## Change feeds, sync, and runtime integration scopes

| Scope | What it means in plain language | Give it when | Usually not needed when |
| --- | --- | --- | --- |
| `changes:read` | Read the unified incremental changes feed. | The worker syncs data to another system or tracks updates over time. | The worker just fetches current snapshots on demand. |
| `syncstate:read` | Read checkpoint/lag status for integrations. | The worker or operator needs to inspect sync health. | The worker does not manage sync checkpoints. |
| `syncstate:write` | Record checkpoints for sync lag tracking. | The worker is a real incremental sync consumer. | The worker is not responsible for checkpoints. |
| `consumers:read` | Deprecated alias for `syncstate:read`. | Only for older integrations. | New workers should not use it. |
| `consumers:write` | Deprecated alias for `syncstate:write`. | Only for older integrations. | New workers should not use it. |
| `webhooks:dlq:read` | Read failed webhook dead-letter events. | You are building webhook operations or failure reporting. | The worker does not manage webhook delivery issues. |
| `webhooks:dlq:replay` | Replay failed webhook dead-letter events. | An operator or recovery worker needs to retry failed deliveries. | The worker should never influence webhook delivery. |

## Control plane and governed-action scopes

These are only meaningful when `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`.

| Scope | What it means in plain language | Give it when | Usually not needed when |
| --- | --- | --- | --- |
| `control:policies:read` | Read control action policies. | The worker or operator tooling needs to inspect policy posture. | The worker just submits requests and does not inspect policy config. |
| `control:policies:write` | Create or update control policies. | You are building trusted operator tooling for policy administration. | Almost all normal workers. |
| `control:approvals:read` | Read the approval queue. | The worker monitors approval state or shows approval status elsewhere. | The worker only submits requests and humans handle the rest manually. |
| `control:approvals:request` | Create approval requests. | The worker asks humans to approve draft writes or other governed actions. | The worker is purely read-only. |
| `control:approvals:decide` | Approve or reject pending approvals. | A human-operated tool or explicit approver bot needs to sign off. | Normal automation workers. This should usually stay human-only. |
| `control:approvals:write` | Legacy combined scope for requesting and deciding approvals. | Only for backward compatibility. | New workers should use the split scopes instead. |
| `control:executions:read` | Read the execution ledger. | The worker or operator tooling needs to inspect past executions. | The worker does not report on execution history. |
| `control:actions:simulate` | Dry-run policy evaluation without actually executing. | You want “what would happen?” checks before execution. | The worker never simulates actions. |
| `control:actions:execute` | Execute approved governed actions. | The worker actually carries out a governed action after approval. | The worker only requests approval and someone else executes later. |
| `control:audit:read` | Read the immutable control-plane audit log. | You need compliance, governance, or operator reporting on approvals/executions. | The worker does not need audit history. |

## Sensitive scopes: use sparingly

These scopes deserve an extra justification before you assign them:

- `orders:read_sensitive`
- `addresses:read_sensitive`
- `users:read_sensitive`
- `control:approvals:decide`
- `control:policies:write`
- `webhooks:dlq:replay`

They either expose sensitive personal/financial data or let the worker change governance or delivery behavior.

## Practical Notes On The Scopes You Mentioned

### `adoption:read`

This is not a content or commerce scope.

Use it when you want a worker to answer questions like:

- how much is Agents being used?
- which parts of the contract are being exercised?
- what does platform adoption look like over time?

Most workers do **not** need it.

### `metrics:read`

This is for runtime observability.

Use it when you want a worker to:

- build ops dashboards
- summarize service behavior
- watch runtime metrics over time

A content worker, translation worker, or sales-report worker usually does **not** need it.

### `lifecycle:read`

This is for account and machine-identity governance.

Use it when you want a worker to report on:

- paused accounts
- revoked accounts
- expiry posture
- lifecycle risk signals

A content-reading or draft-writing worker usually does **not** need it.

## When In Doubt

If you are unsure, do this:

1. start with read-only scopes
2. add only the exact resource scopes the worker touches
3. keep sensitive scopes out unless the use case proves they are needed
4. keep publish/apply/governance decisions separate from content-reading tasks

That usually produces a smaller, safer account than starting from the full list.
