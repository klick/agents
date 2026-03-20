#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CONTAINER_PLUGIN_ROOT="/var/www/html/plugins/agents"
CONTAINER_VENDOR_PLUGIN_ROOT="/var/www/html/vendor/klick/agents"
HELPER_PATH_REPO="$CONTAINER_PLUGIN_ROOT/scripts/qa/helpers/retour_adapter_real_install_harness.php"
HELPER_PATH_VENDOR="$CONTAINER_VENDOR_PLUGIN_ROOT/scripts/qa/helpers/retour_adapter_real_install_harness.php"
JSON_PATH_HELPER="$PLUGIN_ROOT/scripts/qa/helpers/json_path.php"
AGENTS_VERSION="$(php -r '$j=json_decode(file_get_contents("'"$PLUGIN_ROOT"'/composer.json"), true); echo $j["version"] ?? "";')"
AGENTS_VERSION_SERIES="${AGENTS_VERSION%.*}"
CORE_PACKAGE="klick/agents"
ADAPTER_PACKAGE="klick/agents-retour"
RETOUR_PACKAGE="nystudio107/craft-retour"
ADAPTER_REPOSITORY_KEY="klick-agents-retour"
CORE_REPOSITORY_KEY="klick-agents-core"
ADAPTER_PATH="plugins/agents/adapters/retour"
CORE_PATH="plugins/agents"
BASE_URL="${AGENTS_RETOUR_REAL_INSTALL_BASE_URL:-$(ddev describe -j | jq -r '.raw.primary_url // .raw["primary_url"] // empty')}"
BASE_URL="${BASE_URL%/}"

fail() {
  echo "FAIL: $1" >&2
  exit 1
}

pass() {
  echo "PASS: $1"
}

json_get() {
  local json="$1"
  local path="$2"

  printf '%s' "$json" | php "$JSON_PATH_HELPER" "$path"
}

run_helper() {
  ddev exec php "$HELPER_PATH" "$@"
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

http_json() {
  local token="$1"
  local url="$2"

  curl --max-time 20 -sS \
    -H "Accept: application/json" \
    -H "Authorization: Bearer $token" \
    "$url"
}

http_status() {
  local token="$1"
  local url="$2"

  curl --max-time 20 -sS \
    -o /tmp/agents-retour-real-install-body.json \
    -w '%{http_code}' \
    -H "Accept: application/json" \
    -H "Authorization: Bearer $token" \
    "$url"
}

ensure_package() {
  local package="$1"
  local require_spec="$2"
  shift 2
  local extra_args=()
  if [[ "$#" -gt 0 ]]; then
    extra_args=("$@")
  fi

  if ddev composer show "$package" >/dev/null 2>&1; then
    ddev composer update "$package" "${extra_args[@]}" --no-interaction >/dev/null
  else
    ddev composer require "$require_spec" -w "${extra_args[@]}" --no-interaction >/dev/null
  fi
}

if [[ -z "$BASE_URL" ]]; then
  fail "Unable to resolve the sandbox base URL from ddev describe."
fi

if ddev exec test -f "$HELPER_PATH_REPO" >/dev/null 2>&1; then
  HELPER_PATH="$HELPER_PATH_REPO"
elif ddev exec test -f "$HELPER_PATH_VENDOR" >/dev/null 2>&1; then
  HELPER_PATH="$HELPER_PATH_VENDOR"
else
  fail "Unable to locate retour_adapter_real_install_harness.php in repo or installed vendor paths."
fi

cleanup() {
  run_helper cleanup-fixtures >/dev/null 2>&1 || true
}

trap cleanup EXIT

ddev composer config repositories."$CORE_REPOSITORY_KEY" --json "{\"type\":\"path\",\"url\":\"$CORE_PATH\",\"options\":{\"symlink\":true}}" >/dev/null
ddev composer config repositories."$ADAPTER_REPOSITORY_KEY" --json "{\"type\":\"path\",\"url\":\"$ADAPTER_PATH\",\"options\":{\"symlink\":true}}" >/dev/null
ensure_package "$CORE_PACKAGE" "$CORE_PACKAGE:^$AGENTS_VERSION_SERIES" -W
ensure_package "$RETOUR_PACKAGE" "$RETOUR_PACKAGE"
ensure_package "$ADAPTER_PACKAGE" "$ADAPTER_PACKAGE:@dev"

SNAPSHOT_JSON="$(run_helper snapshot)"

if [[ "$(json_get "$SNAPSHOT_JSON" "plugins.retour.installed")" != "true" ]]; then
  ddev exec php craft plugin/install retour --interactive=0 >/dev/null
fi

SNAPSHOT_JSON="$(run_helper snapshot)"
if [[ "$(json_get "$SNAPSHOT_JSON" "plugins.agents-retour.installed")" != "true" ]]; then
  ddev exec php craft plugin/install agents-retour --interactive=0 >/dev/null
fi

SNAPSHOT_JSON="$(run_helper snapshot)"
assert_json_eq "$SNAPSHOT_JSON" "plugins.retour.installed" "true" "Retour is installed in Craft"
assert_json_eq "$SNAPSHOT_JSON" "plugins.agents-retour.installed" "true" "Retour adapter is installed in Craft"
assert_json_eq "$SNAPSHOT_JSON" "tables.retourRedirects" "true" "Retour redirects table exists"
assert_json_eq "$SNAPSHOT_JSON" "retourRedirectsResource.handle" "redirects" "Retour resource is registered in the external provider registry"
assert_json_eq "$SNAPSHOT_JSON" "retourRedirectsResource.scope" "plugins:retour:redirects:read" "Retour scope is exposed for Accounts/capabilities"

SEED_JSON="$(run_helper seed-redirect)"
REDIRECT_ID="$(json_get "$SEED_JSON" "redirect.id")"
if [[ -z "$REDIRECT_ID" || "$REDIRECT_ID" == "null" ]]; then
  fail "Retour redirect fixture was not created."
fi
pass "Retour redirect fixture is seeded"

CREDENTIALS_JSON="$(run_helper ensure-test-credentials)"
ALLOW_TOKEN="$(json_get "$CREDENTIALS_JSON" "credentials.allow.token")"
DENY_TOKEN="$(json_get "$CREDENTIALS_JSON" "credentials.deny.token")"
assert_json_contains "$CREDENTIALS_JSON" "plugins:retour:redirects:read" "Allow credential carries the Retour scope"

CAPABILITIES_JSON="$(http_json "$ALLOW_TOKEN" "$BASE_URL/agents/v1/capabilities")"
assert_json_contains "$CAPABILITIES_JSON" "plugins:retour:redirects:read" "Capabilities exposes the Retour scope"
assert_json_contains "$CAPABILITIES_JSON" "\"plugin\":\"retour\"" "Capabilities exposes the Retour provider"
assert_json_contains "$CAPABILITIES_JSON" "/plugins/retour/redirects" "Capabilities exposes the Retour list endpoint"

WHOAMI_JSON="$(http_json "$ALLOW_TOKEN" "$BASE_URL/agents/v1/auth/whoami")"
assert_json_contains "$WHOAMI_JSON" "plugins:retour:redirects:read" "auth/whoami returns the adapter-backed scope"

DENY_STATUS="$(http_status "$DENY_TOKEN" "$BASE_URL/agents/v1/plugins/retour/redirects")"
if [[ "$DENY_STATUS" != "403" ]]; then
  fail "Missing-scope request should be denied with 403 (got $DENY_STATUS)"
fi
pass "Retour list endpoint denies tokens without the adapter scope"

LIST_JSON="$(http_json "$ALLOW_TOKEN" "$BASE_URL/agents/v1/plugins/retour/redirects")"
assert_json_contains "$LIST_JSON" "\"sourceUrl\":\"/qa/f12-retour-source\"" "Retour list endpoint returns the seeded redirect"
assert_json_contains "$LIST_JSON" "\"destinationUrl\":\"/qa/f12-retour-destination\"" "Retour list payload includes destination URL"

DETAIL_JSON="$(http_json "$ALLOW_TOKEN" "$BASE_URL/agents/v1/plugins/retour/redirects/$REDIRECT_ID")"
assert_json_contains "$DETAIL_JSON" "\"id\":$REDIRECT_ID" "Retour detail endpoint returns the requested redirect"
assert_json_contains "$DETAIL_JSON" "\"raw\"" "Retour detail payload exposes raw redirect data"

echo "Retour real-install adapter check complete."
