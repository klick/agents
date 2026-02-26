---
title: Control Panel Cockpit
---

# Control Panel Cockpit

The plugin CP section provides a 4-tab operational cockpit:

- `agents/overview`
- `agents/readiness`
- `agents/discovery`
- `agents/security`

Legacy aliases remain supported:

- `agents` -> overview
- `agents/dashboard` -> overview
- `agents/health` -> readiness

## Tab responsibilities

### Overview

- runtime on/off state and source (`env` vs settings)
- env-lock aware toggle behavior
- quick API/discovery links
- prewarm entrypoint

### Readiness

- readiness score
- score breakdown and component checks
- diagnostics JSON blocks

### Discovery

- read-only status and metadata for discovery docs
- preview snippets
- prewarm and clear-cache actions

### Security

- effective auth/rate-limit/redaction/webhook posture
- warning and error visibility
- no secret value exposure

