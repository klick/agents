#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

BASE_URL="${BASE_URL:-}"
TOKEN="${TOKEN:-}"

fail() {
  echo "FAIL: $1"
  exit 1
}

pass() {
  echo "PASS: $1"
}

cd "$PLUGIN_ROOT"

echo "[1/7] Composer metadata validation"
composer validate --no-check-all --no-check-publish >/dev/null
pass "composer.json validates"

echo "[2/7] PHP syntax lint"
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || fail "PHP lint failed: $file"
done < <(find src -name "*.php" -print0)
pass "All PHP files lint clean"

echo "[3/7] Version consistency"
composer_version="$(php -r '$j=json_decode(file_get_contents("composer.json"), true); echo $j["version"] ?? "";')"
readme_version="$(sed -n 's/^Current plugin version: \*\*\([^*]*\)\*\*$/\1/p' README.md | head -n1)"

if [[ -z "$composer_version" || -z "$readme_version" ]]; then
  fail "Could not parse versions from composer.json and README.md"
fi

if [[ "$composer_version" != "$readme_version" ]]; then
  fail "Version mismatch (composer.json=$composer_version, README.md=$readme_version)"
fi
pass "Version references match ($composer_version)"

echo "[4/7] Required endpoint docs present"
if ! grep -q "Base URL (this project):" README.md; then
  fail "README is missing the API base URL declaration"
fi

for route in "GET /health" "GET /readiness" "GET /products" "GET /capabilities" "GET /openapi.json"; do
  if ! grep -q "$route" README.md; then
    fail "Missing endpoint in README: $route"
  fi
done
pass "README documents required endpoints"

echo "[5/7] Webhook contract regression check"
"$PLUGIN_ROOT/scripts/qa/webhook-regression-check.sh" >/dev/null
pass "Webhook contract regression checks pass"

echo "[6/7] Credential lifecycle regression check"
"$PLUGIN_ROOT/scripts/qa/credential-lifecycle-regression-check.sh" >/dev/null
pass "Credential lifecycle regression checks pass"

echo "[7/7] Optional live regression checks"
if [[ -n "$BASE_URL" && -n "$TOKEN" ]]; then
  "$PLUGIN_ROOT/scripts/security-regression-check.sh" "$BASE_URL" "$TOKEN"
  "$PLUGIN_ROOT/scripts/qa/incremental-regression-check.sh" "$BASE_URL" "$TOKEN"
  pass "Security + incremental regression checks passed against $BASE_URL"
else
  echo "SKIP: set BASE_URL and TOKEN to run live regression checks"
fi

echo "Release gate checks complete."
