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

SETTINGS_MODEL="$PLUGIN_ROOT/src/models/Settings.php"
SETTINGS_TEMPLATE="$PLUGIN_ROOT/src/templates/settings.twig"
STATUS_TEMPLATE="$PLUGIN_ROOT/src/templates/dashboard.twig"
PLUGIN_FILE="$PLUGIN_ROOT/src/Plugin.php"
CONTROL_SERVICE="$PLUGIN_ROOT/src/services/ControlPlaneService.php"
WEBHOOK_SERVICE="$PLUGIN_ROOT/src/services/WebhookService.php"
NOTIFICATION_SERVICE="$PLUGIN_ROOT/src/services/NotificationService.php"
NOTIFICATION_JOB="$PLUGIN_ROOT/src/queue/jobs/SendOperatorNotificationJob.php"
CONSOLE_CONTROLLER="$PLUGIN_ROOT/src/console/controllers/AgentsController.php"
DASHBOARD_CONTROLLER="$PLUGIN_ROOT/src/controllers/DashboardController.php"
MIGRATION_FILE="$PLUGIN_ROOT/src/migrations/m260313_100000_add_notification_tables.php"
SMOKE_SCRIPT="$PLUGIN_ROOT/scripts/qa/notification-smoke-check.sh"
SMOKE_HELPER="$PLUGIN_ROOT/scripts/qa/helpers/notification_harness.php"

expect_fixed "public bool \$notificationsEnabled = false;" "$SETTINGS_MODEL" "Settings model exposes notifications enabled flag"
expect_fixed "public string \$notificationRecipients = '\$PLUGIN_AGENTS_NOTIFICATION_RECIPIENTS';" "$SETTINGS_MODEL" "Settings model defaults notification recipients to env reference"
expect_fixed "public bool \$notificationApprovalRequested = true;" "$SETTINGS_MODEL" "Settings model exposes approval-requested toggle"
expect_fixed "public bool \$notificationStatusChanged = true;" "$SETTINGS_MODEL" "Settings model exposes status-change toggle"
expect_fixed "id=\"notificationsSection\"" "$SETTINGS_TEMPLATE" "Settings template exposes notifications section"
expect_fixed "php craft agents/notifications-check" "$SETTINGS_TEMPLATE" "Settings template documents scheduled notifications check"
expect_fixed "notificationRecipients" "$DASHBOARD_CONTROLLER" "Dashboard controller persists notification recipients"
expect_fixed "notificationStatusChanged" "$DASHBOARD_CONTROLLER" "Dashboard controller persists status-change toggle"
expect_fixed "'notificationRecipients' => array_key_exists('notificationRecipients', \$config)" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes notification recipients config lock"
expect_fixed "'notificationStatusChanged' => array_key_exists('notificationStatusChanged', \$config)" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes status-change config lock"
expect_fixed "'notificationService' => NotificationService::class" "$PLUGIN_FILE" "Plugin registers notification service component"
expect_fixed "public function getNotificationService(): NotificationService" "$PLUGIN_FILE" "Plugin exposes notification service getter"
expect_fixed "public function queueApprovalRequested(array \$approval): int" "$NOTIFICATION_SERVICE" "Notification service queues approval-requested emails"
expect_fixed "public function queueWebhookDeadLetter(array \$event): int" "$NOTIFICATION_SERVICE" "Notification service queues webhook DLQ emails"
expect_fixed "public function runStatusMonitor(): array" "$NOTIFICATION_SERVICE" "Notification service exposes system status monitor"
expect_fixed "public function getCpSnapshot(int \$limit = 20): array" "$NOTIFICATION_SERVICE" "Notification service exposes CP snapshot"
expect_fixed "new Message()" "$NOTIFICATION_SERVICE" "Notification service instantiates Craft mail messages directly"
expect_fixed "lastSentRecipient" "$NOTIFICATION_SERVICE" "Notification service tracks the last successful recipient in the CP snapshot"
expect_fixed "SendOperatorNotificationJob" "$NOTIFICATION_SERVICE" "Notification service queues background email delivery"
expect_fixed "public int \$notificationId = 0;" "$NOTIFICATION_JOB" "Notification queue job carries notification ID"
expect_fixed "public function actionNotificationsCheck(): int" "$CONSOLE_CONTROLLER" "Console controller exposes notifications-check command"
expect_fixed "'notifications-check'" "$CONSOLE_CONTROLLER" "Console controller registers notifications-check options"
expect_fixed "public function actionRunNotificationsCheck(): Response" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes manual notifications check action"
expect_fixed "queueApprovalRequested" "$CONTROL_SERVICE" "Control plane queues approval-requested notifications"
expect_fixed "queueApprovalDecided" "$CONTROL_SERVICE" "Control plane queues approval-decision notifications"
expect_fixed "queueExecutionIssue" "$CONTROL_SERVICE" "Control plane queues execution-issue notifications"
expect_fixed "queueWebhookDeadLetter" "$WEBHOOK_SERVICE" "Webhook service queues DLQ notifications"
expect_fixed "resolveManagedCredentialRecipientEmails" "$NOTIFICATION_SERVICE" "Notification service resolves account-specific approval recipients"
expect_fixed "accountRecipientCount" "$NOTIFICATION_SERVICE" "Notification service exposes account-specific recipients in the CP snapshot"
expect_fixed "getNotificationTargetsForHandle" "$NOTIFICATION_SERVICE" "Notification service uses credential recipient lookups"
expect_fixed "agents_notification_log" "$MIGRATION_FILE" "Migration creates notification log table"
expect_fixed "agents_notification_monitors" "$MIGRATION_FILE" "Migration creates notification monitor table"
expect_fixed "capture-settings" "$SMOKE_HELPER" "Notification harness captures existing settings"
expect_fixed "queue-approval-request" "$SMOKE_HELPER" "Notification harness queues approval notifications"
expect_fixed "notifications-check" "$SMOKE_HELPER" "Notification harness runs status monitor"
expect_fixed "queue-approval-request 321" "$SMOKE_SCRIPT" "Notification smoke script validates approval queueing"
expect_fixed "queue-webhook-failure evt_notification_qa" "$SMOKE_SCRIPT" "Notification smoke script validates webhook notification queueing"
expect_fixed "id=\"operatorNotificationsSection\"" "$STATUS_TEMPLATE" "Status template exposes operator notifications card"
expect_fixed "Run status check" "$STATUS_TEMPLATE" "Status template exposes manual notifications check button"
expect_fixed "Last handoff:" "$STATUS_TEMPLATE" "Status template exposes the last notification handoff row"
expect_fixed "Recent deliveries" "$STATUS_TEMPLATE" "Status template renders recent notification deliveries"

echo "Notification regression checks completed."
