# F10 Versioned Schema Endpoint

Date: 2026-03-02  
Branch: `feature/f10-versioned-schema-endpoint`

## Goal

Publish machine-readable endpoint schemas by API version for safer client generation and integration validation.

## Dependency Graph

```mermaid
graph TD
  T1[T1 Add schema endpoint route + API action]
  T2[T2 Implement versioned schema catalog + endpoint selector]
  T3[T3 Add schema scope + capabilities/OpenAPI metadata]
  T4[T4 Include schema endpoint in CP endpoint lists]
  T5[T5 Validate schema response stability and error handling]
  T6[T6 Validate via lint + release gate]

  T1 --> T2
  T2 --> T3
  T3 --> T4
  T2 --> T5
  T4 --> T6
  T5 --> T6
```

## Tasks

- `T1` `depends_on: []`
  - Add route/action for `GET /agents/v1/schema`.

- `T2` `depends_on: [T1]`
  - Build versioned schema catalog and allow optional endpoint selection (`endpoint` query).

- `T3` `depends_on: [T2]`
  - Add `schema:read` scope and include endpoint in capabilities/OpenAPI.

- `T4` `depends_on: [T3]`
  - Include schema endpoint in CP API endpoint references.

- `T5` `depends_on: [T2]`
  - Return deterministic `400` for unknown endpoint/version requests.

- `T6` `depends_on: [T4, T5]`
  - Run `php -l` on changed files.
  - Run `scripts/qa/release-gate.sh`.
