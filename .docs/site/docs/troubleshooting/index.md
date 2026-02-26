---
title: Troubleshooting
---

# Troubleshooting

## Flow

1. Capture `X-Request-Id` from failing response.
2. Confirm `status` + `error` code.
3. Apply the code-specific fix path.
4. Correlate using `X-Request-Id` in server logs.

## Common error paths

- `UNAUTHORIZED` (`401`): missing/invalid token or wrong transport
- `FORBIDDEN` (`403`): token missing required scope
- `INVALID_REQUEST` (`400`): malformed query or invalid identifier combination
- `NOT_FOUND` (`404`): resource does not exist
- `METHOD_NOT_ALLOWED` (`405`): endpoint supports only `GET`/`HEAD`
- `RATE_LIMIT_EXCEEDED` (`429`): retry after reset
- `SERVICE_DISABLED` (`503`): runtime disabled by env/CP state
- `SERVER_MISCONFIGURED` (`503`): auth/security env configuration invalid

## Quick diagnostics

- Check `/agents/v1/capabilities` for active auth/scope posture
- Check `/agents/v1/openapi.json` for endpoint contract visibility
- Verify queue workers for webhook-related issues

