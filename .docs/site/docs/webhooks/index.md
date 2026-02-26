---
title: Webhooks
---

# Webhooks

Webhook delivery is optional and activates only when both are configured:

- `PLUGIN_AGENTS_WEBHOOK_URL`
- `PLUGIN_AGENTS_WEBHOOK_SECRET`

## Event behavior

- async queue delivery for `product|order|entry` create/update/delete
- payload shape aligns with `/changes` feed items
- retries up to `PLUGIN_AGENTS_WEBHOOK_MAX_ATTEMPTS`
- variant changes are emitted as product updates

## Request headers

- `X-Agents-Webhook-Id`
- `X-Agents-Webhook-Timestamp`
- `X-Agents-Webhook-Signature` (`sha256=<hex-hmac>`)

## Signature verification

- signed input: `<timestamp>.<raw-body>`
- algorithm: HMAC-SHA256
- secret: `PLUGIN_AGENTS_WEBHOOK_SECRET`

## Queue requirement

Ensure queue workers are running in environments where webhook delivery is expected:

```bash
php craft queue/run
# or
php craft queue/listen
```

