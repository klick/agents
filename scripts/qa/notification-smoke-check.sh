#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CONTAINER_PLUGIN_ROOT="/var/www/html/plugins/agents"
HELPER_REL="$CONTAINER_PLUGIN_ROOT/scripts/qa/helpers/notification_harness.php"

fail() {
  echo "FAIL: $1" >&2
  exit 1
}

pass() {
  echo "PASS: $1"
}

json_path() {
  printf '%s' "$2" | php "$PLUGIN_ROOT/scripts/qa/helpers/json_path.php" "$1"
}

run_helper() {
  local output
  if ! output="$(ddev exec php "$HELPER_REL" "$@" 2>&1)"; then
    fail "Notification harness failed for '$*': $output"
  fi
  printf '%s' "$output"
}

cd "$PLUGIN_ROOT"

ddev exec php craft up --interactive=0 >/dev/null

SETTINGS_B64="$(run_helper capture-settings | jq -r '.settings')"
[[ -n "$SETTINGS_B64" && "$SETTINGS_B64" != "null" ]] || fail "Could not capture existing notification settings"

cleanup() {
  ddev exec php "$HELPER_REL" restore-settings "$SETTINGS_B64" >/dev/null 2>&1 || true
  ddev exec php "$HELPER_REL" reset >/dev/null 2>&1 || true
}
trap cleanup EXIT

run_helper reset >/dev/null
pass "Notification tables reset"

first_queue="$(run_helper queue-approval-request 321 --test-settings)"
[[ "$(json_path 'queued' "$first_queue")" == "1" ]] || fail "Approval request should queue exactly one notification"
[[ "$(json_path 'logs.0.eventType' "$first_queue")" == "approval_requested" ]] || fail "Approval request event type missing"
[[ "$(json_path 'logs.0.status' "$first_queue")" == "queued" ]] || fail "Approval request log should start queued"
pass "Approval request notification queued"

second_queue="$(run_helper queue-approval-request 321 --test-settings)"
[[ "$(json_path 'queued' "$second_queue")" == "0" ]] || fail "Duplicate approval request should be deduped"
[[ "$(printf '%s' "$second_queue" | jq -r '.logs | length')" == "1" ]] || fail "Duplicate approval request should not create another log row"
pass "Approval request dedupe works"

webhook_queue="$(run_helper queue-webhook-failure evt_notification_qa --test-settings)"
[[ "$(json_path 'queued' "$webhook_queue")" == "1" ]] || fail "Webhook DLQ failure should queue exactly one notification"
[[ "$(json_path 'logs.1.eventType' "$webhook_queue")" == "webhook_dlq_failed" ]] || fail "Webhook DLQ event type missing"
pass "Webhook DLQ notification queued"

status_check="$(run_helper notifications-check --test-settings)"
[[ "$(json_path 'result.monitorKey' "$status_check")" == "system-status" ]] || fail "Status monitor key mismatch"
[[ -n "$(json_path 'result.currentStatus' "$status_check")" ]] || fail "Status monitor should return current status"
[[ "$(json_path 'monitors.0.monitorKey' "$status_check")" == "system-status" ]] || fail "Monitor state row not persisted"
pass "Status monitor snapshot persisted"

echo "Notification smoke checks complete."
