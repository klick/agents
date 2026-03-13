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
FORCE_APPROVAL_MIGRATION_FILE="$PLUGIN_ROOT/src/migrations/m260307_150000_add_credential_force_human_approval_column.php"
USER_RELATION_MIGRATION_FILE="$PLUGIN_ROOT/src/migrations/m260313_160000_add_credential_user_relation_columns.php"
DESCRIPTION_MIGRATION_FILE="$PLUGIN_ROOT/src/migrations/m260313_180000_add_credential_description_column.php"
CP_TEMPLATE="$PLUGIN_ROOT/src/templates/credentials.twig"

expect_fixed "agents_credentials" "$MIGRATION_FILE" "Managed credentials migration defines credentials table"
expect_fixed "'tokenHash'" "$MIGRATION_FILE" "Managed credentials migration includes token hash column"
expect_fixed "pausedAt" "$PAUSE_MIGRATION_FILE" "Managed credentials pause migration defines pausedAt column"
expect_fixed "owner" "$OWNER_MIGRATION_FILE" "Managed credentials owner migration defines owner column"
expect_fixed "forceHumanApproval" "$FORCE_APPROVAL_MIGRATION_FILE" "Managed credentials force-human-approval migration defines column"
expect_fixed "ownerUserId" "$USER_RELATION_MIGRATION_FILE" "Managed credentials user-relation migration defines owner user column"
expect_fixed "approvalRecipientUserIds" "$USER_RELATION_MIGRATION_FILE" "Managed credentials user-relation migration defines approval recipient column"
expect_fixed "description" "$DESCRIPTION_MIGRATION_FILE" "Managed credentials description migration defines description column"
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
expect_fixed "supportsDescriptionColumn" "$CREDENTIAL_SERVICE" "CredentialService supports description column detection"
expect_fixed "supportsOwnerUserIdColumn" "$CREDENTIAL_SERVICE" "CredentialService supports owner user relation detection"
expect_fixed "supportsForceHumanApprovalColumn" "$CREDENTIAL_SERVICE" "CredentialService supports force-human-approval column detection"
expect_fixed "supportsApprovalRecipientUserIdsColumn" "$CREDENTIAL_SERVICE" "CredentialService supports approval-recipient relation detection"
expect_fixed "normalizeOwner" "$CREDENTIAL_SERVICE" "CredentialService normalizes owner values"
expect_fixed "getNotificationTargetsForHandle" "$CREDENTIAL_SERVICE" "CredentialService exposes account-specific notification target lookup"
expect_fixed "Unable to generate a secure API token because random_bytes() failed." "$CREDENTIAL_SERVICE" "CredentialService fails closed when secure token entropy is unavailable"

if grep -Fq "uniqid('', true) . microtime(true)" "$CREDENTIAL_SERVICE"; then
  fail "CredentialService must not fall back to uniqid/microtime token entropy"
fi
pass "CredentialService has no predictable token-entropy fallback"

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
expect_fixed "credentialOwnerLegacyValue" "$DASHBOARD_CONTROLLER" "Dashboard controller parses legacy owner fallback input"
expect_fixed "credentialDescription" "$DASHBOARD_CONTROLLER" "Dashboard controller parses credential description input"
expect_fixed "credentialOwnerUserId" "$DASHBOARD_CONTROLLER" "Dashboard controller parses credential owner relation input"
expect_fixed "credentialApprovalRecipientUserIds" "$DASHBOARD_CONTROLLER" "Dashboard controller parses approval recipient relation input"
expect_fixed "credentialForceHumanApproval" "$DASHBOARD_CONTROLLER" "Dashboard controller parses force-human-approval input"
expect_fixed "parseBooleanBodyParam('credentialForceHumanApproval', false)" "$DASHBOARD_CONTROLLER" "Force-human-approval parser defaults unchecked lightswitch to false"
expect_fixed "resolveCurrentCpUserId" "$DASHBOARD_CONTROLLER" "Dashboard controller resolves current CP user ID for owner prefill"
expect_fixed "resolveCurrentCpUserEmail" "$DASHBOARD_CONTROLLER" "Dashboard controller resolves current CP user email for owner prefill"
expect_fixed "requireCredentialPermission" "$DASHBOARD_CONTROLLER" "Dashboard controller enforces credential permissions"
expect_fixed "canCredentialPermission" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes credential permission checks"

expect_fixed "CredentialService::class" "$PLUGIN_FILE" "Plugin registers CredentialService component"
expect_fixed "'agents/accounts'" "$PLUGIN_FILE" "Plugin exposes CP credentials route/subnav"
expect_fixed "EVENT_REGISTER_PERMISSIONS" "$PLUGIN_FILE" "Plugin registers custom credential permissions"
expect_fixed "PERMISSION_CREDENTIALS_MANAGE" "$PLUGIN_FILE" "Plugin defines credential permission constants"
expect_fixed "<h1>Accounts</h1>" "$CP_TEMPLATE" "Accounts CP template is present"
expect_fixed "pause-credential" "$CP_TEMPLATE" "Accounts CP template supports pause action"
expect_fixed "resume-credential" "$CP_TEMPLATE" "Accounts CP template supports resume action"
expect_fixed "update-credential" "$CP_TEMPLATE" "Credentials CP template supports update action"
expect_fixed "delete-credential" "$CP_TEMPLATE" "Credentials CP template supports delete action"
expect_fixed "credentialDescription" "$CP_TEMPLATE" "Credentials CP template includes description input"
expect_fixed "credentialOwnerUserId" "$CP_TEMPLATE" "Credentials CP template includes owner relation input"
expect_fixed "credentialOwnerLegacyValue" "$CP_TEMPLATE" "Credentials CP template preserves legacy owner fallback"
expect_fixed "credentialApprovalRecipientUserIds" "$CP_TEMPLATE" "Credentials CP template includes approval recipient relation input"
expect_fixed "credentialForceHumanApproval" "$CP_TEMPLATE" "Credentials CP template includes force-human-approval controls"
expect_fixed "Account Templates" "$CP_TEMPLATE" "Credentials CP template exposes dedicated account templates section"
expect_fixed "agents-capability-group-title" "$CP_TEMPLATE" "Credentials CP template groups scopes by type"
expect_fixed "scope in ['incidents:read', 'syncstate:read', 'syncstate:write', 'webhooks:dlq:read', 'webhooks:dlq:replay']" "$CP_TEMPLATE" "Credentials CP template force-adds canonical sync-state and webhook DLQ scopes into the picker"
expect_fixed "simulatePulseMode" "$CP_TEMPLATE" "Credentials CP template supports pulse simulation mode query parameter"
expect_fixed "simulatePulseAccounts" "$CP_TEMPLATE" "Credentials CP template supports targeted pulse simulation accounts"
expect_fixed "simulatePulseIntervalMs" "$CP_TEMPLATE" "Credentials CP template supports pulse simulation interval override"
expect_fixed "normalizeSimulationStartOptions" "$CP_TEMPLATE" "Credentials CP template normalizes pulse simulation options"
expect_fixed "credentialIdsByHandle" "$CP_TEMPLATE" "Credentials CP template can target pulse simulation by account handle"
expect_fixed "agents-agent-token-overlay" "$CP_TEMPLATE" "Credentials CP template renders the account-scoped token overlay"
expect_fixed "revealedCredentialOverlayClose" "$CP_TEMPLATE" "Credentials CP template includes a close control for the token overlay"

echo "Credential lifecycle regression checks completed."
