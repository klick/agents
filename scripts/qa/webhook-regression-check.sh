#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

fail() {
  echo "FAIL: $1"
  exit 1
}

pass() {
  echo "PASS: $1"
}

expect_fixed() {
  local needle="$1"
  local file="$2"
  local description="$3"
  if grep -Fq "$needle" "$file"; then
    pass "$description"
    return
  fi
  fail "$description (missing: $needle in $file)"
}

WEBHOOK_SERVICE="$PLUGIN_ROOT/src/services/WebhookService.php"
WEBHOOK_JOB="$PLUGIN_ROOT/src/queue/jobs/DeliverWebhookJob.php"
WEBHOOK_TEST_SINK_SERVICE="$PLUGIN_ROOT/src/services/WebhookTestSinkService.php"
WEBHOOK_TEST_SINK_CONTROLLER="$PLUGIN_ROOT/src/controllers/WebhookTestSinkController.php"
PLUGIN_FILE="$PLUGIN_ROOT/src/Plugin.php"

expect_fixed "if (!Plugin::getInstance()->isAgentsEnabled()) {" "$WEBHOOK_SERVICE" "Webhook enqueue path is runtime-gated"
expect_fixed "if (!\$config['enabled']) {" "$WEBHOOK_SERVICE" "Webhook enqueue path requires full webhook config"
expect_fixed "getSecurityPolicyService()->getRuntimeConfig()" "$WEBHOOK_SERVICE" "Webhook delivery resolves transport settings from shared runtime config"
expect_fixed "'resourceType' => \$change['resourceType']" "$WEBHOOK_SERVICE" "Webhook payload includes resourceType"
expect_fixed "'resourceId' => \$change['resourceId']" "$WEBHOOK_SERVICE" "Webhook payload includes resourceId"
expect_fixed "'action' => \$change['action']" "$WEBHOOK_SERVICE" "Webhook payload includes action"
expect_fixed "'updatedAt' => \$change['updatedAt']" "$WEBHOOK_SERVICE" "Webhook payload includes updatedAt"
expect_fixed "'snapshot' => \$change['snapshot']" "$WEBHOOK_SERVICE" "Webhook payload includes snapshot"
expect_fixed "'resourceType' => 'product'" "$WEBHOOK_SERVICE" "Variant mapping still routes to product resource type"
expect_fixed "'action' => 'updated'" "$WEBHOOK_SERVICE" "Variant mapping still emits product updated action"

expect_fixed "'X-Agents-Webhook-Id'" "$WEBHOOK_JOB" "Webhook delivery includes stable event-id header"
expect_fixed "'X-Agents-Webhook-Timestamp'" "$WEBHOOK_JOB" "Webhook delivery includes timestamp header"
expect_fixed "'X-Agents-Webhook-Signature'" "$WEBHOOK_JOB" "Webhook delivery includes HMAC signature header"
expect_fixed "hash_hmac('sha256', \$timestamp . '.' . \$body, \$this->secret)" "$WEBHOOK_JOB" "Webhook signature string format is stable"
expect_fixed "return \$attempt < max(1, \$this->maxAttempts);" "$WEBHOOK_JOB" "Webhook retry ceiling remains configurable and bounded"
expect_fixed "PLUGIN_AGENTS_WEBHOOK_TEST_SINK" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink is gated by explicit dev-only env flag"
expect_fixed "getSecurityPolicyService()->getRuntimeConfig()" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink reuses shared runtime webhook settings"
expect_fixed "UrlHelper::siteUrl(self::ROUTE_PATH)" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink publishes a copyable local URL"
expect_fixed "hash_hmac('sha256', trim(\$timestamp) . '.' . \$rawBody, \$secret)" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink verifies delivery signatures using the standard HMAC format"
expect_fixed "public function sendTestDelivery(): array" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink exposes one-click test delivery helper"
expect_fixed "new DeliverWebhookJob([" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink one-click delivery reuses the real delivery job"
expect_fixed "public function actionReceive(): Response" "$WEBHOOK_TEST_SINK_CONTROLLER" "Webhook test sink exposes a public receive action"
expect_fixed "protected array|bool|int \$supportsCsrfValidation = false;" "$WEBHOOK_TEST_SINK_CONTROLLER" "Webhook test sink disables CSRF for machine delivery"

expect_fixed "elements\\\\Order" "$PLUGIN_FILE" "Order webhook hook registration is present"
expect_fixed "elements\\\\Product" "$PLUGIN_FILE" "Product webhook hook registration is present"
expect_fixed "Entry::class" "$PLUGIN_FILE" "Entry webhook hook registration is present"
expect_fixed "'agents/dev/webhook-test-sink' => 'agents/webhook-test-sink/receive'" "$PLUGIN_FILE" "Plugin registers the dev webhook test sink route"

echo "Webhook regression checks completed."
