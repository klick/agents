# Activation Funnel

Status: canonical internal growth and onboarding note  
Date: 2026-03-19

See also:

- `AGENCY_FIRST_STRATEGY.md` for the customer and roadmap lens
- `AGENTS_POSITIONING.md` for product/category messaging

## Why This Exists

Install count is not the same as product traction.

For Agents, the useful question is not only:

- how many sites installed the plugin?

It is:

- how many operators reached a first real machine connection?
- how many reached a first meaningful workflow?
- how many kept using the product after the first setup moment?

This note defines the activation funnel to use for product decisions, onboarding work, and early growth interpretation.

## Core Rule

Treat these as different signals:

1. interest
2. setup progress
3. activation
4. depth of use
5. retention

Do not collapse them into one number.

## Funnel Definition

### Stage 1: Install

Definition:

- the plugin is installed on a Craft site

What it means:

- interest exists
- the plugin-store listing, docs, or word of mouth created enough trust to try it

What it does **not** mean:

- the operator understands the product
- the site is configured
- the product has delivered value yet

Primary question:

- are people willing to try Agents?

### Stage 2: First operator session

Definition:

- a human operator opens the plugin and reaches the control plane

Useful examples:

- `Status` opened
- `Accounts` opened
- first-run `Start` screen opened once it exists

What it means:

- the install has turned into active evaluation

Primary question:

- are new installs actually exploring the product?

### Stage 3: First account created

Definition:

- at least one managed account is created

What it means:

- the operator understood enough of the product to create a machine actor
- the product passed the first setup comprehension test

What it does **not** mean:

- the operator has connected anything successfully
- the runtime has proven useful yet

Primary question:

- can an operator get through setup far enough to create the first machine identity?

### Stage 4: First authenticated machine request

Definition:

- the site records the first successful authenticated machine request from a managed account or valid runtime credential

Examples:

- `/auth/whoami`
- a successful read endpoint request
- a successful sync-state request

This is the **primary activation event**.

Why:

- it proves the operator crossed the hardest early boundary:
  - account created
  - token copied or exported
  - worker connected
  - authentication working
- it is the clearest sign that the product is now real, not just installed

Primary question:

- did the operator get Agents connected to something real?

### Stage 5: First meaningful workflow event

Definition:

- the connected runtime performs a meaningful product action beyond a proof ping

Examples:

- first recurring authenticated use
- first approval request
- first governed draft request
- first sync-state checkpoint in a real integration
- first webhook delivery used in a real workflow

What it means:

- the product moved from connection to use

This is the **depth-of-use threshold**.

Primary question:

- did the operator find a workflow worth using?

### Stage 6: Retained use

Definition:

- the site shows repeated meaningful machine use after the initial setup window

Suggested early retention checks:

- activity on day 7
- activity in week 2
- activity in week 4

Examples:

- repeated authenticated requests
- repeated workflow runs
- repeated approvals or governed actions
- continued sync-state or webhook use

What it means:

- the product is becoming part of an operating pattern, not just a trial

Primary question:

- did Agents become part of ongoing site operations?

## Funnel Summary

The internal funnel should be read as:

1. install
2. first operator session
3. first account created
4. first authenticated machine request
5. first meaningful workflow event
6. retained use

## The Most Important Metric

For now, the single most important early-product metric is:

- **first authenticated machine request per install**

Reason:

- it is the clearest marker that onboarding worked
- it is earlier and more frequent than governed-write usage
- it is stronger than account creation alone
- it is the best current proxy for “the product clicked"

## Secondary Metrics

Track these next to the primary activation event:

- install -> first operator session rate
- first operator session -> first account created rate
- first account created -> first authenticated machine request rate
- first authenticated machine request -> first meaningful workflow event rate
- first authenticated machine request -> retained day-7 use rate

These stage-to-stage conversions matter more than raw totals.

## Agency-First Interpretation

Because the ICP is agencies running client sites, activation should be interpreted through operator usefulness, not casual curiosity.

That means:

- a site install without a first account is weak signal
- a first account without first auth is partial setup progress
- first auth is the first real “this can work on a client site” moment
- retained use is the first strong sign of agency workflow fit

For this product, agencies do not win from theoretical capability.
They win when setup becomes operational quickly and repeatedly.

## What To Instrument

Prefer a small event set first.

### Core events

- `plugin_installed`
- `cp_first_opened`
- `managed_account_created`
- `managed_token_revealed`
- `managed_env_downloaded`
- `first_authenticated_request_succeeded`
- `first_meaningful_workflow_event`
- `retained_use_day_7`

### Good supporting dimensions

- site id / installation id
- plugin version
- Craft version
- Commerce enabled or not
- first account template chosen
- write-capable account or read-only account
- webhook enabled or not

Do not start with heavy analytics complexity.
Start with enough to explain drop-off.

## How To Read Drop-Off

### Installs high, first operator sessions low

Likely problems:

- weak post-install guidance
- operators do not know where to start
- the plugin entrypoint feels too heavy or too operational

### First sessions high, account creation low

Likely problems:

- onboarding language is too dense
- scopes and concepts appear too early
- the product feels more complicated than the value justifies

### Account creation high, first auth low

Likely problems:

- worker connection is too hard
- token handoff is unclear
- docs are too abstract
- the operator does not know the next step after account creation

### First auth high, meaningful workflow low

Likely problems:

- the product is connectable but not yet compelling
- the first workflow story is weak
- starter examples and workflow kits are not strong enough

### Meaningful workflow high, retention low

Likely problems:

- value is episodic, not operational
- the workflow is too fragile or high-friction
- agencies do not yet see repeatable service value

## Product Consequences

This funnel should shape near-term product work.

### Onboarding

Optimize first for:

- first account created
- first authenticated machine request

Not for:

- exposing the whole platform model on first visit

### Status

`Status` should primarily help new operators answer:

- am I ready to connect?
- what is the next step?
- what is blocking me right now?

Not force them to interpret a full readiness console before first use.

### Accounts

`Accounts` should primarily help new operators answer:

- what kind of machine actor do I need?
- how do I create it?
- how do I connect it?

Not lead with raw scope complexity.

### Workflow kits

Starter workflows matter because they improve the conversion from:

- first authenticated request
- first meaningful workflow event

That is one of the strongest current strategic levers.

## What Not To Over-Index On Yet

Do not over-read:

- raw install count alone
- pageview-style vanity metrics
- advanced operational feature usage before activation is healthy

At this stage, installs mean interest.
Activation means the product actually connected.
Retention means the product earned a place in ongoing work.

## Current Working Definition Of Success

Short term:

- more installs turn into first operator sessions
- more first sessions turn into first accounts
- more first accounts turn into first authenticated requests

Medium term:

- more connected sites reach a real workflow
- more of those sites still show meaningful use after 7 days

This is the funnel that should be used when evaluating onboarding, mockups, first-run UX, starter workflows, and product clarity.
