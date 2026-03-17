# Agents vs Element API

If you build endpoints with Element API, you can absolutely get useful JSON out of Craft.

The difference is that `Agents` is not just an endpoint tool. It is a governed machine-access layer around those endpoints.

## Comparison

| Area | Element API | Agents |
| --- | --- | --- |
| Primary job | Publish custom JSON/feed endpoints for elements | Act as a governed backend for machines, workers, and LLMs |
| Setup model | Define routes in `config/element-api.php` and write the query/transformer yourself | Create managed accounts, assign scopes, and use a stable API surface |
| Auth model | Whatever you build yourself | Built-in machine credentials, scoped tokens, optional TTL/IP controls |
| Discovery | No standard self-description by default | `/capabilities`, `/openapi.json`, `/auth/whoami` for machine-readable discovery |
| Writes and governance | You invent your own write model and approval flow | Governed write/proposal flows already exist behind scopes and approvals |
| Consistency | Each project's endpoints can differ a lot | One consistent contract across content, commerce, diagnostics, lifecycle, incidents, and approvals |
| Ops and observability | You build error contracts, audit, readiness, and similar concerns yourself | Readiness, incidents, lifecycle, sync-state, and an audit-oriented operating model are already part of it |
| LLM and agent friendliness | Possible, but you hand the model bespoke endpoints and docs | Designed for machine clients: discoverable, scoped, and predictable |
| Long-term maintenance | Fast at first, but tends to become endpoint sprawl | More upfront structure, less reinvention per integration |

## Rule of Thumb

Use Element API when you need project-specific content feeds.

Use Agents when you want Craft to act like a governed backend for workers, LLMs, automations, or multiple external integrations.

Put more bluntly:

- Element API gives you endpoints.
- Agents gives you an operating model.

## When Element API Fits Better

- You need public or semi-public read feeds.
- You only need a few custom content endpoints.
- You do not need approvals, scoped machine identities, or machine-readable discovery.

## When Agents Fits Better

- You expect multiple integrations over time.
- You need least-privilege machine tokens.
- You want governed draft or proposal flows.
- You want readiness, diagnostics, and a stable machine contract.
- You are connecting external assistants, workers, or automations.

## Practical Difference

`Element API` is a good fit when the question is:

> How do I expose some content as JSON?

`Agents` is a better fit when the question is:

> How do I let machines work with Craft safely and predictably over time?

## See Also

- [Get Started](/get-started/)
- [API Overview](/api/)
- [Execution Model](/security/execution-model)
- Element API plugin docs: https://github.com/craftcms/element-api
