# Governed Entry Drafts

Use this job when you want to test or build the real governed draft-write path against an entry.

The public example uses a worker, but the same governed flow can also be driven by another external runtime if it can manage the same request and execute-approved phases safely.

This is the simplest write-side job in Agents:

- request approval for `entry.updateDraft`
- approve it in the control panel
- execute the approved action
- review the saved draft on the target entry

It is a good fit for:

- end-to-end draft-write testing
- editorial automation proofs of concept
- bounded agent workflows that should never publish directly

## What It Does

- creates a governed approval request for `entry.updateDraft`
- waits for human approval in `Agents -> Approvals`
- executes the approved draft write
- creates or updates a saved draft on the target entry/site

## What It Does Not Do

- publish automatically
- bypass approvals
- ignore saved-draft conflicts
- replace editorial review

## Required Scopes

For the worker example:

- `control:approvals:request`
- `control:actions:execute`
- `entries:write:draft`

Optional but useful:

- `auth:read`

The write endpoints also require `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`.

## Example Worker

Public example path:

- `examples/workers/entry-draft-governed/`

The worker supports two modes:

1. `request`
- creates the approval request

2. `execute-approved`
- executes the draft action after a human approved the request

If your agent or orchestrator already handles scheduling, state, and tool calls cleanly, it can drive the same flow directly. The worker example stays useful because it makes the approval boundary, idempotency, and execute-approved phase explicit.

## Suggested Flow

1. Create a managed account with the required scopes.
   Optional but recommended: create one or more reusable boundaries from `Agents -> Accounts` via `Manage boundaries`, then assign them to the account so it can only request draft updates for explicit entries/sites.
2. Configure the worker `.env` with:
   - `ENTRY_ID`
   - `SITE_ID`
   - optional `TARGET_SET_HANDLE` / bounded entry/site hints from the account helper
   - optional title/slug/fields changes
3. Run the worker in `request` mode.
4. Approve the request in `Agents -> Approvals`.
5. Run the worker again in `execute-approved` mode with the returned `APPROVAL_ID`.
6. Review the saved draft on the entry.

## Important Guardrails

- use the correct `siteId` for the destination site/language
- for boundary-constrained accounts, `payload.siteId` is required and must stay inside the assigned boundary
- keep the job approval-driven; do not auto-apply the draft
- if another saved draft already exists, either resolve it editorially or target the exact `DRAFT_ID`
- treat `Apply Draft` as a separate human decision

## Why This Fits Agents

This job exercises the exact control-plane boundary Agents is designed for:

- managed machine identity
- explicit write scopes
- approval routing before execution
- auditable draft creation instead of direct publishing

That makes it a strong first write-side test before you build more specialized workers like translation, content refresh, or campaign drafting.

## Related

- [Governed Content Refresh](/workflows/governed-content-refresh)
- [First Worker](/get-started/first-worker)
- [Entry Translation Drafts](/workflows/entry-translation-drafts)
- [Starter Packs](/api/starter-packs)
