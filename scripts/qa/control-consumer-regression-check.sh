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

API_CONTROLLER="$PLUGIN_ROOT/src/controllers/ApiController.php"
PLUGIN_FILE="$PLUGIN_ROOT/src/Plugin.php"

expect_fixed "public function actionAuthWhoami(): Response" "$API_CONTROLLER" "API exposes auth diagnostics endpoint action"
expect_fixed "public function actionAdoptionMetrics(): Response" "$API_CONTROLLER" "API exposes adoption metrics endpoint action"
expect_fixed "public function actionMetrics(): Response" "$API_CONTROLLER" "API exposes observability metrics endpoint action"
expect_fixed "public function actionConsumersLag(): Response" "$API_CONTROLLER" "API exposes consumer lag endpoint action"
expect_fixed "public function actionConsumersCheckpoint(): Response" "$API_CONTROLLER" "API exposes consumer checkpoint endpoint action"

expect_fixed "public function actionControlPolicies(): Response" "$API_CONTROLLER" "API exposes control policies endpoint action"
expect_fixed "public function actionControlPolicyUpsert(): Response" "$API_CONTROLLER" "API exposes control policy upsert endpoint action"
expect_fixed "public function actionControlApprovals(): Response" "$API_CONTROLLER" "API exposes control approvals endpoint action"
expect_fixed "public function actionControlApprovalRequest(): Response" "$API_CONTROLLER" "API exposes control approval request endpoint action"
expect_fixed "public function actionControlApprovalDecide(): Response" "$API_CONTROLLER" "API exposes control approval decide endpoint action"
expect_fixed "public function actionControlExecutions(): Response" "$API_CONTROLLER" "API exposes control executions endpoint action"
expect_fixed "public function actionControlPolicySimulate(): Response" "$API_CONTROLLER" "API exposes control policy simulator endpoint action"
expect_fixed "public function actionControlActionsExecute(): Response" "$API_CONTROLLER" "API exposes control execute endpoint action"
expect_fixed "public function actionControlAudit(): Response" "$API_CONTROLLER" "API exposes control audit endpoint action"

expect_fixed "public function actionWebhookDlqList(): Response" "$API_CONTROLLER" "API exposes webhook DLQ list endpoint action"
expect_fixed "public function actionWebhookDlqReplay(): Response" "$API_CONTROLLER" "API exposes webhook DLQ replay endpoint action"

expect_fixed "'/consumers/lag'" "$API_CONTROLLER" "OpenAPI/capabilities include consumers lag path"
expect_fixed "'/consumers/checkpoint'" "$API_CONTROLLER" "OpenAPI/capabilities include consumers checkpoint path"
expect_fixed "'/adoption/metrics'" "$API_CONTROLLER" "OpenAPI/capabilities include adoption metrics path"
expect_fixed "'/metrics'" "$API_CONTROLLER" "OpenAPI/capabilities include observability metrics path"
expect_fixed "'/control/actions/execute'" "$API_CONTROLLER" "OpenAPI/capabilities include control execute path"
expect_fixed "'/webhooks/dlq/replay'" "$API_CONTROLLER" "OpenAPI/capabilities include webhook replay path"
expect_fixed "adoption:read" "$API_CONTROLLER" "Adoption metrics scope is declared in API controller"
expect_fixed "metrics:read" "$API_CONTROLLER" "Observability metrics scope is declared in API controller"
expect_fixed "control:actions:execute" "$API_CONTROLLER" "Control execution scope is declared in API controller"
expect_fixed "webhooks:dlq:replay" "$API_CONTROLLER" "Webhook replay scope is declared in API controller"

expect_fixed "'agents/v1/adoption/metrics' => 'agents/api/adoption-metrics'" "$PLUGIN_FILE" "Plugin registers adoption metrics route"
expect_fixed "'agents/v1/metrics' => 'agents/api/metrics'" "$PLUGIN_FILE" "Plugin registers observability metrics route"
expect_fixed "'agents/v1/consumers/checkpoint' => 'agents/api/consumers-checkpoint'" "$PLUGIN_FILE" "Plugin registers consumers checkpoint route"
expect_fixed "'agents/v1/consumers/lag' => 'agents/api/consumers-lag'" "$PLUGIN_FILE" "Plugin registers consumers lag route"
expect_fixed "'agents/v1/webhooks/dlq' => 'agents/api/webhook-dlq-list'" "$PLUGIN_FILE" "Plugin registers webhook DLQ list route"
expect_fixed "'agents/v1/webhooks/dlq/replay' => 'agents/api/webhook-dlq-replay'" "$PLUGIN_FILE" "Plugin registers webhook DLQ replay route"

echo "Control/consumer regression checks completed."
