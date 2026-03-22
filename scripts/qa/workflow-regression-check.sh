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
  if grep -Fq -- "$needle" "$file"; then
    pass "$description"
    return
  fi
  fail "$description (missing: $needle in $file)"
}

expect_absent() {
  local needle="$1"
  local file="$2"
  local description="$3"
  if grep -Fq -- "$needle" "$file"; then
    fail "$description (unexpected: $needle in $file)"
  fi
  pass "$description"
}

PLUGIN_FILE="$PLUGIN_ROOT/src/Plugin.php"
DASHBOARD_CONTROLLER="$PLUGIN_ROOT/src/controllers/DashboardController.php"
WORKFLOW_SERVICE="$PLUGIN_ROOT/src/services/WorkflowService.php"
WORKFLOW_TEMPLATE="$PLUGIN_ROOT/src/templates/workflows.twig"
WORKFLOW_MIGRATION="$PLUGIN_ROOT/src/migrations/m260322_120000_add_workflows_tables.php"
WORKFLOW_NULLABLE_TARGET_SET_MIGRATION="$PLUGIN_ROOT/src/migrations/m260322_141500_make_workflow_target_set_nullable.php"
CP_DOCS="$PLUGIN_ROOT/docs/cp/index.md"
CP_UI_PARTIAL="$PLUGIN_ROOT/src/templates/_partials/cp-ui.twig"
MANAGED_ACCOUNT_PARTIAL="$PLUGIN_ROOT/src/templates/_partials/managed-account-ref.twig"

expect_fixed "PERMISSION_WORKFLOWS_VIEW" "$PLUGIN_FILE" "Plugin defines workflow view permission"
expect_fixed "PERMISSION_WORKFLOWS_MANAGE" "$PLUGIN_FILE" "Plugin defines workflow manage permission"
expect_fixed "'workflows' => [" "$PLUGIN_FILE" "Plugin registers Workflows subnav"
expect_fixed "'agents/workflows' => 'agents/dashboard/workflows'" "$PLUGIN_FILE" "Plugin registers workflows CP route"
expect_fixed "'agents/workflows/<workflowId:\\\\d+>' => 'agents/dashboard/workflows'" "$PLUGIN_FILE" "Plugin registers workflow detail CP route"

expect_fixed "class WorkflowService extends Component" "$WORKFLOW_SERVICE" "Workflow service class exists"
expect_fixed "getWorkflowTemplates" "$WORKFLOW_SERVICE" "Workflow service exposes template definitions"
expect_fixed "getWorkflows" "$WORKFLOW_SERVICE" "Workflow service exposes workflow registry"
expect_fixed "getWorkflowRuns" "$WORKFLOW_SERVICE" "Workflow service exposes run history lookup"
expect_fixed "buildWorkflowAttentionState" "$WORKFLOW_SERVICE" "Workflow service derives workflow attention summaries for the CP"
expect_fixed "attentionSummary" "$WORKFLOW_SERVICE" "Workflow service exposes workflow attention summary text"
expect_fixed "attentionMeta" "$WORKFLOW_SERVICE" "Workflow service exposes workflow attention detail text"
expect_fixed "buildBootstrapBundleFiles" "$WORKFLOW_SERVICE" "Workflow service exposes bootstrap bundle generation"
expect_fixed "buildBundleReadme(array \$workflow, array \$template, string \$workflowSlug)" "$WORKFLOW_SERVICE" "Workflow service passes workflow slug explicitly into bundle README generation"
expect_fixed "return <<<'BASH'" "$WORKFLOW_SERVICE" "Workflow service keeps bundle run script shell variables literal via nowdoc"
expect_fixed "buildOutputContract" "$WORKFLOW_SERVICE" "Workflow service includes explicit workflow output-contract guidance"
expect_fixed "output-contract.md" "$WORKFLOW_SERVICE" "Workflow service bundles an output-contract file"
expect_fixed "content-quality-review" "$WORKFLOW_SERVICE" "Workflow service includes the content quality review template"
expect_fixed "legal-consent-review" "$WORKFLOW_SERVICE" "Workflow service includes the legal and consent review template"
expect_fixed "change-monitor" "$WORKFLOW_SERVICE" "Workflow service includes the change monitor template"
expect_fixed "launch-readiness-review" "$WORKFLOW_SERVICE" "Workflow service includes the launch readiness review template"
expect_fixed "'mode' => 'read-only'" "$WORKFLOW_SERVICE" "Workflow service marks managed templates as read-only"

expect_fixed "agents_workflows" "$WORKFLOW_MIGRATION" "Workflow migration defines workflows table"
expect_fixed "agents_workflow_runs" "$WORKFLOW_MIGRATION" "Workflow migration defines workflow runs table"
expect_fixed "make_workflow_target_set_nullable" "$WORKFLOW_NULLABLE_TARGET_SET_MIGRATION" "Workflow nullable target-set migration exists"

expect_fixed "actionWorkflows" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes workflows page"
expect_fixed "actionCreateWorkflow" "$DASHBOARD_CONTROLLER" "Dashboard controller supports workflow creation"
expect_fixed "actionUpdateWorkflow" "$DASHBOARD_CONTROLLER" "Dashboard controller supports workflow updates"
expect_fixed "actionPauseWorkflow" "$DASHBOARD_CONTROLLER" "Dashboard controller supports workflow pause"
expect_fixed "actionResumeWorkflow" "$DASHBOARD_CONTROLLER" "Dashboard controller supports workflow resume"
expect_fixed "actionDuplicateWorkflow" "$DASHBOARD_CONTROLLER" "Dashboard controller supports workflow duplication"
expect_fixed "actionDeleteWorkflow" "$DASHBOARD_CONTROLLER" "Dashboard controller supports workflow deletion"
expect_fixed "actionDownloadWorkflowBundle" "$DASHBOARD_CONTROLLER" "Dashboard controller supports workflow bundle download"
expect_fixed "sendBundleArchive" "$DASHBOARD_CONTROLLER" "Dashboard controller centralizes workflow handoff archive delivery"
expect_fixed "buildWorkflowPayloadFromRequest" "$DASHBOARD_CONTROLLER" "Dashboard controller builds workflow payloads from POST data"
expect_fixed "buildAvailableWorkflowAccounts" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes eligible workflow accounts"
expect_fixed "workflowAccountSatisfiesRequiredScope" "$DASHBOARD_CONTROLLER" "Dashboard controller normalizes workflow account scope eligibility"
expect_fixed "missingScopes" "$DASHBOARD_CONTROLLER" "Dashboard controller surfaces missing workflow scopes for CP selection context"
expect_fixed "selectedCreateTemplate" "$DASHBOARD_CONTROLLER" "Dashboard controller tracks the selected workflow template in create mode"

expect_fixed "selectedSubnavItem = 'workflows'" "$WORKFLOW_TEMPLATE" "Workflow template selects the workflows subnav item"
expect_fixed "{% set showWorkflowRiskColumn = workflowListFilter == 'attention' %}" "$WORKFLOW_TEMPLATE" "Workflow template gates the workflow risk column behind the attention filter"
expect_fixed "Create Workflow" "$WORKFLOW_TEMPLATE" "Workflow template exposes create workflow copy"
expect_fixed "Workflow configuration" "$WORKFLOW_TEMPLATE" "Workflow template exposes workflow edit copy"
expect_fixed "Handoff" "$WORKFLOW_TEMPLATE" "Workflow template exposes workflow handoff actions"
expect_fixed "Worker / Agent Handoff" "$WORKFLOW_TEMPLATE" "Workflow template labels the workflow handoff surface clearly"
expect_fixed "Recent runs" "$WORKFLOW_TEMPLATE" "Workflow template exposes recent run visibility"
expect_fixed "External execution" "$WORKFLOW_TEMPLATE" "Workflow template explains the external execution boundary"
expect_fixed "agents-workflows-registry" "$WORKFLOW_TEMPLATE" "Workflow template renders a registry table"
expect_fixed "agents-workflows-registry__col--icon" "$WORKFLOW_TEMPLATE" "Workflow template renders the leading workflow icon column"
expect_fixed "agents-workflows-registry-icon" "$WORKFLOW_TEMPLATE" "Workflow template renders the leading workflow icon in registry rows"
expect_fixed "agents-standard-registry-wrap" "$WORKFLOW_TEMPLATE" "Workflow template uses the shared standard registry wrapper"
expect_fixed "agents-standard-registry__header-cell" "$WORKFLOW_TEMPLATE" "Workflow template uses shared registry header cells"
expect_fixed "agents-standard-registry__body-cell--empty" "$WORKFLOW_TEMPLATE" "Workflow template uses shared empty-row registry styling"
expect_fixed "agents-table-attention-heading" "$WORKFLOW_TEMPLATE" "Workflow template highlights the attention risk header"
expect_fixed "agents-table-attention-text" "$WORKFLOW_TEMPLATE" "Workflow template highlights workflow attention reasons"
expect_fixed "missing:" "$WORKFLOW_TEMPLATE" "Workflow template explains account eligibility gaps in the account selector"
expect_fixed "agents-workflow-template-library" "$WORKFLOW_TEMPLATE" "Workflow template renders a template library for workflow creation"
expect_fixed "border: 1px dashed rgba(96, 125, 159, 0.5);" "$WORKFLOW_TEMPLATE" "Workflow template uses Craft-style dashed picker borders"
expect_fixed "curated read-only workflow template" "$WORKFLOW_TEMPLATE" "Workflow template explains the read-only-first creation model"
expect_fixed "No matching account yet" "$WORKFLOW_TEMPLATE" "Workflow template explains when no eligible workflow account exists"
expect_fixed "Create matching account in Accounts" "$WORKFLOW_TEMPLATE" "Workflow template hands missing-account setup off to Accounts"
expect_fixed 'type="time"' "$WORKFLOW_TEMPLATE" "Workflow template uses a plain HTML time input for schedule editing"
expect_fixed "agents-table-toolbar" "$WORKFLOW_TEMPLATE" "Workflow template uses the shared table toolbar wrapper"
expect_fixed "getEntries()->getAllSections()" "$WORKFLOW_SERVICE" "Workflow service validates sections via Craft entries service"
expect_fixed "entries:write'" "$WORKFLOW_SERVICE" "Workflow service honors the deprecated draft-write scope alias"
expect_fixed "download the matching account handoff" "$WORKFLOW_SERVICE" "Workflow handoff guidance points operators back to the account handoff for secrets"
expect_fixed "OUTPUT_ROOT=" "$WORKFLOW_SERVICE" "Workflow handoff env examples include an output root"
expect_fixed "Download handoff" "$WORKFLOW_TEMPLATE" "Workflow template exposes workflow handoff download controls"
expect_fixed "var(--ui-control-height)" "$CP_UI_PARTIAL" "Shared CP UI aligns the reusable filter strip to Craft control height"
expect_fixed "--agents-table-body-padding-block: 24px;" "$CP_UI_PARTIAL" "Shared CP UI defines reusable table body padding tokens"
expect_fixed "--agents-table-attention-color:" "$CP_UI_PARTIAL" "Shared CP UI defines reusable attention color tokens"
expect_fixed "background: var(--secondary-pane-bg);" "$CP_UI_PARTIAL" "Shared CP UI uses the restored strip background color"
expect_fixed "agents-managed-account-ref" "$CP_UI_PARTIAL" "Shared CP UI styles the reusable managed-account reference"
expect_fixed "color: #596673;" "$CP_UI_PARTIAL" "Shared CP UI keeps the managed-account reference label muted"
expect_fixed "font-weight: 700;" "$CP_UI_PARTIAL" "Shared CP UI keeps the managed-account reference label bold"
expect_fixed "managed-account-ref" "$WORKFLOW_TEMPLATE" "Workflow template uses the shared managed-account reference partial"
expect_fixed "managed-account-ref" "$MANAGED_ACCOUNT_PARTIAL" "Managed-account reference partial exists"
expect_absent "<a href=" "$MANAGED_ACCOUNT_PARTIAL" "Managed-account reference partial no longer links to the account surface"

expect_fixed "**Workflows**" "$CP_DOCS" "CP docs include the Workflows surface"
expect_fixed "admin/agents/workflows" "$CP_DOCS" "CP docs include the workflows route"
expect_fixed "read-only workflow instances" "$CP_DOCS" "CP docs describe workflows as read-only"
expect_fixed "handoff bundle" "$CP_DOCS" "CP docs mention workflow handoff bundles"
expect_fixed "job runner" "$CP_DOCS" "CP docs explain that Agents is not the workflow job runner"
expect_fixed "recent-run views only show data if something external writes those run rows today" "$CP_DOCS" "CP docs tame expectations around current workflow run visibility"

pass "Workflow feature regression checks completed"
