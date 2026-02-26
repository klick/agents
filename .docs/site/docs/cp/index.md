---
title: Control Panel Cockpit
---

# Control Panel Cockpit

The plugin CP section provides a 5-tab operational cockpit:

- `agents/overview`
- `agents/readiness`
- `agents/discovery`
- `agents/security`
- `agents/credentials`

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

### Credentials

- CP-managed credential create/rotate/revoke workflow
- one-time token reveal on create/rotate
- last-used metadata (`lastUsedAt`, `lastUsedIp`, auth method)
- compatibility with existing env-defined credentials
