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

echo "[1/23] Composer metadata validation"
composer validate --no-check-all --no-check-publish >/dev/null
pass "composer.json validates"

echo "[2/23] PHP syntax lint"
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || fail "PHP lint failed: $file"
done < <(find src -name "*.php" -print0)
pass "All PHP files lint clean"

echo "[3/23] Version consistency"
composer_version="$(php -r '$j=json_decode(file_get_contents("composer.json"), true); echo $j["version"] ?? "";')"
readme_version="$(sed -n 's/^Current plugin version: \*\*\([^*]*\)\*\*$/\1/p' README.md | head -n1)"

if [[ -z "$composer_version" || -z "$readme_version" ]]; then
  fail "Could not parse versions from composer.json and README.md"
fi

if [[ "$composer_version" != "$readme_version" ]]; then
  fail "Version mismatch (composer.json=$composer_version, README.md=$readme_version)"
fi
pass "Version references match ($composer_version)"

echo "[4/23] Contract parity checks"
"$PLUGIN_ROOT/scripts/qa/contract-parity-check.sh" >/dev/null
pass "API/scope/docs contract parity checks pass"

echo "[5/23] External adapter regression check"
bash "$PLUGIN_ROOT/scripts/qa/external-adapters-regression-check.sh" >/dev/null
pass "External adapter regression checks pass"

echo "[6/23] Optional Retour reference-adapter real-install check"
if [[ "${AGENTS_RUN_RETOUR_REAL_INSTALL:-0}" == "1" ]]; then
  bash "$PLUGIN_ROOT/scripts/qa/retour-adapter-real-install-check.sh"
  pass "Retour reference-adapter real-install check passed"
else
  echo "SKIP: set AGENTS_RUN_RETOUR_REAL_INSTALL=1 to run the real-install Retour adapter check"
fi

echo "[7/23] Deterministic validation regression check"
"$PLUGIN_ROOT/scripts/qa/validation-regression-check.sh" >/dev/null
pass "Validation regression checks pass"

echo "[8/23] Control/consumer regression check"
"$PLUGIN_ROOT/scripts/qa/control-consumer-regression-check.sh" >/dev/null
pass "Control and consumer regression checks pass"

echo "[9/23] Migration safety check"
"$PLUGIN_ROOT/scripts/qa/migration-safety-check.sh" >/dev/null
if grep -Eq "return '0\\.3\\.(0|3)'" "$PLUGIN_ROOT/src/services/ReadinessService.php" "$PLUGIN_ROOT/src/controllers/ApiController.php"; then
  fail "Stale plugin-version fallback detected in runtime services/controllers"
fi
pass "Migration safety checks pass"

echo "[10/23] README and docs references present"
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

if [[ ! -f "docs/api/external-plugin-adapters.md" ]]; then
  fail "Missing external adapter docs: docs/api/external-plugin-adapters.md"
fi

if [[ ! -f "adapters/retour/src/RetourExternalResourceProvider.php" ]]; then
  fail "Missing Retour reference adapter provider: adapters/retour/src/RetourExternalResourceProvider.php"
fi

if [[ ! -f "docs/troubleshooting/observability-runbook.md" ]]; then
  fail "Missing observability runbook: docs/troubleshooting/observability-runbook.md"
fi

if ! grep -q 'id="readinessStateCard"' src/templates/dashboard.twig; then
  fail "Dashboard is missing readiness state card"
fi

if ! grep -q 'id="readinessActionMappingSection"' src/templates/dashboard.twig; then
  fail "Dashboard is missing readiness action mapping section"
fi

pass "README and docs entry points are present"

echo "[11/23] Webhook contract regression check"
"$PLUGIN_ROOT/scripts/qa/webhook-regression-check.sh" >/dev/null
pass "Webhook contract regression checks pass"

echo "[12/23] Notification regression check"
"$PLUGIN_ROOT/scripts/qa/notification-regression-check.sh" >/dev/null
pass "Notification regression checks pass"

echo "[13/23] Reference automations/template regression check"
"$PLUGIN_ROOT/scripts/qa/reference-automations-regression-check.sh" >/dev/null
pass "Reference automations/template regression checks pass"

echo "[14/23] Starter-pack regression check"
"$PLUGIN_ROOT/scripts/qa/starter-packs-regression-check.sh" >/dev/null
pass "Starter-pack regression checks pass"

echo "[15/23] Reliability-pack regression check"
"$PLUGIN_ROOT/scripts/qa/reliability-pack-regression-check.sh" >/dev/null
pass "Reliability-pack regression checks pass"

echo "[16/23] Credential lifecycle regression check"
"$PLUGIN_ROOT/scripts/qa/credential-lifecycle-regression-check.sh" >/dev/null
pass "Credential lifecycle regression checks pass"

echo "[17/23] Lifecycle governance regression check"
"$PLUGIN_ROOT/scripts/qa/lifecycle-governance-regression-check.sh" >/dev/null
pass "Lifecycle governance regression checks pass"

echo "[18/23] Worker bootstrap regression check"
"$PLUGIN_ROOT/scripts/qa/worker-bootstrap-regression-check.sh" >/dev/null
pass "Worker bootstrap regression checks pass"

echo "[19/23] Workflow regression check"
bash "$PLUGIN_ROOT/scripts/qa/workflow-regression-check.sh" >/dev/null
pass "Workflow regression checks pass"

echo "[20/23] Entry draft worker regression check"
"$PLUGIN_ROOT/scripts/qa/entry-draft-worker-regression-check.sh" >/dev/null
pass "Entry draft worker regression checks pass"

echo "[21/23] Scope guide docs regression check"
"$PLUGIN_ROOT/scripts/qa/scope-guide-docs-regression-check.sh" >/dev/null
pass "Scope guide docs regression checks pass"

echo "[22/23] Optional notification smoke check"
if [[ "${AGENTS_RUN_NOTIFICATION_SMOKE:-0}" == "1" ]]; then
  "$PLUGIN_ROOT/scripts/qa/notification-smoke-check.sh"
  pass "Notification smoke checks passed"
else
  echo "SKIP: set AGENTS_RUN_NOTIFICATION_SMOKE=1 to run notification smoke checks"
fi

echo "[23/23] Optional live regression checks"
if [[ -n "$BASE_URL" && -n "$TOKEN" ]]; then
  "$PLUGIN_ROOT/scripts/security-regression-check.sh" "$BASE_URL" "$TOKEN"
  "$PLUGIN_ROOT/scripts/qa/incremental-regression-check.sh" "$BASE_URL" "$TOKEN"
  pass "Security + incremental regression checks passed against $BASE_URL"
else
  echo "SKIP: set BASE_URL and TOKEN to run live regression checks"
fi

echo "Release gate checks complete."
