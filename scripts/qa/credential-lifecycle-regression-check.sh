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
PAUSE_MIGRATION_FILE="$PLUGIN_ROOT/src/migrations/m260305_110000_add_credential_pause_column.php"
OWNER_MIGRATION_FILE="$PLUGIN_ROOT/src/migrations/m260306_100000_add_credential_owner_column.php"
CP_TEMPLATE="$PLUGIN_ROOT/src/templates/credentials.twig"

expect_fixed "agents_credentials" "$MIGRATION_FILE" "Managed credentials migration defines credentials table"
expect_fixed "'tokenHash'" "$MIGRATION_FILE" "Managed credentials migration includes token hash column"
expect_fixed "pausedAt" "$PAUSE_MIGRATION_FILE" "Managed credentials pause migration defines pausedAt column"
expect_fixed "owner" "$OWNER_MIGRATION_FILE" "Managed credentials owner migration defines owner column"
expect_fixed "class CredentialService extends Component" "$CREDENTIAL_SERVICE" "CredentialService class exists"
expect_fixed "createManagedCredential" "$CREDENTIAL_SERVICE" "CredentialService supports credential creation"
expect_fixed "updateManagedCredential" "$CREDENTIAL_SERVICE" "CredentialService supports credential profile/scope updates"
expect_fixed "rotateManagedCredential" "$CREDENTIAL_SERVICE" "CredentialService supports credential rotation"
expect_fixed "revokeManagedCredential" "$CREDENTIAL_SERVICE" "CredentialService supports credential revocation"
expect_fixed "deleteManagedCredential" "$CREDENTIAL_SERVICE" "CredentialService supports credential deletion"
expect_fixed "recordCredentialUse" "$CREDENTIAL_SERVICE" "CredentialService tracks last-used metadata"
expect_fixed "pauseManagedCredential" "$CREDENTIAL_SERVICE" "CredentialService supports reversible pause"
expect_fixed "resumeManagedCredential" "$CREDENTIAL_SERVICE" "CredentialService supports reversible resume"
expect_fixed "supportsOwnerColumn" "$CREDENTIAL_SERVICE" "CredentialService supports owner column detection"
expect_fixed "normalizeOwner" "$CREDENTIAL_SERVICE" "CredentialService normalizes owner values"

expect_fixed "getManagedCredentialsForRuntime" "$SECURITY_SERVICE" "Security policy consumes managed credentials for runtime auth"
expect_fixed "managedCredentialCount" "$SECURITY_SERVICE" "Security posture exposes managed credential counts"
expect_fixed "recordCredentialUse" "$API_CONTROLLER" "API auth path records managed credential usage"

expect_fixed "actionCredentials" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes credentials tab"
expect_fixed "actionCreateCredential" "$DASHBOARD_CONTROLLER" "Dashboard controller supports credential creation action"
expect_fixed "actionUpdateCredential" "$DASHBOARD_CONTROLLER" "Dashboard controller supports credential update action"
expect_fixed "actionRotateCredential" "$DASHBOARD_CONTROLLER" "Dashboard controller supports credential rotation action"
expect_fixed "actionRevokeCredential" "$DASHBOARD_CONTROLLER" "Dashboard controller supports credential revoke action"
expect_fixed "actionDeleteCredential" "$DASHBOARD_CONTROLLER" "Dashboard controller supports credential delete action"
expect_fixed "actionPauseCredential" "$DASHBOARD_CONTROLLER" "Dashboard controller supports agent pause action"
expect_fixed "actionResumeCredential" "$DASHBOARD_CONTROLLER" "Dashboard controller supports agent resume action"
expect_fixed "credentialOwner" "$DASHBOARD_CONTROLLER" "Dashboard controller parses credential owner input"
expect_fixed "resolveCurrentCpUserEmail" "$DASHBOARD_CONTROLLER" "Dashboard controller resolves current CP user email for owner prefill"
expect_fixed "requireCredentialPermission" "$DASHBOARD_CONTROLLER" "Dashboard controller enforces credential permissions"
expect_fixed "canCredentialPermission" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes credential permission checks"

expect_fixed "CredentialService::class" "$PLUGIN_FILE" "Plugin registers CredentialService component"
expect_fixed "'agents/credentials'" "$PLUGIN_FILE" "Plugin exposes CP credentials route/subnav"
expect_fixed "EVENT_REGISTER_PERMISSIONS" "$PLUGIN_FILE" "Plugin registers custom credential permissions"
expect_fixed "PERMISSION_CREDENTIALS_MANAGE" "$PLUGIN_FILE" "Plugin defines credential permission constants"
expect_fixed "<h1>Accounts</h1>" "$CP_TEMPLATE" "Accounts CP template is present"
expect_fixed "pause-credential" "$CP_TEMPLATE" "Accounts CP template supports pause action"
expect_fixed "resume-credential" "$CP_TEMPLATE" "Accounts CP template supports resume action"
expect_fixed "update-credential" "$CP_TEMPLATE" "Credentials CP template supports update action"
expect_fixed "delete-credential" "$CP_TEMPLATE" "Credentials CP template supports delete action"
expect_fixed "credentialOwner" "$CP_TEMPLATE" "Credentials CP template includes owner input"

echo "Credential lifecycle regression checks completed."
