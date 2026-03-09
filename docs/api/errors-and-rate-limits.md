# Errors & Rate Limits

## Error schema

Guarded endpoints return stable JSON error payloads with:

- HTTP status
- `error.code`
- `error.message`
- `requestId`

Response header:

- `X-Request-Id`

## Error codes

- `INVALID_REQUEST` (`400`)
- `UNAUTHORIZED` (`401`)
- `FORBIDDEN` (`403`)
- `NOT_FOUND` (`404`)
- `METHOD_NOT_ALLOWED` (`405`)
- `RATE_LIMIT_EXCEEDED` (`429`)
- `SERVICE_DISABLED` (`503`)
- `SERVER_MISCONFIGURED` (`503`)
- `INTERNAL_ERROR` (`500`)

## Rate limiting

Rate limit is applied in pre-auth and post-auth stages.

Config:

- `PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE`
- `PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS`

Headers:

- `X-RateLimit-Limit`
- `X-RateLimit-Remaining`
- `X-RateLimit-Reset`

On limit exceeded:

- HTTP `429`
- `RATE_LIMIT_EXCEEDED`
