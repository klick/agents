#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CONTAINER_PLUGIN_ROOT="/var/www/html/plugins/agents"
CONTAINER_SITE_ROOT="/var/www/html"
HELPER_PATH="$CONTAINER_PLUGIN_ROOT/scripts/qa/helpers/webhook_test_sink_harness.php"
WEBHOOK_TEST_SINK_PORT="${WEBHOOK_TEST_SINK_PORT:-18091}"
WEBHOOK_TEST_SINK_URL="http://127.0.0.1:${WEBHOOK_TEST_SINK_PORT}/agents/dev/webhook-test-sink"
WEBHOOK_TEST_SINK_SECRET="${WEBHOOK_TEST_SINK_SECRET:-agents-webhook-test-sink-secret}"
WEBHOOK_TEST_SINK_LOG="/tmp/agents-webhook-test-sink-${WEBHOOK_TEST_SINK_PORT}.log"
WEBHOOK_TEST_SINK_PID=""

fail() {
  echo "FAIL: $1" >&2
  exit 1
}

pass() {
  echo "PASS: $1"
}

run_sink_helper() {
  ddev exec env \
    PLUGIN_AGENTS_WEBHOOK_TEST_SINK=true \
    PLUGIN_AGENTS_WEBHOOK_SECRET="$WEBHOOK_TEST_SINK_SECRET" \
    PLUGIN_AGENTS_WEBHOOK_URL="$WEBHOOK_TEST_SINK_URL" \
    php "$HELPER_PATH" "$@"
}

run_plain_helper() {
  ddev exec php "$HELPER_PATH" "$@"
}

start_sink_server() {
  local pid
  pid="$(ddev exec sh -lc "cd '$CONTAINER_SITE_ROOT' && export PLUGIN_AGENTS_WEBHOOK_TEST_SINK=true PLUGIN_AGENTS_WEBHOOK_SECRET='$WEBHOOK_TEST_SINK_SECRET' PLUGIN_AGENTS_WEBHOOK_URL='$WEBHOOK_TEST_SINK_URL' && php -S 127.0.0.1:$WEBHOOK_TEST_SINK_PORT -t web web/index.php >'$WEBHOOK_TEST_SINK_LOG' 2>&1 & jobs -p")"
  pid="$(printf '%s' "$pid" | tr -d '\r' | tail -n 1 | xargs)"
  if [[ -z "$pid" ]]; then
    fail "Unable to start temporary webhook test sink server"
  fi

  WEBHOOK_TEST_SINK_PID="$pid"
  wait_for_sink_server
}

wait_for_sink_server() {
  local attempt output
  for attempt in $(seq 1 30); do
    if output="$(run_sink_helper http-get "$WEBHOOK_TEST_SINK_URL" 2>/dev/null || true)"; then
      if [[ "$output" == *'"statusCode":200'* ]] && [[ "$output" == *'"type":"webhook.test-sink"'* ]]; then
        return
      fi
    fi
    sleep 1
  done

  cleanup_sink_server
  fail "Webhook test sink server did not become healthy"
}

cleanup_sink_server() {
  run_sink_helper clear >/dev/null 2>&1 || true

  if [[ -n "$WEBHOOK_TEST_SINK_PID" ]]; then
    ddev exec kill "$WEBHOOK_TEST_SINK_PID" >/dev/null 2>&1 || true
    WEBHOOK_TEST_SINK_PID=""
  fi
}

json_get() {
  local json="$1"
  local path="$2"

  printf '%s' "$json" | ddev exec php "$CONTAINER_PLUGIN_ROOT/scripts/qa/helpers/json_path.php" "$path"
}

assert_json_eq() {
  local json="$1"
  local path="$2"
  local expected="$3"
  local description="$4"
  local actual
  actual="$(json_get "$json" "$path")"
  if [[ "$actual" == "$expected" ]]; then
    pass "$description"
    return
  fi
  fail "$description (expected '$expected', got '$actual')"
}

assert_json_contains() {
  local json="$1"
  local needle="$2"
  local description="$3"
  if printf '%s' "$json" | grep -Fq "$needle"; then
    pass "$description"
    return
  fi
  fail "$description (missing '$needle')"
}
