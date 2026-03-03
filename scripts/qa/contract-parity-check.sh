#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

API_CONTROLLER="$PLUGIN_ROOT/src/controllers/ApiController.php"
PLUGIN_FILE="$PLUGIN_ROOT/src/Plugin.php"
README_FILE="$PLUGIN_ROOT/README.md"

fail() {
  echo "FAIL: $1"
  exit 1
}

pass() {
  echo "PASS: $1"
}

require_file() {
  local file="$1"
  [[ -f "$file" ]] || fail "Missing required file: $file"
}

compare_sets() {
  local left="$1"
  local right="$2"
  local label="$3"
  local diff_file="$TMP_DIR/diff.txt"

  if ! diff -u "$left" "$right" >"$diff_file"; then
    echo "FAIL: $label"
    cat "$diff_file"
    exit 1
  fi
  pass "$label"
}

ensure_subset() {
  local subset="$1"
  local superset="$2"
  local label="$3"
  local missing_file="$TMP_DIR/missing.txt"

  comm -23 "$subset" "$superset" >"$missing_file" || true
  if [[ -s "$missing_file" ]]; then
    echo "FAIL: $label"
    echo "Missing values:"
    cat "$missing_file"
    exit 1
  fi
  pass "$label"
}

require_file "$API_CONTROLLER"
require_file "$PLUGIN_FILE"
require_file "$README_FILE"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

capabilities_endpoints="$TMP_DIR/capabilities-endpoints.txt"
openapi_endpoints="$TMP_DIR/openapi-endpoints.txt"
readme_endpoints="$TMP_DIR/readme-endpoints.txt"
capabilities_paths="$TMP_DIR/capabilities-paths.txt"
routes_paths="$TMP_DIR/routes-paths.txt"
available_scopes="$TMP_DIR/available-scopes.txt"
endpoint_scopes="$TMP_DIR/endpoint-scopes.txt"
readme_scopes="$TMP_DIR/readme-scopes.txt"

perl -ne 'if (/\x27method\x27\s*=>\s*\x27([A-Z]+)\x27.*\x27path\x27\s*=>\s*\x27(\/[^'"'"']+)\x27/) { print "$1 $2\n"; }' \
  "$API_CONTROLLER" | LC_ALL=C sort -u >"$capabilities_endpoints"

perl -ne 'if (/^\s*\x27(\/[^'"'"']+)\x27\s*=>\s*\[\x27(get|post)\x27\s*=>/i) { print uc($2)." ".$1."\n"; }' \
  "$API_CONTROLLER" | LC_ALL=C sort -u >"$openapi_endpoints"

awk '
  /^### Endpoints$/ { in_section=1; next }
  /^### Scope catalog$/ { in_section=0 }
  in_section { print }
' "$README_FILE" \
  | perl -ne 'if (/\`\s*(GET|POST)\s+([^` ]+)\s*\`/) { print "$1 $2\n"; }' \
  | LC_ALL=C sort -u >"$readme_endpoints"

cut -d' ' -f2 "$capabilities_endpoints" | LC_ALL=C sort -u >"$capabilities_paths"

{
  perl -ne 'if (/\x27agents\/v1(\/[^'"'"']+)\x27\s*=>/) { print "$1\n"; }' "$PLUGIN_FILE"
  perl -ne 'if (/^\s*\x27(llms(?:-full)?\.txt|commerce\.txt)\x27\s*=>/) { print "/$1\n"; }' "$PLUGIN_FILE"
} | LC_ALL=C sort -u >"$routes_paths"

awk '
  /private function availableScopes\(\): array/ { in_section=1; next }
  /private function isRefundApprovalsExperimentalEnabled\(\): bool/ { in_section=0 }
  in_section { print }
' "$API_CONTROLLER" \
  | perl -ne 'while (/\x27([a-z0-9:_]+)\x27\s*=>/g) { print "$1\n"; }' \
  | LC_ALL=C sort -u >"$available_scopes"

perl -0777 -ne '
  while (/\x27(?:requiredScopes|optionalScopes|x-required-scopes|x-optional-scopes)\x27\s*=>\s*\[(.*?)\]/sg) {
    my $block = $1;
    while ($block =~ /\x27([a-z0-9:_]+)\x27/g) {
      print "$1\n";
    }
  }
' "$API_CONTROLLER" | LC_ALL=C sort -u >"$endpoint_scopes"

awk '
  /^### Scope catalog$/ { in_section=1; next }
  /^## CLI Commands$/ { in_section=0 }
  in_section { print }
' "$README_FILE" \
  | perl -ne 'if (/-\s+`([a-z0-9:_]+)`/) { print "$1\n"; }' \
  | LC_ALL=C sort -u >"$readme_scopes"

compare_sets "$capabilities_endpoints" "$openapi_endpoints" "Capabilities and OpenAPI endpoint method/path contracts are aligned"
compare_sets "$capabilities_endpoints" "$readme_endpoints" "Capabilities and README endpoint method/path contracts are aligned"
compare_sets "$capabilities_paths" "$routes_paths" "Capabilities endpoint paths and registered site routes are aligned"
compare_sets "$available_scopes" "$readme_scopes" "Available scopes and README scope catalog are aligned"
ensure_subset "$endpoint_scopes" "$available_scopes" "Endpoint-required scopes are defined in available scope catalog"
ensure_subset "$endpoint_scopes" "$readme_scopes" "Endpoint-required scopes are documented in README scope catalog"

echo "Contract parity checks complete."
