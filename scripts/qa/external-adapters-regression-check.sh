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

API_CONTROLLER="$PLUGIN_ROOT/src/controllers/ApiController.php"
PLUGIN_FILE="$PLUGIN_ROOT/src/Plugin.php"
REGISTRY_SERVICE="$PLUGIN_ROOT/src/services/ExternalResourceRegistryService.php"
PROVIDER_INTERFACE="$PLUGIN_ROOT/src/external/ExternalResourceProviderInterface.php"
RESOURCE_DEFINITION="$PLUGIN_ROOT/src/external/ExternalResourceDefinition.php"
PARAMETER_DEFINITION="$PLUGIN_ROOT/src/external/ExternalResourceParameterDefinition.php"
EVENT_CLASS="$PLUGIN_ROOT/src/events/RegisterExternalResourceProvidersEvent.php"
HELPER_SCRIPT="$PLUGIN_ROOT/scripts/qa/helpers/external_provider_contract_smoke.php"
DASHBOARD_CONTROLLER="$PLUGIN_ROOT/src/controllers/DashboardController.php"
CREDENTIALS_TEMPLATE="$PLUGIN_ROOT/src/templates/credentials.twig"
RETOUR_ADAPTER_PLUGIN="$PLUGIN_ROOT/adapters/retour/src/Plugin.php"
RETOUR_ADAPTER_PROVIDER="$PLUGIN_ROOT/adapters/retour/src/RetourExternalResourceProvider.php"
RETOUR_ADAPTER_COMPOSER="$PLUGIN_ROOT/adapters/retour/composer.json"
EXTERNAL_ADAPTERS_DOC="$PLUGIN_ROOT/docs/api/external-plugin-adapters.md"

expect_fixed "EVENT_REGISTER_EXTERNAL_RESOURCE_PROVIDERS" "$PLUGIN_FILE" "Plugin declares external provider registration event"
expect_fixed "externalResourceRegistryService" "$PLUGIN_FILE" "Plugin registers external resource registry service"
expect_fixed "refreshExternalResourceProviders" "$PLUGIN_FILE" "Plugin refreshes external resource providers during bootstrap"
expect_fixed "agents/v1/plugins/<pluginHandle" "$PLUGIN_FILE" "Plugin registers dynamic external resource list route"
expect_fixed "external-resource-index" "$PLUGIN_FILE" "Plugin registers external resource list action route"
expect_fixed "external-resource-show" "$PLUGIN_FILE" "Plugin registers external resource detail action route"
expect_fixed "public function actionExternalResourceIndex" "$API_CONTROLLER" "API exposes external resource list action"
expect_fixed "public function actionExternalResourceShow" "$API_CONTROLLER" "API exposes external resource detail action"
expect_fixed "externalResources" "$API_CONTROLLER" "Capabilities payload exposes registered external resources"
expect_fixed "buildOpenApiPaths" "$API_CONTROLLER" "OpenAPI output merges external resource paths"
expect_fixed "buildSchemaCatalog('/agents/v1')" "$API_CONTROLLER" "Schema catalog merges external resource definitions"
expect_fixed "getCapabilityScopes()" "$API_CONTROLLER" "Available scopes merge external resource scopes"
expect_fixed "interface ExternalResourceProviderInterface" "$PROVIDER_INTERFACE" "Provider interface exists"
expect_fixed "class ExternalResourceDefinition" "$RESOURCE_DEFINITION" "External resource definition DTO exists"
expect_fixed "class ExternalResourceParameterDefinition" "$PARAMETER_DEFINITION" "External resource parameter DTO exists"
expect_fixed 'plugins:%s:%s:read' "$RESOURCE_DEFINITION" "External scopes use the plugin resource read format"
expect_fixed "class RegisterExternalResourceProvidersEvent" "$EVENT_CLASS" "External provider registration event exists"
expect_fixed "class ExternalResourceRegistryService" "$REGISTRY_SERVICE" "External resource registry service exists"
expect_fixed "getExternalResourceRegistryService()->getCapabilitiesResources()" "$DASHBOARD_CONTROLLER" "Accounts controller loads registered external resource providers"
expect_fixed "'externalResourceProviders' => \$externalResourceProviders" "$DASHBOARD_CONTROLLER" "Accounts controller passes external resource providers to the CP template"
expect_fixed "External plugin scopes" "$CREDENTIALS_TEMPLATE" "Accounts CP template renders an external plugin scopes section"
expect_fixed "provider.resources" "$CREDENTIALS_TEMPLATE" "Accounts CP template groups external scopes by provider resources"
expect_fixed "resource.scope" "$CREDENTIALS_TEMPLATE" "Accounts CP template binds external scope checkbox values from the provider registry"
expect_fixed "RegisterExternalResourceProvidersEvent" "$RETOUR_ADAPTER_PLUGIN" "Reference adapter plugin registers against the external provider event"
expect_fixed "class RetourExternalResourceProvider" "$RETOUR_ADAPTER_PROVIDER" "Reference adapter provider class exists"
expect_fixed "handle: 'redirects'" "$RETOUR_ADAPTER_PROVIDER" "Reference adapter exposes the Retour redirects resource"
expect_fixed "\"klick/agents\": \"^0.26\"" "$RETOUR_ADAPTER_COMPOSER" "Reference adapter requires the current F12 foundation release line"
expect_fixed "External Plugin Adapters" "$EXTERNAL_ADAPTERS_DOC" "External adapter docs page exists"
expect_fixed "Agents -> Accounts" "$EXTERNAL_ADAPTERS_DOC" "External adapter docs explain CP scope assignment"

php "$HELPER_SCRIPT"

echo "External adapters regression checks completed."
