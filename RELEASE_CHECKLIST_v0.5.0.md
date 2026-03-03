# v0.5.0 Release Checklist

Owner: Agents maintainers  
Date: 2026-03-03

## Dependency Graph

```mermaid
graph TD
  R1[R1 Version + changelog lock]
  R2[R2 QA and release gate]
  R3[R3 Release evidence write-up]
  R4[R4 Commit + tag]
  R5[R5 Post-tag verification]

  R1 --> R2
  R2 --> R3
  R3 --> R4
  R4 --> R5
```

## Tasks

- `R1` `depends_on: []`
  - Bump version in `composer.json`, README current version line, and `Plugin::$schemaVersion`.
  - Move current release notes into `## 0.5.0 - 2026-03-03` in `CHANGELOG.md`.
  - Ensure install snippet reflects `^0.5.0`.

- `R2` `depends_on: [R1]`
  - Run `./scripts/qa/release-gate.sh`.
  - Confirm parity + validation + control/consumer + migration + webhook + credential checks pass.
  - Optional live checks remain gated behind `BASE_URL` and `TOKEN`.

- `R3` `depends_on: [R2]`
  - Write `VALIDATION_v0.5.0.md` with executed commands and outcomes.
  - Capture skipped checks (if any) and rationale.

- `R4` `depends_on: [R3]`
  - Commit release payload.
  - Create annotated tag `v0.5.0`.

- `R5` `depends_on: [R4]`
  - Verify `git show v0.5.0 --no-patch` resolves correctly.
  - Verify working tree is clean after tagging.
