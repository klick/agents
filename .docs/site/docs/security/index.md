---
title: Security Overview
---

# Security Overview

Security posture is designed for fail-closed defaults in production.

## Core defaults

- token auth required by default
- query-token transport disabled by default
- missing token config in production fails closed by default
- sensitive order data scope-gated
- non-live entry access scope-gated
- rate limiting enabled by default

## Key controls

- `PLUGIN_AGENTS_REQUIRE_TOKEN`
- `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD`
- `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN`
- `PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD`
- `PLUGIN_AGENTS_TOKEN_SCOPES`
- `PLUGIN_AGENTS_REDACT_EMAIL`
- rate-limit env vars

See:

- [Deployment Checklist](/security/deployment-checklist)

