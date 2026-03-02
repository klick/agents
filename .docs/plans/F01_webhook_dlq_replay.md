# F01 Webhook DLQ + Replay

Date: 2026-03-02  
Branch: `feature/f01-webhook-dlq-replay`

## Goal

Failed webhook deliveries should be captured in a dead-letter queue and replayable from both CP and API.

## Dependency Graph

```mermaid
graph TD
  T1[T1 Add webhook DLQ persistence table]
  T2[T2 Capture terminal webhook failures into DLQ]
  T3[T3 Add WebhookService DLQ list/replay operations]
  T4[T4 Expose API list/replay endpoints + scopes/docs]
  T5[T5 Add CP replay controls in Security tab]
  T6[T6 Validate via lint + release gate]

  T1 --> T2
  T1 --> T3
  T2 --> T3
  T3 --> T4
  T3 --> T5
  T4 --> T6
  T5 --> T6
```

## Tasks

- `T1` `depends_on: []`
  - Add migration for `agents_webhook_dlq` table and indexes.

- `T2` `depends_on: [T1]`
  - On terminal webhook failure (final retry), store payload + error in DLQ.

- `T3` `depends_on: [T1, T2]`
  - Implement list/replay methods in `WebhookService`.

- `T4` `depends_on: [T3]`
  - Add guarded API endpoints for DLQ list/replay.
  - Add scopes to capabilities/OpenAPI metadata.

- `T5` `depends_on: [T3]`
  - Add Security tab DLQ view and replay actions.

- `T6` `depends_on: [T4, T5]`
  - Run `php -l` on changed PHP files.
  - Run `scripts/qa/release-gate.sh`.
