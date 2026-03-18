# Agents Positioning

Status: working positioning document
Date: 2026-03-09

See also:

- `AGENCY_FIRST_STRATEGY.md` for the canonical internal customer and roadmap lens

## Core thesis

Agents started as an "agents for Craft" idea.

It has evolved into something broader and stronger:

Agents is the governed machine-access layer for Craft CMS and Craft Commerce.

That means:

- agents are still the wedge
- governed machine access is the real category
- the product is useful for agents, automations, orchestrators, integrations, and operator workflows

## Brand, category, wedge

Brand:

- Agents

Primary category:

- Safe APIs and governance for AI agents and automations in Craft CMS and Craft Commerce

Short category version:

- Governed machine access for Craft

Wedge:

- AI agents

Reason people first care:

- teams want AI systems to read and act on Craft and Commerce data

Reason teams adopt:

- they need safe access, managed credentials, observability, and control

## One-line definition

Internal:

- Agents is the governed machine-access layer for Craft CMS and Craft Commerce.

External:

- Agents gives Craft a safe API and control plane for AI agents, automations, and integrations.

## Positioning statement

For agencies and delivery teams running Craft CMS and Craft Commerce sites for clients, Agents provides a governed machine-access layer with scoped APIs, managed credentials, diagnostics, and approval controls, so automation work stays predictable, observable, and auditable.

## What the product is

Agents does three jobs:

- API layer: structured, machine-ready access to Craft and Commerce data and actions
- Control layer: credentials, scopes, policies, approvals, and auditability
- Operations layer: readiness, diagnostics, sync-state, lifecycle, and reliability visibility

This is not only an "agent runtime" anymore.

It is the trust boundary between Craft and machine actors.

## What the product is not

Do not position Agents as:

- a chatbot plugin
- a model provider integration
- an agent framework
- a prompt management tool
- a generic headless API plugin
- a shell execution layer for production agents

These frames are either too narrow, misleading, or not where the product is strongest.

## Ideal customer profile

Primary:

- Craft agencies building and operating AI or automation solutions for clients
- agency technical leads who need machine access without custom API sprawl
- agency operators who want visibility and control in the Craft CP

Secondary:

- in-house Craft and Commerce teams adding AI workflows or automation
- platform and integration teams syncing Craft and Commerce data into external systems
- teams that need governed write workflows for sensitive changes

Not primary:

- teams looking for a simple chatbot feature
- non-technical buyers shopping for content-generation tools
- teams expecting a full model orchestration platform

## Core problem

Without Agents, teams typically choose between bad options:

- custom endpoints with uneven security and no shared contract
- risky runtime access patterns
- weak visibility into machine behavior
- no approval path for higher-risk operations
- ad hoc credentials and unclear ownership

Agents replaces that with one governed boundary for machine access.

## Core value props

Connect:

- Give agencies and delivery teams structured machine access to Craft and Commerce

Control:

- Keep credentials, scopes, policies, approvals, and audit trail in the Craft CP

Operate:

- Monitor readiness, diagnostics, lag, lifecycle posture, and reliability across ongoing client work

These three pillars should show up in most messaging.

## Positioning rule

If a feature helps machines:

- access Craft safely
- discover capabilities
- operate reliably
- be governed when risk is higher

it fits the product.

If a feature also helps agencies:

- deliver client automation safely
- explain and approve changes clearly
- package repeatable service patterns
- operate multiple sites with less support burden

it is probably strategically strong.

If a feature pushes the product toward:

- building the agent itself
- prompt tooling
- chat UI
- model-provider abstraction
- general orchestration unrelated to Craft trust boundaries

it is probably outside the core product.

## Messaging guidance

Say this:

- safe API access
- governed machine access
- managed credentials
- scoped access
- diagnostics and readiness
- approvals and audit trail
- AI agents and automations
- production-safe integration surface

Avoid leading with:

- agent runtime
- agent platform
- chatbot
- prompt layer
- orchestration engine
- shell execution

"Agent runtime" can still appear in technical docs, but it should not lead the story.

## Homepage messaging

Headline:

- Governed AI and automation APIs for Craft teams running client sites

Subhead:

- Connect agents, automations, and integrations to Craft through scoped APIs, managed credentials, diagnostics, and optional approval controls, with one clear operating layer for agency and delivery teams.

Proof points:

- Structured API access for content and commerce data
- Managed machine credentials and scopes in the Craft CP
- Readiness, diagnostics, and sync-state visibility
- Optional approvals and audit trail for sensitive actions
- Reusable workflow patterns that can be carried across multiple client projects

CTA options:

- Explore the API
- View Control Panel features
- Start with diagnostics and readiness

## Plugin Store messaging

Short description:

- Secure API and governance layer for AI agents, automations, and integrations in Craft CMS and Craft Commerce.

Recommended title support line:

- Safe AI and automation APIs for Craft CMS and Craft Commerce

Plugin Store body copy:

Agents gives AI agents, automations, and integrations a secure way to work with Craft CMS and Craft Commerce.

Instead of building custom endpoints or exposing risky runtime access, Agents provides scoped HTTP APIs, managed credentials, operational visibility, and optional approval controls. That gives agencies and delivery teams a safer way to connect tools like OpenAI, Claude, Gemini, n8n, Make, and internal services to Craft and Craft Commerce.

What it helps with:

- connect AI tools to Craft and Commerce data without custom API work
- sync products, orders, entries, and other content into external systems
- monitor readiness, lag, and integration health
- manage machine access from the Control Panel
- add approvals and audit trails for higher-risk actions

Core capabilities:

- agent-ready APIs for commerce and content data
- unified incremental changes feed for sync and indexing workflows
- sync-state endpoints for lag and checkpoint tracking
- discovery endpoints including `/capabilities`, `/openapi.json`, and `/schema`
- discovery files including `/llms.txt`, `/llms-full.txt`, and `/commerce.txt`
- managed accounts in the Control Panel for scoped credentials
- diagnostics bundle for faster triage and support handoff
- Craft-native CLI via `craft agents/*`

Secure by default:

- token authentication
- scope-based access control
- rate limiting
- sensitive data redaction when scope is missing
- fail-closed production behavior when credentials are missing

Optional governance features:

- credential expiry policies and reminders
- API key IP allowlists
- policy simulation before sensitive execution
- rules, approvals, and audit trail for governed actions
- governed write APIs for higher-risk workflows

## Verbal pitch

15-second version:

- Agents gives Craft a safe machine-access layer for AI agents and automations.

30-second version:

- Agents lets AI agents, automations, and integrations work with Craft and Craft Commerce through scoped APIs instead of custom endpoints or risky runtime access. Agencies and delivery teams get managed credentials, diagnostics, sync visibility, and optional approvals for sensitive actions, so production behavior stays predictable and auditable.

## Screenshot caption guidance

- Managed Accounts: create scoped credentials, pause access, rotate keys, and track usage
- Readiness and diagnostics: see integration health, lag, and support signals in one place
- Approvals workflow: review and govern higher-risk actions before execution
- Policy controls: define rules and approval requirements for sensitive operations
- Operational visibility: inspect activity, follow-up items, and audit history inside Craft

## Strategic implications

Keep "Agents" as the product name.

Do not force the product into a narrower identity than what it has become.

The correct mental model is:

- built for agents
- useful for any machine actor
- adopted because it creates a safer trust boundary around Craft and Commerce

## Bottom line

Position Agents as:

- the safe API and control plane for AI agents and automations in Craft

Do not position it as:

- just an agent runtime

That keeps the original idea intact while matching the product that now exists.
