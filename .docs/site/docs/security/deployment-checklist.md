---
title: Deployment Checklist
---

# Security Deployment Checklist

1. Set a strong `PLUGIN_AGENTS_API_TOKEN` or credential set.
2. Keep `PLUGIN_AGENTS_REQUIRE_TOKEN=true`.
3. Keep `PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD=true`.
4. Keep `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD=false`.
5. Keep `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN=false` unless temporarily required.
6. Apply least-privilege scopes for each integration.
7. Verify `capabilities` and `openapi.json` outputs match expected policy.
8. Run local regression checks before promotion:

```bash
./scripts/security-regression-check.sh https://example.com "$PLUGIN_AGENTS_API_TOKEN"
```

## Verification quick checks

```bash
# Missing token should fail
curl -i "https://example.com/agents/v1/health"

# Query token should fail by default
curl -i "https://example.com/agents/v1/health?apiToken=$PLUGIN_AGENTS_API_TOKEN"

# Header token should pass
curl -i -H "Authorization: Bearer $PLUGIN_AGENTS_API_TOKEN" \
  "https://example.com/agents/v1/health"
```

