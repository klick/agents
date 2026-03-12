#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./webhook-test-sink-common.sh
source "$SCRIPT_DIR/webhook-test-sink-common.sh"

cleanup() {
  cleanup_sink_server
}
trap cleanup EXIT

start_sink_server
run_sink_helper clear >/dev/null

health_json="$(run_sink_helper http-get "$WEBHOOK_TEST_SINK_URL")"
assert_json_eq "$health_json" "statusCode" "200" "Sink health route returns HTTP 200"
assert_json_eq "$health_json" "json.enabled" "true" "Sink health payload reports enabled state"
assert_json_eq "$health_json" "json.type" "webhook.test-sink" "Sink health payload reports test sink type"

button_json="$(run_sink_helper send-test-delivery)"
assert_json_eq "$button_json" "ok" "true" "One-click test delivery backend succeeds"
assert_json_eq "$button_json" "captured.verificationStatus" "valid" "One-click test delivery is captured as valid"

snapshot_json="$(run_sink_helper snapshot)"
assert_json_eq "$snapshot_json" "snapshot.summary.total" "1" "One-click test delivery increments captured total"
assert_json_eq "$snapshot_json" "snapshot.summary.valid" "1" "One-click test delivery increments valid total"

valid_json="$(run_sink_helper post-sample "$WEBHOOK_TEST_SINK_URL" valid evt_webhook_smoke_valid)"
assert_json_eq "$valid_json" "statusCode" "200" "Valid signed POST returns HTTP 200"
assert_json_eq "$valid_json" "json.captured.verificationStatus" "valid" "Valid signed POST is marked valid"

snapshot_json="$(run_sink_helper snapshot)"
assert_json_eq "$snapshot_json" "snapshot.summary.total" "2" "Snapshot tracks two captured events after valid POST"
assert_json_eq "$snapshot_json" "snapshot.summary.valid" "2" "Snapshot tracks two valid events after valid POST"
assert_json_eq "$snapshot_json" "snapshot.events.0.eventId" "evt_webhook_smoke_valid" "Valid sample is stored as the latest event"

invalid_json="$(run_sink_helper post-sample "$WEBHOOK_TEST_SINK_URL" invalid evt_webhook_smoke_invalid)"
assert_json_eq "$invalid_json" "statusCode" "200" "Invalid signed POST returns HTTP 200"
assert_json_eq "$invalid_json" "json.captured.verificationStatus" "invalid" "Invalid signed POST is marked invalid"

snapshot_json="$(run_sink_helper snapshot)"
assert_json_eq "$snapshot_json" "snapshot.summary.total" "3" "Snapshot total increments after invalid POST"
assert_json_eq "$snapshot_json" "snapshot.summary.invalid" "1" "Snapshot tracks one invalid event"
assert_json_eq "$snapshot_json" "snapshot.events.0.eventId" "evt_webhook_smoke_invalid" "Invalid sample becomes latest event"

unsigned_json="$(run_sink_helper post-sample "$WEBHOOK_TEST_SINK_URL" unsigned evt_webhook_smoke_unsigned)"
assert_json_eq "$unsigned_json" "statusCode" "200" "Unsigned POST returns HTTP 200"
assert_json_eq "$unsigned_json" "json.captured.verificationStatus" "unsigned" "Unsigned POST is marked unsigned"

snapshot_json="$(run_sink_helper snapshot)"
assert_json_eq "$snapshot_json" "snapshot.summary.total" "4" "Snapshot total increments after unsigned POST"
assert_json_eq "$snapshot_json" "snapshot.summary.unsigned" "1" "Snapshot tracks one unsigned event"
assert_json_eq "$snapshot_json" "snapshot.events.0.eventId" "evt_webhook_smoke_unsigned" "Unsigned sample becomes latest event"

clear_json="$(run_sink_helper clear)"
assert_json_eq "$clear_json" "deleted" "4" "Clear action removes all captured smoke events"

snapshot_json="$(run_sink_helper snapshot)"
assert_json_eq "$snapshot_json" "snapshot.summary.total" "0" "Snapshot resets to zero after clear"
assert_json_eq "$snapshot_json" "snapshot.summary.valid" "0" "Valid count resets to zero after clear"
assert_json_eq "$snapshot_json" "snapshot.summary.invalid" "0" "Invalid count resets to zero after clear"
assert_json_eq "$snapshot_json" "snapshot.summary.unsigned" "0" "Unsigned count resets to zero after clear"

echo "Webhook test sink smoke checks completed."
