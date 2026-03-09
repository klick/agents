# CLI

Craft-native commands are available under `craft agents/*`.

## Commands

- `php craft agents/product-list`
- `php craft agents/order-list`
- `php craft agents/order-show`
- `php craft agents/entry-list`
- `php craft agents/entry-show`
- `php craft agents/section-list`
- `php craft agents/discovery-prewarm`
- `php craft agents/auth-check`
- `php craft agents/discovery-check`
- `php craft agents/readiness-check`
- `php craft agents/reliability-check`
- `php craft agents/lifecycle-report`
- `php craft agents/starter-packs`
- `php craft agents/diagnostics-bundle`
- `php craft agents/smoke`

## Output modes

- default: human-readable text
- machine mode: add `--json=1`

## Examples

```bash
php craft agents/product-list --status=live --limit=10
php craft agents/order-list --status=shipped --last-days=14 --limit=20
php craft agents/order-show --number=A1B2C3D4
php craft agents/entry-list --section=news --status=live --limit=20
php craft agents/entry-show --slug=shipping-information
php craft agents/discovery-prewarm --target=all
php craft agents/auth-check --json=1
php craft agents/discovery-check --json=1
php craft agents/readiness-check --json=1
php craft agents/reliability-check --json=1
php craft agents/lifecycle-report --json=1
php craft agents/lifecycle-report --strict=1 --json=1
php craft agents/starter-packs --json=1
php craft agents/starter-packs --template-id=catalog-sync-loop --json=1
php craft agents/diagnostics-bundle --json=1
php craft agents/smoke --json=1
```

## Identifier rules

- `order-show`: use exactly one of `--number` or `--resource-id`
- `entry-show`: use exactly one of `--slug` or `--resource-id`
