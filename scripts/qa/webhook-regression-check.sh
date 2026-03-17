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
WEBHOOK_PROBE_SERVICE="$PLUGIN_ROOT/src/services/WebhookProbeService.php"
WEBHOOK_JOB="$PLUGIN_ROOT/src/queue/jobs/DeliverWebhookJob.php"
WEBHOOK_TEST_SINK_SERVICE="$PLUGIN_ROOT/src/services/WebhookTestSinkService.php"
WEBHOOK_TEST_SINK_CONTROLLER="$PLUGIN_ROOT/src/controllers/WebhookTestSinkController.php"
WEBHOOK_PROBE_MIGRATION="$PLUGIN_ROOT/src/migrations/m260317_120000_add_webhook_probe_runs_table.php"
CP_CONTROLLER="$PLUGIN_ROOT/src/controllers/DashboardController.php"
CP_TEMPLATE="$PLUGIN_ROOT/src/templates/dashboard.twig"
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
expect_fixed "public function deliverPayloadNow(array \$payload, array \$config = []): array" "$WEBHOOK_SERVICE" "Webhook service exposes a reusable synchronous delivery helper"
expect_fixed "'X-Agents-Webhook-Id' => \$eventId" "$WEBHOOK_SERVICE" "Webhook service includes stable event-id header on direct delivery"
expect_fixed "'X-Agents-Webhook-Timestamp' => \$timestamp" "$WEBHOOK_SERVICE" "Webhook service includes timestamp header on direct delivery"
expect_fixed "'X-Agents-Webhook-Signature' => 'sha256=' . \$signature" "$WEBHOOK_SERVICE" "Webhook service includes HMAC signature header on direct delivery"
expect_fixed "hash_hmac('sha256', \$timestamp . '.' . \$body, \$secret)" "$WEBHOOK_SERVICE" "Webhook service keeps the canonical HMAC string format"

expect_fixed "getWebhookService()->deliverPayloadNow(\$this->payload" "$WEBHOOK_JOB" "Queued webhook delivery reuses the shared synchronous transport helper"
expect_fixed "return \$attempt < max(1, \$this->maxAttempts);" "$WEBHOOK_JOB" "Webhook retry ceiling remains configurable and bounded"
expect_fixed "PLUGIN_AGENTS_WEBHOOK_TEST_SINK" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink is gated by explicit dev-only env flag"
expect_fixed "getSecurityPolicyService()->getRuntimeConfig()" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink reuses shared runtime webhook settings"
expect_fixed "UrlHelper::siteUrl(self::ROUTE_PATH)" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink publishes a copyable local URL"
expect_fixed "hash_hmac('sha256', trim(\$timestamp) . '.' . \$rawBody, \$secret)" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink verifies delivery signatures using the standard HMAC format"
expect_fixed "public function sendTestDelivery(): array" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink exposes one-click test delivery helper"
expect_fixed "new DeliverWebhookJob([" "$WEBHOOK_TEST_SINK_SERVICE" "Webhook test sink one-click delivery reuses the real delivery job"
expect_fixed "public function actionReceive(): Response" "$WEBHOOK_TEST_SINK_CONTROLLER" "Webhook test sink exposes a public receive action"
expect_fixed "protected array|bool|int \$supportsCsrfValidation = false;" "$WEBHOOK_TEST_SINK_CONTROLLER" "Webhook test sink disables CSRF for machine delivery"
expect_fixed "class m260317_120000_add_webhook_probe_runs_table extends Migration" "$WEBHOOK_PROBE_MIGRATION" "Webhook probe migration is present"
expect_fixed "{{%agents_webhook_probe_runs}}" "$WEBHOOK_PROBE_MIGRATION" "Webhook probe migration defines the probe runs table"
expect_fixed "public function sendProductionProbe(?User \$actor = null): array" "$WEBHOOK_PROBE_SERVICE" "Webhook probe service exposes production probe sending"
expect_fixed "'eventKind' => 'probe'" "$WEBHOOK_PROBE_SERVICE" "Webhook probe payload marks probe event kind explicitly"
expect_fixed "'isProbe' => true" "$WEBHOOK_PROBE_SERVICE" "Webhook probe payload includes a stable probe marker"
expect_fixed "'probeId' => \$probeId" "$WEBHOOK_PROBE_SERVICE" "Webhook probe payload includes a probe identifier"
expect_fixed "Webhook probe cooldown is active" "$WEBHOOK_PROBE_SERVICE" "Webhook probe service enforces a cooldown"
expect_fixed "public function actionSendWebhookProbe(): Response" "$CP_CONTROLLER" "Dashboard controller exposes the production webhook probe action"
expect_fixed "getWebhookProbeService()->getCpSnapshot" "$CP_CONTROLLER" "Dashboard controller loads webhook probe snapshot data for CP"
expect_fixed "Unable to send production webhook probe" "$CP_CONTROLLER" "Dashboard controller surfaces probe send failures clearly"
expect_fixed "Webhook Probe" "$CP_TEMPLATE" "Status template renders the production webhook probe card"
expect_fixed "send-webhook-probe" "$CP_TEMPLATE" "Status template exposes the production webhook probe action"
expect_fixed "webhookProbePayloadDialog" "$CP_TEMPLATE" "Status template exposes the webhook probe payload dialog"
expect_fixed "Open webhook probe" "$CP_TEMPLATE" "Status detail actions link to the probe section"

expect_fixed "elements\\\\Order" "$PLUGIN_FILE" "Order webhook hook registration is present"
expect_fixed "elements\\\\Product" "$PLUGIN_FILE" "Product webhook hook registration is present"
expect_fixed "Entry::class" "$PLUGIN_FILE" "Entry webhook hook registration is present"
expect_fixed "'agents/dev/webhook-test-sink' => 'agents/webhook-test-sink/receive'" "$PLUGIN_FILE" "Plugin registers the dev webhook test sink route"
expect_fixed "'webhookProbeService' => WebhookProbeService::class" "$PLUGIN_FILE" "Plugin registers the webhook probe service"

echo "Webhook regression checks completed."
