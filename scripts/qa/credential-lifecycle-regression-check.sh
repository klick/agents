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

CREDENTIAL_SERVICE="$PLUGIN_ROOT/src/services/CredentialService.php"
SECURITY_SERVICE="$PLUGIN_ROOT/src/services/SecurityPolicyService.php"
API_CONTROLLER="$PLUGIN_ROOT/src/controllers/ApiController.php"
DASHBOARD_CONTROLLER="$PLUGIN_ROOT/src/controllers/DashboardController.php"
PLUGIN_FILE="$PLUGIN_ROOT/src/Plugin.php"
MIGRATION_FILE="$PLUGIN_ROOT/src/migrations/m260226_180000_add_agents_credentials_table.php"
CP_TEMPLATE="$PLUGIN_ROOT/src/templates/credentials.twig"

expect_fixed "agents_credentials" "$MIGRATION_FILE" "Managed credentials migration defines credentials table"
expect_fixed "'tokenHash'" "$MIGRATION_FILE" "Managed credentials migration includes token hash column"
expect_fixed "class CredentialService extends Component" "$CREDENTIAL_SERVICE" "CredentialService class exists"
expect_fixed "createManagedCredential" "$CREDENTIAL_SERVICE" "CredentialService supports credential creation"
expect_fixed "rotateManagedCredential" "$CREDENTIAL_SERVICE" "CredentialService supports credential rotation"
expect_fixed "revokeManagedCredential" "$CREDENTIAL_SERVICE" "CredentialService supports credential revocation"
expect_fixed "recordCredentialUse" "$CREDENTIAL_SERVICE" "CredentialService tracks last-used metadata"

expect_fixed "getManagedCredentialsForRuntime" "$SECURITY_SERVICE" "Security policy consumes managed credentials for runtime auth"
expect_fixed "managedCredentialCount" "$SECURITY_SERVICE" "Security posture exposes managed credential counts"
expect_fixed "recordCredentialUse" "$API_CONTROLLER" "API auth path records managed credential usage"

expect_fixed "actionCredentials" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes credentials tab"
expect_fixed "actionCreateCredential" "$DASHBOARD_CONTROLLER" "Dashboard controller supports credential creation action"
expect_fixed "actionRotateCredential" "$DASHBOARD_CONTROLLER" "Dashboard controller supports credential rotation action"
expect_fixed "actionRevokeCredential" "$DASHBOARD_CONTROLLER" "Dashboard controller supports credential revoke action"

expect_fixed "CredentialService::class" "$PLUGIN_FILE" "Plugin registers CredentialService component"
expect_fixed "'agents/credentials'" "$PLUGIN_FILE" "Plugin exposes CP credentials route/subnav"
expect_fixed "Manage CP-controlled API credentials" "$CP_TEMPLATE" "Credentials CP template is present"

echo "Credential lifecycle regression checks completed."
