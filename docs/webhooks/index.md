# Webhooks

Agents can emit change events for entries/products/orders.

## Enablement

Set both:

- `PLUGIN_AGENTS_WEBHOOK_URL`
- `PLUGIN_AGENTS_WEBHOOK_SECRET`

Optional tuning:

- `PLUGIN_AGENTS_WEBHOOK_TIMEOUT_SECONDS` (default `5`, max `60`)
- `PLUGIN_AGENTS_WEBHOOK_MAX_ATTEMPTS` (default `3`, max `10`)

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
