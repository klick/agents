# Governed Entry Draft Worker

This example creates a governed `entry.updateDraft` request, waits for a human approval in the Craft control panel, and then executes the approved draft action.

Use it when you want to test the real write path end to end:

- managed account
- governed approval request
- human approval in `Agents -> Approvals`
- draft creation or draft update on the target entry

## Requirements

Before you run it:

- enable `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`
- create a managed account with these scopes:
  - `control:approvals:request`
  - `control:actions:execute`
  - `entries:write:draft`
- optional but useful:
  - `auth:read`

## Configure

```bash
cp .env.example .env
```

Set at minimum:

- `SITE_URL` or `BASE_URL`
- `AGENTS_TOKEN`
- `ENTRY_ID`
- `SITE_ID`

The example also accepts:

- `DRAFT_ID` if you want to target an existing saved draft
- `TITLE`
- `SLUG`
- `FIELDS_JSON`
- `DRAFT_NAME`
- `DRAFT_NOTES`

`FIELDS_JSON` must be a JSON object.

Example:

```env
FIELDS_JSON='{"summary":"Short summary prepared by the worker example."}'
```

## Step 1: Request approval

```bash
MODE=request ./run-worker.sh
```

The worker calls:

- `POST /control/approvals/request`

It prints the request number you need for the next steps.

## Step 2: Approve it in Craft

In the control panel:

- open `Agents -> Approvals`
- approve the request

If your approval policy requires two approvals, complete both approvals first.

## Step 3: Execute the approved draft action

```bash
MODE=execute-approved APPROVAL_ID=123 EXECUTE_IDEMPOTENCY_KEY=approval-123-exec-v1 ./run-worker.sh
```

The worker calls:

- `POST /control/actions/execute`

On success it prints:

- execution id
- execution status
- created draft entry id
- saved draft record id
- whether the draft was newly created

## What To Expect

Happy path:

- request is created as `pending`
- operator approves it in the CP
- execution returns `succeeded`
- a saved draft now exists on the target entry/site

Conflict path:

- if the entry already has another saved draft and you did not provide `DRAFT_ID`, execution is blocked
- the result payload tells you to target the exact saved draft via `payload.draftId`

## Schedule

This example is mainly for end-to-end validation, but you can still run it from cron if you keep the environment and idempotency keys explicit.

Request example:

```txt
0 9 * * 1 /bin/bash /absolute/path/to/examples/workers/entry-draft-governed/run-worker.sh >> /absolute/path/to/examples/workers/entry-draft-governed/worker.log 2>&1
```

For repeated scheduled runs, generate deterministic `ACTION_REF`, `REQUEST_IDEMPOTENCY_KEY`, and `EXECUTE_IDEMPOTENCY_KEY` values that match your workflow semantics.
