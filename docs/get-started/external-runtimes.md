# External Runtimes

Agents issues managed accounts to **external runtimes**.

That is the canonical umbrella term for the thing outside Craft that uses a managed account token.

Typical external runtimes include:

- agents
- orchestrators
- workers
- scripts

## Why Use An Umbrella Term

Not every integration looks the same.

Some teams run:

- one cron-driven worker
- one agent runtime that fetches data and reasons directly
- one orchestrator that coordinates tools and submits approvals
- a hybrid of deterministic scripts plus an LLM step

Agents should describe all of those cleanly without pretending they are identical.

So the product language is:

- `Agents` = the product and trust boundary
- `managed account` = the machine identity
- `external runtime` = the thing using that identity

## Trust Rule

Use this as the calm mental model:

- operators set the boundary
- external runtimes work inside it
- Agents enforces the rules

That means operators decide:

- scopes
- optional approvals
- write boundaries
- who receives operational visibility

And the external runtime does the execution work inside those limits.

## When A Worker Is Helpful

A worker is just one kind of external runtime.

It is often the best pattern when you want:

- deterministic fetch and preparation
- scheduled cron-style execution
- retries and backoff without LLM token spend
- repeatable input datasets for audits or review

That is why the docs still include worker-first examples.

## When A Direct Agent Orchestrator Is Enough

A separate worker is not mandatory.

A direct agent or orchestrator can use the managed account token itself when:

- the workflow is simple
- the data inputs are bounded
- the runtime already handles scheduling and tool calls well
- reproducibility pressure is lower

In that model, the agent or orchestrator can:

- fetch data from Agents
- reason over it
- call other tools
- submit governed approval requests

## What Agents Does Not Do

Agents does not run the external runtime for you.

It does not:

- execute model reasoning
- orchestrate external tool calls
- run arbitrary shell commands as production behavior

Instead, it provides:

- managed accounts
- scoped API access
- approvals for sensitive actions
- diagnostics and runtime visibility
- a governed boundary between Craft and the external runtime

## Rule Of Thumb

If you need the simplest first bootstrap:

- start with a worker

If you already have a disciplined orchestrator or agent runtime:

- it can use the managed account directly

Both patterns fit the product.

## See Also

- [First Worker](/get-started/first-worker)
- [Execution Model](/security/execution-model)
- [Job Guides](/workflows/)
