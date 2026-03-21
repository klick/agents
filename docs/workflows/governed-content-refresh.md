# Governed Content Refresh

Use this workflow when you want an operator-copy/paste path for a scheduled worker that:

- reads entry data from Craft
- derives draft content from that data
- submits a governed `entry.updateDraft` approval request
- lets the Craft control panel create the saved draft on final approval

This is the most practical first agency-style write workflow because it keeps the system simple:

- one managed account
- one cron-driven worker
- one reasoning layer you can replace later
- one approval surface in Craft

## What This Workflow Covers

1. create a write-capable managed account
2. configure a worker `.env`
3. schedule the worker with cron
4. read entry data from Craft
5. generate a draft proposal
6. submit the approval request with the full draft payload
7. let the final CP approval execute that stored payload

## Required Account Shape

Create a managed account in `Agents -> Accounts` with:

- Name: `Governed Content Refresh`
- Description: `Scheduled worker that reads entry data and requests governed refresh drafts.`

Recommended scopes:

- `entries:read`
- `sections:read`
- `entries:write:draft`
- `control:approvals:request`

Useful optional scopes:

- `entries:read_all_statuses`
- `auth:read`

Use `Target Sets` when you want the account limited to explicit entries and sites.

## Why This Version Uses One Request

The approval request stores the payload Craft will later execute.

That matters because the Craft control panel already auto-executes a finally approved request when the approver has execute permission. So the operator-friendly path is:

1. the worker submits the full draft payload
2. humans approve it
3. the final approval creates the saved draft

That avoids a second worker phase unless you explicitly want one.

## Example Worker

Copy/paste-ready example path:

- `examples/workers/governed-content-refresh/`

Bootstrap:

```bash
cd examples/workers/governed-content-refresh
cp .env.example .env
```

Run once:

```bash
./run-worker.sh
```

Dry run only:

```bash
DRY_RUN=1 ./run-worker.sh
```

Cron:

```txt
15 8 * * 1 /bin/bash /absolute/path/to/examples/workers/governed-content-refresh/run-worker.sh >> /absolute/path/to/examples/workers/governed-content-refresh/worker.log 2>&1
```

## What The Worker Actually Does

The example worker:

- calls `GET /entries/show?id=ENTRY_ID`
- builds a draft proposal from the returned entry metadata
- sends `POST /control/approvals/request` with a full `entry.updateDraft` payload

The local reasoning stub is intentionally simple and replaceable.

Keep the split like this:

- worker: auth, scheduling, idempotency, payload transport
- agent/reasoning step: decide the proposed draft values

## Current Constraint

The current entry read API returns entry metadata like:

- `id`
- `title`
- `slug`
- `uri`
- `section`
- `updatedAt`

It does **not** currently expose arbitrary custom entry field bodies through `/entries/show`.

So this workflow is strongest today for:

- title refresh proposals
- summary/excerpt generation from known metadata plus operator context
- bounded automation proofs

If you need richer content reasoning, extend the input set with other read surfaces or add the exact adapter/data path your worker needs.

## When To Use A Different Pattern

Use [Governed Entry Drafts](/workflows/governed-entry-drafts) instead when:

- the worker itself should execute the approved action later
- you want a separate `request` and `execute-approved` phase
- you already have the full draft payload outside the approval request lifecycle

## Related

- [Governed Entry Drafts](/workflows/governed-entry-drafts)
- [First Worker](/get-started/first-worker)
