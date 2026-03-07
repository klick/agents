#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

fail() {
  echo "FAIL: $1"
  exit 1
}

expect_file() {
  local file="$1"
  local label="$2"
  [[ -f "$file" ]] || fail "$label missing: $file"
}

expect_fixed() {
  local needle="$1"
  local file="$2"
  local label="$3"
  grep -qF "$needle" "$file" || fail "$label"
}

expect_file "$PLUGIN_ROOT/src/services/ReliabilitySignalService.php" "Reliability signal service"
expect_file "$PLUGIN_ROOT/docs/observability-runbook.md" "Observability runbook doc"

expect_fixed "'reliabilitySignalService' => ReliabilitySignalService::class" "$PLUGIN_ROOT/src/Plugin.php" "Reliability service component missing"
expect_fixed "public function getReliabilitySignalService(): ReliabilitySignalService" "$PLUGIN_ROOT/src/Plugin.php" "Reliability service getter missing"

expect_fixed "'reliability' => \$reliability" "$PLUGIN_ROOT/src/services/ObservabilityMetricsService.php" "Metrics snapshot missing reliability payload"
expect_fixed "'reliability.signals'" "$PLUGIN_ROOT/src/services/DiagnosticsBundleService.php" "Diagnostics bundle missing reliability capture"
expect_fixed "'reliability' => \$reliabilitySnapshot" "$PLUGIN_ROOT/src/services/DiagnosticsBundleService.php" "Diagnostics snapshots missing reliability entry"
expect_fixed "'reliabilityStatus' => (string)(\$reliability['status'] ?? 'ok')" "$PLUGIN_ROOT/src/controllers/DashboardController.php" "Dashboard summary missing reliability status"

expect_fixed "Needs Attention Now" "$PLUGIN_ROOT/src/templates/dashboard.twig" "Dashboard readiness is missing reliability triage section"
expect_fixed "Runbook & Alert Guidance" "$PLUGIN_ROOT/src/templates/dashboard.twig" "Dashboard runbook section missing"
expect_fixed "observabilitySummary.reliabilitySignals|default([])" "$PLUGIN_ROOT/src/templates/dashboard.twig" "Dashboard runbook section is not driven by reliability signals"
expect_fixed "reliabilityConsumerLagWarnSeconds" "$PLUGIN_ROOT/src/models/Settings.php" "Settings model missing configurable reliability warn threshold"
expect_fixed "reliabilityConsumerLagCriticalSeconds" "$PLUGIN_ROOT/src/models/Settings.php" "Settings model missing configurable reliability critical threshold"
expect_fixed "consumerLagThresholds()" "$PLUGIN_ROOT/src/services/ReliabilitySignalService.php" "Reliability service missing configurable consumer lag thresholds"
expect_fixed "reliabilityConsumerLagWarnSeconds" "$PLUGIN_ROOT/src/templates/settings.twig" "Settings UI missing consumer lag warn threshold field"
expect_fixed "reliabilityConsumerLagCriticalSeconds" "$PLUGIN_ROOT/src/templates/settings.twig" "Settings UI missing consumer lag critical threshold field"

expect_fixed "public function actionReliabilityCheck(): int" "$PLUGIN_ROOT/src/console/controllers/AgentsController.php" "CLI reliability-check action missing"
expect_fixed "'reliability-check'" "$PLUGIN_ROOT/src/console/controllers/AgentsController.php" "CLI reliability-check option binding missing"
expect_fixed "'agents/reliability-check'" "$PLUGIN_ROOT/src/controllers/ApiController.php" "Capabilities command list missing reliability-check"

expect_fixed "php craft agents/reliability-check" "$PLUGIN_ROOT/docs/observability-runbook.md" "Runbook missing CLI reliability command"
expect_fixed "agents_auth_failures_total" "$PLUGIN_ROOT/docs/observability-runbook.md" "Runbook missing auth-failures metric reference"
expect_fixed "agents_consumer_lag_max_seconds" "$PLUGIN_ROOT/docs/observability-runbook.md" "Runbook missing consumer lag metric reference"

echo "PASS: reliability pack regression checks pass"
