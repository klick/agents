# Job Guides

Use these guides when you already understand the basic Agents bootstrap flow and want a bounded real-world job pattern to build from.

This area is the jobs starter-kit layer for agencies:

- bounded job patterns that can be reused across client sites
- companion guides and runnable reference patterns that make delivery repeatable
- governed automation that stays inside explicit client boundaries

These are job examples, not hosted product modules:

- they show how to combine scoped machine access, governed draft writes, and external runtime logic
- they do not promise autonomous publishing or turnkey domain-specific apps
- they keep humans in control where review quality matters

Managed jobs in Agents follow the same boundary:

- Agents can store job intent, managed-account bindings, handoff artifacts, and operator-facing visibility
- the actual schedule runner, fetch/reasoning loop, and execution still stay outside Agents for now
- runtime polling and lifecycle reporting flow through the job API contract instead of ad hoc run rows
- current job surfaces are there to make external runtime handoff clearer, not to replace the runtime

Depending on the job, the external runtime may be:

- one agent or orchestrator that fetches data directly
- one worker or script that prepares deterministic data first
- a split between deterministic prep and reasoning

## Available Guides

- [Governed Content Refresh](/workflows/governed-content-refresh)
- [Governed Entry Drafts](/workflows/governed-entry-drafts)
- [Entry Translation Drafts](/workflows/entry-translation-drafts)

## Start Here

If you have not run one runtime end to end yet, begin with:

- [First Worker](/get-started/first-worker)
- [External Runtimes](/get-started/external-runtimes)

Then come back to these job guides once the account, token, and worker bootstrap path is already clear.
