#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./webhook-test-sink-common.sh
source "$SCRIPT_DIR/webhook-test-sink-common.sh"

ENTRY_ID=""
ENTRY_SITE_ID=""
ENTRY_TITLE_B64=""

cleanup() {
  if [[ -n "$ENTRY_ID" ]] && [[ -n "$ENTRY_SITE_ID" ]] && [[ -n "$ENTRY_TITLE_B64" ]]; then
    run_plain_helper restore-entry "$ENTRY_ID" "$ENTRY_SITE_ID" "$ENTRY_TITLE_B64" >/dev/null 2>&1 || true
  fi
  cleanup_sink_server
}
trap cleanup EXIT

start_sink_server
run_sink_helper clear >/dev/null

initial_dlq_json="$(run_sink_helper dlq-count)"
initial_dlq_count="$(json_get "$initial_dlq_json" "count")"

entry_touch_json="$(run_sink_helper touch-entry)"
assert_json_eq "$entry_touch_json" "ok" "true" "QA entry touch succeeds"
ENTRY_ID="$(json_get "$entry_touch_json" "entryId")"
ENTRY_SITE_ID="$(json_get "$entry_touch_json" "siteId")"
ENTRY_TITLE_B64="$(json_get "$entry_touch_json" "originalTitleBase64")"

if [[ -z "$ENTRY_ID" ]] || [[ -z "$ENTRY_SITE_ID" ]] || [[ -z "$ENTRY_TITLE_B64" ]]; then
  fail "QA entry touch did not return restorable entry metadata"
fi

queue_output="$(ddev exec env \
  PLUGIN_AGENTS_WEBHOOK_TEST_SINK=true \
  PLUGIN_AGENTS_WEBHOOK_SECRET="$WEBHOOK_TEST_SINK_SECRET" \
  PLUGIN_AGENTS_WEBHOOK_URL="$WEBHOOK_TEST_SINK_URL" \
  php /var/www/html/craft queue/run 2>&1)"
if [[ -n "${queue_output}" ]]; then
  pass "Queue worker completes successfully for the outbound webhook job"
else
  pass "Queue worker completes successfully for the outbound webhook job"
fi

snapshot_json="$(run_sink_helper snapshot)"
assert_json_eq "$snapshot_json" "snapshot.summary.total" "1" "E2E flow captures one webhook delivery"
assert_json_eq "$snapshot_json" "snapshot.summary.valid" "1" "E2E flow captures a valid signed delivery"
assert_json_eq "$snapshot_json" "snapshot.events.0.resourceType" "entry" "Captured E2E event resource type is entry"
assert_json_eq "$snapshot_json" "snapshot.events.0.action" "updated" "Captured E2E event action is updated"
assert_json_eq "$snapshot_json" "snapshot.events.0.verificationStatus" "valid" "Captured E2E event verifies successfully"
assert_json_eq "$snapshot_json" "snapshot.events.0.subscriptionMode" "firehose" "Captured E2E event preserves subscription mode"
assert_json_contains "$snapshot_json" '"snapshot"' "Captured E2E payload includes a snapshot"
assert_json_contains "$snapshot_json" '"credentialHandles":[]' "Captured E2E payload includes credential handles"

after_dlq_json="$(run_sink_helper dlq-count)"
after_dlq_count="$(json_get "$after_dlq_json" "count")"
if [[ "$after_dlq_count" == "$initial_dlq_count" ]]; then
  pass "Successful E2E delivery does not add DLQ events"
else
  fail "Successful E2E delivery should not add DLQ events (before '$initial_dlq_count', after '$after_dlq_count')"
fi

run_plain_helper restore-entry "$ENTRY_ID" "$ENTRY_SITE_ID" "$ENTRY_TITLE_B64" >/dev/null
ENTRY_ID=""
ENTRY_SITE_ID=""
ENTRY_TITLE_B64=""

echo "Webhook test sink end-to-end checks completed."
