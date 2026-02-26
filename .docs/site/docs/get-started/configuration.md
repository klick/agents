---
title: Configuration
---

# Configuration

The plugin uses a config-first ownership model:

- CP: runtime toggles and safe operator actions
- `config/agents.php`: discovery content/policy fields
- `.env`: security/auth/rate-limit/webhook transport and secrets

## Environment variables

- `PLUGIN_AGENTS_ENABLED`
- `PLUGIN_AGENTS_API_TOKEN`
- `PLUGIN_AGENTS_API_CREDENTIALS`
- `PLUGIN_AGENTS_REQUIRE_TOKEN` (default `true`)
- `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD` (default `false`)
- `PLUGIN_AGENTS_ALLOW_QUERY_TOKEN` (default `false`)
- `PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD` (default `true`)
- `PLUGIN_AGENTS_TOKEN_SCOPES`
- `PLUGIN_AGENTS_REDACT_EMAIL` (default `true`)
- `PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE` (default `60`)
- `PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS` (default `60`)
- `PLUGIN_AGENTS_WEBHOOK_URL`
- `PLUGIN_AGENTS_WEBHOOK_SECRET`
- `PLUGIN_AGENTS_WEBHOOK_TIMEOUT_SECONDS` (default `5`)
- `PLUGIN_AGENTS_WEBHOOK_MAX_ATTEMPTS` (default `3`)

## Runtime enablement precedence

1. If `PLUGIN_AGENTS_ENABLED` is set, it overrides CP/plugin setting state.
2. If `PLUGIN_AGENTS_ENABLED` is not set, CP/plugin setting `enabled` controls runtime on/off.

## Discovery config (`config/agents.php`)

```php
<?php

return [
    'enableLlmsTxt' => true,
    'enableCommerceTxt' => true,
    'llmsTxtCacheTtl' => 86400,
    'commerceTxtCacheTtl' => 3600,
    'llmsSiteSummary' => 'Product and policy discovery for assistants.',
    'llmsIncludeAgentsLinks' => true,
    'llmsIncludeSitemapLink' => true,
    'llmsLinks' => [
        ['label' => 'Support', 'url' => '/support'],
        ['label' => 'Contact', 'url' => '/contact'],
    ],
    'commerceSummary' => 'Commerce metadata for discovery workflows.',
    'commerceCatalogUrl' => '/agents/v1/products?status=live&limit=200',
    'commercePolicyUrls' => [
        'shipping' => '/shipping-information',
        'returns' => '/returns',
        'payment' => '/payment-options',
    ],
    'commerceSupport' => [
        'email' => 'support@example.com',
        'phone' => '+1-555-0100',
        'url' => '/contact',
    ],
    'commerceAttributes' => [
        'currency' => 'USD',
        'region' => 'US',
    ],
];
```

