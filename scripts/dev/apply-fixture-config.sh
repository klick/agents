#!/usr/bin/env bash
set -euo pipefail

CRAFT_PROJECT_DIR="${1:-}"
TOKEN="${2:-agents-local-token}"

if [[ -z "$CRAFT_PROJECT_DIR" ]]; then
  echo "Usage: $0 <craft-project-dir> [api-token]"
  echo "Example: $0 ~/sites/agents-sandbox agents-local-token"
  exit 1
fi

if [[ ! -f "$CRAFT_PROJECT_DIR/.env" ]]; then
  echo "ERROR: .env not found in $CRAFT_PROJECT_DIR"
  exit 1
fi

set_env_var() {
  local key="$1"
  local value="$2"
  local file="$3"
  local tmp_file
  tmp_file="$(mktemp)"

  awk -v k="$key" -v v="$value" '
    BEGIN { found = 0 }
    $0 ~ "^" k "=" {
      print k "=" v
      found = 1
      next
    }
    { print }
    END {
      if (!found) {
        print k "=" v
      }
    }
  ' "$file" > "$tmp_file"

  mv "$tmp_file" "$file"
}

ENV_FILE="$CRAFT_PROJECT_DIR/.env"
set_env_var "PLUGIN_AGENTS_ENABLED" "true" "$ENV_FILE"
set_env_var "PLUGIN_AGENTS_REQUIRE_TOKEN" "true" "$ENV_FILE"
set_env_var "PLUGIN_AGENTS_API_TOKEN" "$TOKEN" "$ENV_FILE"
set_env_var "PLUGIN_AGENTS_ALLOW_QUERY_TOKEN" "false" "$ENV_FILE"
set_env_var "PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD" "true" "$ENV_FILE"
set_env_var "PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD" "false" "$ENV_FILE"
set_env_var "PLUGIN_AGENTS_REDACT_EMAIL" "true" "$ENV_FILE"
set_env_var "PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE" "120" "$ENV_FILE"
set_env_var "PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS" "60" "$ENV_FILE"

mkdir -p "$CRAFT_PROJECT_DIR/config"
cat > "$CRAFT_PROJECT_DIR/config/agents.php" <<'PHP'
<?php

return [
    'enableLlmsTxt' => true,
    'enableCommerceTxt' => true,
    'llmsSiteSummary' => 'Local deterministic fixture summary for agent development.',
    'llmsIncludeAgentsLinks' => true,
    'llmsIncludeSitemapLink' => true,
    'llmsLinks' => [
        ['label' => 'Fixture Help', 'url' => '/help'],
    ],
    'commerceSummary' => 'Local deterministic commerce fixture metadata.',
    'commerceCatalogUrl' => '/agents/v1/products?status=live&limit=50',
    'commercePolicyUrls' => [
        'shipping' => '/shipping',
        'returns' => '/returns',
        'payment' => '/payment',
    ],
    'commerceSupport' => [
        'email' => 'support@example.test',
        'phone' => '+1-555-0100',
        'url' => '/contact',
    ],
    'commerceAttributes' => [
        'currency' => 'USD',
        'region' => 'US',
    ],
];
PHP

echo "Applied deterministic plugin fixture config to: $CRAFT_PROJECT_DIR"
echo "Token configured as: $TOKEN"
