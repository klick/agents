# Agency-First Strategy

Status: canonical internal strategy note  
Date: 2026-03-18

See also:

- `ACTIVATION_FUNNEL.md` for the internal install -> activation -> retention model
- `FIRST_ACCOUNT_SETUP_JOURNEY.md` for the concrete setup flow through first account creation

## Decision

Agents is being built first for agencies that run Craft sites on behalf of clients.

This is the primary strategic decision now shaping:

- roadmap priority
- product framing
- workflow examples
- control-plane UX
- support boundaries
- public positioning

The product is not primarily being optimized for self-serve end clients who manage their own sites day to day.

## Core Thesis

The strongest path to adoption is:

- agencies already own the implementation and support relationship
- agencies already operate Craft and Craft Commerce sites over time
- agencies need a safe way to introduce automation without increasing operational risk
- agencies can resell successful automation patterns across multiple clients

So the product promise is:

- help agencies sell and operate AI automation on client Craft sites without losing control

## ICP

### Primary customer

- Craft agencies
- especially agencies that:
  - build and maintain websites for clients
  - keep long-term backend responsibility
  - want to add AI or automation services without building a custom governance layer every time

### Primary operator

- agency delivery teams
- agency technical leads
- agency support and maintenance teams
- agency strategists or consultants who define automation boundaries but do not want raw backend chaos

### Primary beneficiary

- the agency's client
- but as a second-order beneficiary, not the primary product operator

### Not primary

- self-serve end clients expecting to run their own automation stack
- non-technical buyers looking for “AI content generation” as a standalone feature
- teams looking for a general orchestration or prompt platform

## Buying And Usage Model

Agencies do not usually buy this only as internal tooling.

They buy it because it helps them:

- win client work
- keep client work
- expand retainers
- reduce delivery risk
- standardize successful automation patterns

The product therefore needs to support three agency jobs:

1. sell the promise
2. operate the workflow safely
3. reuse the pattern across multiple clients

If a feature is strong technically but weak on those three jobs, it is not core.

## Product Implications

### 1. Safety over novelty

Agencies need to look competent and in control in front of clients.

That means:

- bounded access
- approvals
- audit trail
- explainable changes
- visible operational status

This matters more than flashy autonomous behavior.

### 2. Reusable workflow packaging

Agencies need repeatable things they can carry from one site to another:

- templates
- starter workers
- workflow guides
- bounded automation lanes

This is why workflow kits matter so much.

### 3. Multi-client operating reality

Agencies operate many sites, not one abstract installation.

That means the product should bias toward:

- clean boundaries
- repeatable setup
- low support burden
- strong defaults
- safe delegation

### 4. Stack extension matters

Agencies often standardize on a known Craft stack.

So extension into real plugin ecosystems matters, but only when it supports actual delivery work rather than abstract platform ambition.

## What This Means For The Roadmap

### Highest-fit themes

- bounded client automation
- reusable workflow starter kits
- extension into real Craft agency stacks
- operator assistance that improves agency delivery and support

### Lower-fit themes

- abstract extensibility with no visible delivery value
- autonomous operator control
- product directions that do not help agencies sell, govern, or operate client workflows

### Current priority logic

Best fits:

1. target-bound governed writes
2. workflow starter kits
3. adapter paths into agency-standard Craft stacks

Later fits:

4. constrained operator copilot support
5. fleet-operations assistance

Supporting infrastructure only:

6. extensibility hardening when needed by higher-value work

Explicitly parked:

7. stablecoin spend rail and similar non-core agency motions

## Decision Rules

When evaluating a feature, ask:

1. Does this help an agency sell or expand client work?
2. Does this help an agency operate client sites more safely?
3. Does this make automation more governable or easier to explain to a client?
4. Can this become a repeatable service, template, or workflow kit?
5. Does this lower support burden across multiple sites?
6. Does this preserve the trust boundary between agency operator, client, and machine actor?

If the answer is mostly no, the feature is probably secondary.

## Feature Review Checklist

Use this checklist during roadmap review, design review, and feature acceptance.

### Customer fit

- Is the primary operator an agency or delivery team rather than a self-serve end client?
- Is the client relationship model clear?
- Does the feature improve something agencies already sell, operate, or support?

### Product fit

- Does it strengthen bounded automation, repeatable workflows, stack extension, or safe operator assistance?
- Does it reinforce the product as the trust boundary between Craft and machine actors?
- Does it avoid pulling the product toward chatbot, prompt-tooling, or general orchestration territory?

### Commercial fit

- Can an agency explain this as a service, workflow, or operating improvement to a client?
- Can the feature be reused across more than one project without bespoke rebuilding?
- Does it help the agency look safer, more competent, or easier to work with?

### Operational fit

- Does it reduce risk, support burden, or ambiguity for teams running multiple sites?
- Are the boundaries, approvals, and audit expectations clear?
- Does it keep the machine actor visibly constrained?

### Decision

- If most answers are yes:
  - keep or promote
- If product value exists but agency fit is weak:
  - demote to enabling infrastructure or later work
- If the feature is interesting but does not materially help agencies deliver governed client automation:
  - park it

## Messaging Consequences

### Internal

Be explicit:

- agencies are the primary customer
- agencies are the primary operator
- client value is mediated through the agency relationship

### Public

Do not overstate the ICP literally.

Prefer product-facing language such as:

- agencies and delivery teams running Craft sites for clients
- governed automation for client websites
- reusable workflow patterns across sites
- safer automation inside approved boundaries

Avoid turning public docs into an internal strategy memo.

## What This Strategy Rejects

Do not drift toward:

- chatbot/plugin framing
- generic AI-for-content framing
- broad prompt tooling
- unconstrained agent autonomy
- features that are interesting but do not help agencies deliver governed client automation

## Relationship To Other Internal Docs

- `AGENTS_POSITIONING.md`
  - category, messaging, and external framing
- `AGENCY_FIRST_STRATEGY.md`
  - customer choice, roadmap lens, and prioritization logic

If these documents diverge, this strategy note should control customer/roadmap interpretation.
