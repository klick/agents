---
title: CLI Commands
---

# CLI Commands

Craft commands:

- `craft agents/product-list`
- `craft agents/order-list`
- `craft agents/order-show`
- `craft agents/entry-list`
- `craft agents/entry-show`
- `craft agents/section-list`
- `craft agents/discovery-prewarm`

## Examples

```bash
php craft agents/product-list --status=live --limit=10
php craft agents/product-list --status=all --search=emboss --limit=5 --json=1
php craft agents/product-list --low-stock=1 --low-stock-threshold=10 --limit=25

php craft agents/order-list --status=shipped --last-days=14 --limit=20
php craft agents/order-show --number=A1B2C3D4
php craft agents/order-show --resource-id=12345

php craft agents/entry-list --section=termsConditionsB2b --status=live --limit=20
php craft agents/entry-show --slug=shipping-information
php craft agents/entry-show --resource-id=123

php craft agents/section-list

php craft agents/discovery-prewarm
php craft agents/discovery-prewarm --target=llms --json=1
```

Notes:

- CLI output is human-readable by default.
- Add `--json=1` for machine consumption.
- Show commands require exactly one identifier parameter.

