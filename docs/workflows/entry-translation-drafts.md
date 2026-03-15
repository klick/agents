# Entry Translation Drafts

Use this workflow when you want a worker to prepare localized entry drafts for review without publishing automatically.

This is a good fit for:

- marketing pages
- campaign landing pages
- summaries and CTA text
- bounded localization work where editors still want final control

This is not a full translation-management system. It prepares drafts for review.

## What It Does

- reads approved source entries
- translates selected fields externally
- creates or updates a draft in the target site/language
- routes the change through the normal approval flow
- leaves review and apply to humans

## What It Does Not Do

- publish automatically
- guarantee translation quality
- replace editorial review
- manage the full localization lifecycle across every content type

## Recommended Account Template

Use the `Entry Translation Drafts` account template in `Agents -> Accounts`.

Suggested scopes:

- `entries:read`
- `entries:read_all_statuses`
- `sections:read`
- `entries:write:draft`
- `control:approvals:request`
- `control:approvals:read`
- `capabilities:read`
- `openapi:read`

## Example Use Case

`Marketing Localization Drafts`

Example:

- source site/language: `en`
- target site/language: `de`
- section: `campaignPages`
- fields:
  - `title`
  - `summary`
  - `body`
  - `ctaLabel`

The worker reads the approved English entry, prepares German copy for the selected fields, and creates a governed German draft for editorial review.

## Suggested Flow

1. Create the `Entry Translation Drafts` account.
2. Operationally scope the worker to one source language, one target language, and one section first.
   - Create a dedicated account for that translation lane, for example `translation-en-de-campaign-pages`.
   - Configure the worker with fixed source and target site/language values.
   - Keep the worker limited to one section handle and a small field allowlist.
3. Read the source entry and collect the selected translatable fields.
4. Translate those fields in the external worker.
5. Submit a governed `entry.updateDraft` request with:
   - `entryId`
   - target `siteId`
   - translated field values
6. Let the approval flow decide whether the draft may be created.
7. Have an editor review and apply the target-language draft.

Today, that scope is enforced operationally by the dedicated account and worker configuration, not by a hard account-level section or language restriction inside Agents.

## Important Guardrails

- always target the correct `siteId` for the destination language
- do not auto-apply translated drafts
- stop on existing saved-draft conflicts and let editors resolve them
- start with a small allowlist of fields instead of trying to translate arbitrary field payloads

## Why This Fits Agents

Agents already provides the useful boundary for this pattern:

- machine identity through managed accounts
- explicit read and draft-write scopes
- governed approval routing
- draft creation without direct publishing

That makes translation-draft preparation a strong example of governed machine assistance rather than autonomous publishing.

## Positioning

Safe product framing:

- `Prepare localized drafts with approval`
- `Create target-language entry drafts for editorial review`

Avoid overclaiming:

- not `automatic multilingual publishing`
- not `full localization platform`
- not `perfect translation out of the box`

## Related

- [First Worker](/get-started/first-worker)
- [Dashboard & CP](/cp/)
- [Starter Packs](/api/starter-packs)
