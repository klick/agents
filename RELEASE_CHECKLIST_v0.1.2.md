# v0.1.2 Fast Response Checklist

Use this checklist if Craft review requests changes after submission.

## 1. Intake

- Copy reviewer feedback verbatim into an issue.
- Classify each point: metadata, docs, code behavior, security, UX, assets.
- Confirm scope for `v0.1.2` only (no feature expansion).

## 2. Metadata + Listing

- Verify `composer.json` still has:
  - `name`, `type`, `version`, `license`
  - `extra.name`, `extra.handle`, `extra.class`
  - `support.docs`, `support.issues`, `support.source`
- Confirm Plugin Store fields match README wording (no conflicting claims).
- Confirm icon/screenshot assets are current.

## 3. Documentation

- Update `README.md` for reviewer-requested clarifications.
- Append `CHANGELOG.md` entry for `0.1.2` with concrete bullet points.
- Keep install instructions accurate for publication state.

## 4. Code + Safety

- Apply minimal patch only for requested behavior.
- Keep API/CLI read-only contract intact.
- Re-run PHP lint for all plugin PHP files.
- Verify critical endpoints:
  - `/agents/v1/health`
  - `/agents/v1/capabilities`
  - `/agents/v1/openapi.json`
- Verify CLI discovery command works:
  - `php craft help agents/product-list`

## 5. Release Steps

- Commit with focused message.
- Tag release:
  - `git tag -a v0.1.2 -m "Release v0.1.2"`
- Push:
  - `git push gh main`
  - `git push gh v0.1.2`

## 6. Resubmission

- Reply to Craft review with change summary mapped to each feedback point.
- Link commit/tag and updated docs.
- Keep response concise and factual.
