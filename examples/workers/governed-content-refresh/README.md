# Governed Content Refresh Worker

This example is the operator-ready version of a scheduled write workflow:

- a managed account reads entry data from Craft
- a local reasoning step prepares draft content
- the worker submits a governed `entry.updateDraft` approval request
- a human approves it in `Agents -> Approvals`
- the final approval executes the stored draft payload and creates the saved draft

Use it when you want one copy/paste-friendly baseline for:

- a cron-driven worker
- a bounded write-capable account
- a replaceable reasoning layer
- governed draft creation without direct publishing

## What This Example Assumes

- `PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true`
- the final approver in the Craft control panel has permission to execute governed actions
- the managed account is allowed to request `entry.updateDraft`
- if target sets are assigned, the chosen `ENTRY_ID` and `SITE_ID` stay inside those boundaries

## 1. Create the managed account

In `Agents -> Accounts`, create a managed account like:

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
- `capabilities:read`

Important:

- this worker does **not** need `control:actions:execute` when the CP approver is expected to auto-execute the approved request
- if you want the worker itself to execute after approval, use the separate `entry-draft-governed` example instead

## 2. Bootstrap the worker

```bash
cd examples/workers/governed-content-refresh
cp .env.example .env
```

Set at minimum:

- `SITE_URL` or `BASE_URL`
- `AGENTS_TOKEN`
- `ENTRY_ID`
- `SITE_ID`

Recommended first run values:

```env
TITLE_SUFFIX= [Review]
SUMMARY_FIELD_HANDLE=summary
PROMPT_CONTEXT=Highlight stale claims, tighten wording, and keep the tone factual.
```

If your entry does not have a `summary` field, either:

- change `SUMMARY_FIELD_HANDLE` to a real field handle on that entry type
- or leave `SUMMARY_FIELD_HANDLE` empty and drive the proposal through `TITLE_PREFIX`, `TITLE_SUFFIX`, and `FIELDS_JSON`

## 3. Run once

```bash
cd examples/workers/governed-content-refresh
./run-worker.sh
```

What the worker does:

1. reads the entry from `GET /entries/show?id=ENTRY_ID`
2. derives a draft proposal from the entry metadata
3. submits a full `POST /control/approvals/request` payload

Because the approval request already contains the full draft payload, the CP can execute it on final approval without a second worker step.

## 4. Cron it

Example cron entry:

```txt
15 8 * * 1 /bin/bash /absolute/path/to/examples/workers/governed-content-refresh/run-worker.sh >> /absolute/path/to/examples/workers/governed-content-refresh/worker.log 2>&1
```

Keep these stable across repeated runs:

- `ACTION_REF`
- `REQUEST_IDEMPOTENCY_KEY`

Change them only when the workflow semantics should create a new approval request.

## 5. Replace the reasoning stub

The worker already contains a runnable local proposal function:

- it reads entry metadata
- it derives a draft title
- it optionally writes one field like `summary`
- it writes draft notes explaining why the change was proposed

That logic lives in:

- `buildDraftProposal()`
- `buildSummaryValue()`

Replace those functions with your real agent/model call when ready.

Pragmatic rule:

- keep the worker responsible for transport, auth, idempotency, and bounded payload shape
- keep the reasoning layer responsible only for deciding the proposed draft values

## Copy/Paste Request Shape

This is what the worker ultimately sends to Craft:

```json
{
  "actionType": "entry.updateDraft",
  "actionRef": "CONTENT-REFRESH-1001-1",
  "reason": "Prepare scheduled content refresh draft for editorial review",
  "idempotencyKey": "content-refresh-1001-1",
  "metadata": {
    "source": "cron-worker",
    "agentId": "governed-content-refresh",
    "traceId": "content-refresh-1001-1"
  },
  "payload": {
    "entryId": 1001,
    "siteId": 1,
    "draftName": "Scheduled content refresh draft",
    "draftNotes": "Prepared by scheduled governed automation for editorial review.",
    "title": "Original Title [Review]",
    "fields": {
      "summary": "Draft summary prepared by scheduled automation: Original Title is being refreshed for editorial review."
    }
  }
}
```

## Dry Run

If you want to inspect the exact request without creating a real approval:

```bash
DRY_RUN=1 ./run-worker.sh
```

## Related

- `examples/workers/entry-draft-governed/`
- [Governed Entry Drafts](/workflows/governed-entry-drafts)
- [First Worker](/get-started/first-worker)
