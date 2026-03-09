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

echo "[1/15] Composer metadata validation"
composer validate --no-check-all --no-check-publish >/dev/null
pass "composer.json validates"

echo "[2/15] PHP syntax lint"
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || fail "PHP lint failed: $file"
done < <(find src -name "*.php" -print0)
pass "All PHP files lint clean"

echo "[3/15] Version consistency"
composer_version="$(php -r '$j=json_decode(file_get_contents("composer.json"), true); echo $j["version"] ?? "";')"
readme_version="$(sed -n 's/^Current plugin version: \*\*\([^*]*\)\*\*$/\1/p' README.md | head -n1)"

if [[ -z "$composer_version" || -z "$readme_version" ]]; then
  fail "Could not parse versions from composer.json and README.md"
fi

if [[ "$composer_version" != "$readme_version" ]]; then
  fail "Version mismatch (composer.json=$composer_version, README.md=$readme_version)"
fi
pass "Version references match ($composer_version)"

echo "[4/15] Contract parity checks"
"$PLUGIN_ROOT/scripts/qa/contract-parity-check.sh" >/dev/null
pass "API/scope/docs contract parity checks pass"

echo "[5/15] Deterministic validation regression check"
"$PLUGIN_ROOT/scripts/qa/validation-regression-check.sh" >/dev/null
pass "Validation regression checks pass"

echo "[6/15] Control/consumer regression check"
"$PLUGIN_ROOT/scripts/qa/control-consumer-regression-check.sh" >/dev/null
pass "Control and consumer regression checks pass"

echo "[7/15] Migration safety check"
"$PLUGIN_ROOT/scripts/qa/migration-safety-check.sh" >/dev/null
if grep -Eq "return '0\\.3\\.(0|3)'" "$PLUGIN_ROOT/src/services/ReadinessService.php" "$PLUGIN_ROOT/src/controllers/ApiController.php"; then
  fail "Stale plugin-version fallback detected in runtime services/controllers"
fi
pass "Migration safety checks pass"

echo "[8/15] README and docs references present"
if ! grep -q "Public docs: https://marcusscheller.com/docs/agents/" README.md; then
  fail "README is missing the public docs link"
fi

if ! grep -q '^/\.tmp/' .gitignore; then
  fail "Missing /.tmp ignore rule in .gitignore"
fi

if git ls-files -- '.tmp' '.tmp/*' | grep -q '.'; then
  fail "Tracked .tmp artifacts detected; /.tmp must remain hidden from release surfaces"
fi

if ! grep -q "composer require klick/agents" README.md; then
  fail "README is missing the composer install command"
fi

if [[ ! -f "docs/api/endpoints.md" ]]; then
  fail "Missing API endpoints docs: docs/api/endpoints.md"
fi

if [[ ! -f "docs/api/auth-and-scopes.md" ]]; then
  fail "Missing auth/scopes docs: docs/api/auth-and-scopes.md"
fi

if [[ ! -f "docs/troubleshooting/observability-runbook.md" ]]; then
  fail "Missing observability runbook: docs/troubleshooting/observability-runbook.md"
fi

if ! grep -q "Runbook & Alert Guidance" src/templates/dashboard.twig; then
  fail "Dashboard is missing Runbook & Alert Guidance section"
fi

pass "README and docs entry points are present"

echo "[9/15] Webhook contract regression check"
"$PLUGIN_ROOT/scripts/qa/webhook-regression-check.sh" >/dev/null
pass "Webhook contract regression checks pass"

echo "[10/15] Reference automations/template regression check"
"$PLUGIN_ROOT/scripts/qa/reference-automations-regression-check.sh" >/dev/null
pass "Reference automations/template regression checks pass"

echo "[11/15] Starter-pack regression check"
"$PLUGIN_ROOT/scripts/qa/starter-packs-regression-check.sh" >/dev/null
pass "Starter-pack regression checks pass"

echo "[12/15] Reliability-pack regression check"
"$PLUGIN_ROOT/scripts/qa/reliability-pack-regression-check.sh" >/dev/null
pass "Reliability-pack regression checks pass"

echo "[13/15] Credential lifecycle regression check"
"$PLUGIN_ROOT/scripts/qa/credential-lifecycle-regression-check.sh" >/dev/null
pass "Credential lifecycle regression checks pass"

echo "[14/15] Lifecycle governance regression check"
"$PLUGIN_ROOT/scripts/qa/lifecycle-governance-regression-check.sh" >/dev/null
pass "Lifecycle governance regression checks pass"

echo "[15/15] Optional live regression checks"
if [[ -n "$BASE_URL" && -n "$TOKEN" ]]; then
  "$PLUGIN_ROOT/scripts/security-regression-check.sh" "$BASE_URL" "$TOKEN"
  "$PLUGIN_ROOT/scripts/qa/incremental-regression-check.sh" "$BASE_URL" "$TOKEN"
  pass "Security + incremental regression checks passed against $BASE_URL"
else
  echo "SKIP: set BASE_URL and TOKEN to run live regression checks"
fi

echo "Release gate checks complete."
