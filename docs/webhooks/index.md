# Webhooks

Agents can emit change events for entries/products/orders.

## Enablement

Set both:

- `PLUGIN_AGENTS_WEBHOOK_URL`
- `PLUGIN_AGENTS_WEBHOOK_SECRET`

Optional tuning:

- `PLUGIN_AGENTS_WEBHOOK_TIMEOUT_SECONDS` (default `5`, max `60`)
- `PLUGIN_AGENTS_WEBHOOK_MAX_ATTEMPTS` (default `3`, max `10`)

These can also be referenced from `Agents -> Settings -> Webhooks` using env-aware fields. Prefer storing real values in the environment and keeping the Settings fields as `$PLUGIN_AGENTS_*` references.

## Production probe

For a production-safe transport check against the live receiver:

- use `Agents -> Status -> Webhook Probe`
- the action is admin-only
- it sends a synthetic signed webhook through the current runtime webhook URL and secret
- it does not require saving real content
- it does not require temporarily pointing delivery at a dev sink
- a five-minute cooldown prevents repeated probe spam against the receiver

Probe payloads are explicitly marked so receivers can ignore or separately log them:

- `eventKind: probe`
- `isProbe: true`
- `probeId`
- `triggeredAt`
- `triggeredBy`

The Status card keeps a small probe ledger with:

- recent attempts
- delivered vs failed counts
- last success / failure timestamps
- payload inspection for the synthetic event

What a successful probe proves:

- the current webhook URL is reachable
- the signing secret matches
- outbound HTTP delivery succeeds right now

What it does not prove:

- a real content change hook fired
- the normal queued business-event path was exercised
- downstream business logic reacts to real events the same way

## Dev-only test sink

For local/dev inspection without a real external receiver:

- set `PLUGIN_AGENTS_WEBHOOK_TEST_SINK=true`
- keep Craft `devMode` enabled
- `PLUGIN_AGENTS_WEBHOOK_URL` and `PLUGIN_AGENTS_WEBHOOK_SECRET` remain the normal runtime webhook settings
- only point `PLUGIN_AGENTS_WEBHOOK_URL` at the sink URL shown in `Agents -> Status` in local/dev
- keep `PLUGIN_AGENTS_WEBHOOK_SECRET` configured so signatures can be verified

The sink is a local capture endpoint:

- route: `/agents/dev/webhook-test-sink`
- it stores recent deliveries for inspection in `Agents -> Status`
- it is intentionally unavailable outside explicit dev-mode opt-in
- `Send test webhook` issues a one-click local delivery through the real signing + HTTP delivery path
- do not route production webhook delivery to the local sink

## Manual CP check

For a quick operator/dev verification pass:

1. set `PLUGIN_AGENTS_WEBHOOK_TEST_SINK=true`
2. keep Craft `devMode` enabled
3. temporarily point the normal runtime `PLUGIN_AGENTS_WEBHOOK_URL` at the sink URL shown in `Agents -> Status`
4. confirm `Webhook Test Sink` appears in `Agents -> Status`
5. click `Send test webhook` for a one-click delivery check, or save an entry/product/order and run `php craft queue/run` for a real content-triggered event
6. confirm the sink section shows:
   - captured count
   - valid count
   - last captured timestamp
   - routing mode / matched credential handles
   - payload preview
7. use `Clear captures` to reset the local inspection history

Automated QA scenarios are available in:

- `scripts/qa/webhook-test-sink-smoke.sh`
- `scripts/qa/webhook-test-sink-e2e.sh`

## Delivery model

- webhook jobs are queued
- retries are bounded by max attempts
- non-2xx responses are retried
- exhausted events are persisted to dead-letter queue storage for replay

## Subscription targeting (per agent)

Managed accounts can include optional event-interest subscriptions:

- resource types: `entry`, `product`, `order`
- actions: `created`, `updated`, `deleted`

Behavior:

- if no accounts define subscriptions, webhook delivery runs in firehose mode
- if any account defines subscriptions, delivery switches to targeted mode and only matching events are sent
- subscriptions are managed in `Agents -> Accounts`

## Signature headers

Outgoing requests include:

- `X-Agents-Webhook-Id`
- `X-Agents-Webhook-Timestamp`
- `X-Agents-Webhook-Signature`

Signature format:

- `sha256=<hmac>`
- HMAC payload: `<timestamp>.<rawJsonBody>`
- HMAC algorithm: SHA-256
- key: `PLUGIN_AGENTS_WEBHOOK_SECRET`

## Event payload shape

Typical fields:

- `id`
- `occurredAt`
- `resourceType` (`entry|product|order`)
- `resourceId`
- `action` (`created|updated|deleted`)
- `updatedAt`
- `snapshot` (null on delete tombstones)

## Dead-letter queue (DLQ) + replay

Guarded endpoints:

- `GET /agents/v1/webhooks/dlq` (scope: `webhooks:dlq:read`)
- `POST /agents/v1/webhooks/dlq/replay` (scope: `webhooks:dlq:replay`)

Replay modes:

- replay a single event by `id`
- replay a batch with `mode=all` (+ optional `limit`)
