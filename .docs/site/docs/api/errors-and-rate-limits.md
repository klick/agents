---
title: Errors & Rate Limits
---

# Errors & Rate Limits

## Response schema

```json
{
  "error": "UNAUTHORIZED",
  "message": "Missing or invalid token.",
  "status": 401,
  "requestId": "agents-9fd2b20abec4a65f"
}
```

All API responses include `X-Request-Id`.

## Error code map

- `INVALID_REQUEST` (`400`)
- `UNAUTHORIZED` (`401`)
- `FORBIDDEN` (`403`)
- `NOT_FOUND` (`404`)
- `METHOD_NOT_ALLOWED` (`405`)
- `RATE_LIMIT_EXCEEDED` (`429`)
- `SERVICE_DISABLED` (`503`)
- `SERVER_MISCONFIGURED` (`503`)

## Rate limiting

Guarded requests expose:

- `X-RateLimit-Limit`
- `X-RateLimit-Remaining`
- `X-RateLimit-Reset`

Rate limiting applies pre-auth and post-auth to reduce brute-force and abusive retry patterns.

