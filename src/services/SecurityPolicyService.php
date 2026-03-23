<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use Klick\Agents\Plugin;

class SecurityPolicyService extends Component
{
    private const EFFECTIVE_POLICY_VERSION = 'env-profile-v1';
    private const SAFE_RUNTIME_DEFAULT_SCOPES = [
        'auth:read',
        'health:read',
        'readiness:read',
        'capabilities:read',
        'openapi:read',
    ];
    private const JOB_RUNTIME_ADDON_SCOPES = [
        'jobs:read',
        'jobs:report',
    ];
    private const ACCOUNT_SCOPE_BUNDLES = [
        'runtime_basics' => [
            'label' => 'Agent Basics',
            'hint' => 'Safe default for every new account. Covers auth checks, health checks, readiness, and machine-readable contract discovery.',
        ],
        'jobs' => [
            'label' => 'Jobs',
            'hint' => 'Add when an agent should discover, inspect, or report recurring Jobs.',
        ],
        'content_review' => [
            'label' => 'Content Review',
            'hint' => 'Read entries, assets, taxonomy, and site structure for content-focused review work.',
        ],
        'commerce_review' => [
            'label' => 'Commerce Review',
            'hint' => 'Read catalog, order, and commerce records for merchandising or operational review.',
        ],
        'sensitive_data' => [
            'label' => 'Sensitive Data',
            'hint' => 'High-trust read access for unredacted people, address, or order detail.',
        ],
        'sync_webhooks' => [
            'label' => 'Sync & Webhooks',
            'hint' => 'Change feeds, sync checkpoints, and webhook dead-letter operations.',
        ],
        'observability' => [
            'label' => 'Observability & Diagnostics',
            'hint' => 'Operator-facing telemetry, incident snapshots, diagnostics bundles, and support signals.',
        ],
        'governed_writes' => [
            'label' => 'Governed Writes',
            'hint' => 'Experimental or higher-risk write, approval, and execution scopes. Leave off unless the account truly needs them.',
        ],
        'other' => [
            'label' => 'Other',
            'hint' => 'Fallback bucket for uncommon or transitional scopes.',
        ],
    ];
    private const DEFAULT_RATE_LIMIT_PER_MINUTE = 60;
    private const DEFAULT_RATE_LIMIT_WINDOW_SECONDS = 60;
    private const DEFAULT_WEBHOOK_TIMEOUT_SECONDS = 5;
    private const DEFAULT_WEBHOOK_MAX_ATTEMPTS = 3;
    private const PROFILE_DEFAULTS = [
        'local' => [
            'requireToken' => true,
            'allowInsecureNoTokenInProd' => false,
            'allowQueryToken' => false,
            'failOnMissingTokenInProd' => true,
            'redactEmail' => true,
            'rateLimitPerMinute' => 300,
            'rateLimitWindowSeconds' => 60,
            'webhookTimeoutSeconds' => 5,
            'webhookMaxAttempts' => 2,
        ],
        'test' => [
            'requireToken' => true,
            'allowInsecureNoTokenInProd' => false,
            'allowQueryToken' => false,
            'failOnMissingTokenInProd' => true,
            'redactEmail' => true,
            'rateLimitPerMinute' => 300,
            'rateLimitWindowSeconds' => 60,
            'webhookTimeoutSeconds' => 5,
            'webhookMaxAttempts' => 2,
        ],
        'staging' => [
            'requireToken' => true,
            'allowInsecureNoTokenInProd' => false,
            'allowQueryToken' => false,
            'failOnMissingTokenInProd' => true,
            'redactEmail' => true,
            'rateLimitPerMinute' => 120,
            'rateLimitWindowSeconds' => 60,
            'webhookTimeoutSeconds' => 5,
            'webhookMaxAttempts' => 3,
        ],
        'production' => [
            'requireToken' => true,
            'allowInsecureNoTokenInProd' => false,
            'allowQueryToken' => false,
            'failOnMissingTokenInProd' => true,
            'redactEmail' => true,
            'rateLimitPerMinute' => 60,
            'rateLimitWindowSeconds' => 60,
            'webhookTimeoutSeconds' => 5,
            'webhookMaxAttempts' => 3,
        ],
    ];

    private ?array $runtimeConfig = null;

    public function getManagedAccountDefaultScopes(): array
    {
        return $this->filterUnavailableDefaultScopes(self::SAFE_RUNTIME_DEFAULT_SCOPES);
    }

    public function getJobRuntimeAddonScopes(): array
    {
        return $this->filterUnavailableDefaultScopes(self::JOB_RUNTIME_ADDON_SCOPES);
    }

    public function getAccountScopeBundles(): array
    {
        return self::ACCOUNT_SCOPE_BUNDLES;
    }

    public function getAccountScopeCatalog(): array
    {
        $catalog = [
            'auth:read' => [
                'label' => 'Auth Diagnostics',
                'description' => 'Read authenticated caller diagnostics (`/auth/whoami`).',
                'bundle' => 'runtime_basics',
                'recommendedByDefault' => true,
            ],
            'health:read' => [
                'label' => 'Service Health',
                'description' => 'Read service health summary.',
                'bundle' => 'runtime_basics',
                'recommendedByDefault' => true,
            ],
            'readiness:read' => [
                'label' => 'Readiness',
                'description' => 'Read readiness summary and score.',
                'bundle' => 'runtime_basics',
                'recommendedByDefault' => true,
            ],
            'capabilities:read' => [
                'label' => 'Capabilities',
                'description' => 'Read capabilities descriptor endpoint.',
                'bundle' => 'runtime_basics',
                'recommendedByDefault' => true,
            ],
            'openapi:read' => [
                'label' => 'OpenAPI Contract',
                'description' => 'Read OpenAPI descriptor endpoint.',
                'bundle' => 'runtime_basics',
                'recommendedByDefault' => true,
            ],
            'schema:read' => [
                'label' => 'Schema',
                'description' => 'Read machine-readable endpoint schemas for a specific version.',
                'bundle' => 'runtime_basics',
            ],
            'templates:read' => [
                'label' => 'Templates',
                'description' => 'Read canonical integration templates derived from schema and OpenAPI contracts.',
                'bundle' => 'runtime_basics',
            ],
            'jobs:read' => [
                'label' => 'Job Discovery',
                'description' => 'Read managed Job discovery and detail endpoints for the bound account.',
                'bundle' => 'jobs',
                'recommendedForJobs' => true,
            ],
            'jobs:report' => [
                'label' => 'Job Run Reporting',
                'description' => 'Record managed Job run lifecycle state and summaries.',
                'bundle' => 'jobs',
                'recommendedForJobs' => true,
            ],
            'entries:read' => [
                'label' => 'Entries',
                'description' => 'Read live content entry endpoints.',
                'bundle' => 'content_review',
            ],
            'entries:read_all_statuses' => [
                'label' => 'Entries: All Statuses',
                'description' => 'Read non-live entries, drafts, and broader status detail.',
                'bundle' => 'content_review',
            ],
            'assets:read' => [
                'label' => 'Assets',
                'description' => 'Read asset list and lookup endpoints.',
                'bundle' => 'content_review',
            ],
            'categories:read' => [
                'label' => 'Categories',
                'description' => 'Read category list and lookup endpoints.',
                'bundle' => 'content_review',
            ],
            'tags:read' => [
                'label' => 'Tags',
                'description' => 'Read tag list and lookup endpoints.',
                'bundle' => 'content_review',
            ],
            'globalsets:read' => [
                'label' => 'Global Sets',
                'description' => 'Read global set list and lookup endpoints.',
                'bundle' => 'content_review',
            ],
            'contentblocks:read' => [
                'label' => 'Content Blocks',
                'description' => 'Read content block list and lookup endpoints.',
                'bundle' => 'content_review',
            ],
            'changes:read' => [
                'label' => 'Changes Feed',
                'description' => 'Read the unified cross-resource incremental changes feed.',
                'bundle' => 'content_review',
            ],
            'sections:read' => [
                'label' => 'Sections',
                'description' => 'Read section list endpoint.',
                'bundle' => 'content_review',
            ],
            'products:read' => [
                'label' => 'Products',
                'description' => 'Read product snapshot endpoints.',
                'bundle' => 'commerce_review',
            ],
            'variants:read' => [
                'label' => 'Variants',
                'description' => 'Read variant list and lookup endpoints.',
                'bundle' => 'commerce_review',
            ],
            'subscriptions:read' => [
                'label' => 'Subscriptions',
                'description' => 'Read subscription list and lookup endpoints.',
                'bundle' => 'commerce_review',
            ],
            'transfers:read' => [
                'label' => 'Transfers',
                'description' => 'Read transfer list and lookup endpoints.',
                'bundle' => 'commerce_review',
            ],
            'donations:read' => [
                'label' => 'Donations',
                'description' => 'Read donation list and lookup endpoints.',
                'bundle' => 'commerce_review',
            ],
            'orders:read' => [
                'label' => 'Orders',
                'description' => 'Read order metadata endpoints.',
                'bundle' => 'commerce_review',
            ],
            'orders:read_sensitive' => [
                'label' => 'Orders: Sensitive Detail',
                'description' => 'Unredacted order PII and financial detail fields.',
                'bundle' => 'sensitive_data',
            ],
            'addresses:read' => [
                'label' => 'Addresses',
                'description' => 'Read address list and lookup endpoints.',
                'bundle' => 'sensitive_data',
            ],
            'addresses:read_sensitive' => [
                'label' => 'Addresses: Sensitive Detail',
                'description' => 'Unredacted address PII detail fields.',
                'bundle' => 'sensitive_data',
            ],
            'users:read' => [
                'label' => 'Users',
                'description' => 'Read user list and lookup endpoints.',
                'bundle' => 'sensitive_data',
            ],
            'users:read_sensitive' => [
                'label' => 'Users: Sensitive Detail',
                'description' => 'Unredacted user email and profile detail fields.',
                'bundle' => 'sensitive_data',
            ],
            'syncstate:read' => [
                'label' => 'Sync State',
                'description' => 'Read per-integration sync-state lag and checkpoint status.',
                'bundle' => 'sync_webhooks',
            ],
            'syncstate:write' => [
                'label' => 'Sync State Writeback',
                'description' => 'Record per-integration sync-state checkpoints for lag tracking.',
                'bundle' => 'sync_webhooks',
            ],
            'consumers:read' => [
                'label' => 'Consumers (Legacy Read)',
                'description' => 'Deprecated alias for sync-state read access.',
                'bundle' => 'sync_webhooks',
            ],
            'consumers:write' => [
                'label' => 'Consumers (Legacy Write)',
                'description' => 'Deprecated alias for sync-state write access.',
                'bundle' => 'sync_webhooks',
            ],
            'webhooks:dlq:read' => [
                'label' => 'Webhook Dead Letters',
                'description' => 'Read failed webhook dead-letter events.',
                'bundle' => 'sync_webhooks',
            ],
            'webhooks:dlq:replay' => [
                'label' => 'Webhook Replay',
                'description' => 'Replay failed webhook dead-letter events.',
                'bundle' => 'sync_webhooks',
            ],
            'adoption:read' => [
                'label' => 'Adoption Metrics',
                'description' => 'Read adoption instrumentation snapshot (`/adoption/metrics`).',
                'bundle' => 'observability',
            ],
            'metrics:read' => [
                'label' => 'Observability Metrics',
                'description' => 'Read observability metrics snapshot (`/metrics`).',
                'bundle' => 'observability',
            ],
            'incidents:read' => [
                'label' => 'Incidents',
                'description' => 'Read redacted agent incident snapshot (`/incidents`).',
                'bundle' => 'observability',
            ],
            'lifecycle:read' => [
                'label' => 'Lifecycle Governance',
                'description' => 'Read agent lifecycle governance snapshot (`/lifecycle`).',
                'bundle' => 'observability',
            ],
            'diagnostics:read' => [
                'label' => 'Diagnostics Bundle',
                'description' => 'Read one-click diagnostics support bundle (`/diagnostics/bundle`).',
                'bundle' => 'observability',
            ],
            'entries:write:draft' => [
                'label' => 'Entry Draft Writes',
                'description' => 'Create and update entry drafts through governed control actions.',
                'bundle' => 'governed_writes',
            ],
            'entries:write' => [
                'label' => 'Entry Draft Writes (Legacy Alias)',
                'description' => 'Deprecated alias for `entries:write:draft`.',
                'bundle' => 'governed_writes',
            ],
            'control:policies:read' => [
                'label' => 'Approval Policies: Read',
                'description' => 'Read control action policies.',
                'bundle' => 'governed_writes',
            ],
            'control:policies:write' => [
                'label' => 'Approval Policies: Write',
                'description' => 'Create and update control action policies.',
                'bundle' => 'governed_writes',
            ],
            'control:approvals:read' => [
                'label' => 'Approvals: Read',
                'description' => 'Read approval request queue.',
                'bundle' => 'governed_writes',
            ],
            'control:approvals:request' => [
                'label' => 'Approvals: Request',
                'description' => 'Create control approval requests from an agent.',
                'bundle' => 'governed_writes',
            ],
            'control:approvals:decide' => [
                'label' => 'Approvals: Decide',
                'description' => 'Approve or reject pending control approvals.',
                'bundle' => 'governed_writes',
            ],
            'control:approvals:write' => [
                'label' => 'Approvals: Combined Legacy Write',
                'description' => 'Legacy combined scope for requesting and deciding approvals.',
                'bundle' => 'governed_writes',
            ],
            'control:executions:read' => [
                'label' => 'Executions: Read',
                'description' => 'Read the control action execution ledger.',
                'bundle' => 'governed_writes',
            ],
            'control:actions:simulate' => [
                'label' => 'Actions: Simulate',
                'description' => 'Run dry-run policy and approval evaluation without execution.',
                'bundle' => 'governed_writes',
            ],
            'control:actions:execute' => [
                'label' => 'Actions: Execute',
                'description' => 'Execute idempotent control actions.',
                'bundle' => 'governed_writes',
            ],
            'control:audit:read' => [
                'label' => 'Audit Log',
                'description' => 'Read immutable control-plane audit log.',
                'bundle' => 'governed_writes',
            ],
        ];

        $available = [];
        foreach ($catalog as $scope => $meta) {
            if (!$this->isScopeAvailableForCp($scope)) {
                continue;
            }

            $available[$scope] = $meta;
        }

        return $available;
    }

    public function getRuntimeConfig(): array
    {
        if ($this->runtimeConfig !== null) {
            return $this->runtimeConfig;
        }

        $environment = strtolower((string)(App::env('ENVIRONMENT') ?: App::env('CRAFT_ENVIRONMENT') ?: 'production'));
        if ($environment === '') {
            $environment = 'production';
        }
        $isProduction = in_array($environment, ['prod', 'production'], true);
        $profileResolution = $this->resolveEnvironmentProfile($environment);
        $environmentProfile = (string)$profileResolution['profile'];
        $profileDefaults = self::PROFILE_DEFAULTS[$environmentProfile] ?? self::PROFILE_DEFAULTS['production'];
        $profileDefaultsAppliedFields = [];

        $requestedRequireToken = $this->resolveBooleanRuntimeSetting(
            'PLUGIN_AGENTS_REQUIRE_TOKEN',
            (bool)$profileDefaults['requireToken'],
            'requireToken',
            $profileDefaultsAppliedFields
        );

        $allowInsecureNoTokenInProd = $this->resolveBooleanRuntimeSetting(
            'PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD',
            (bool)$profileDefaults['allowInsecureNoTokenInProd'],
            'allowInsecureNoTokenInProd',
            $profileDefaultsAppliedFields
        );

        $allowQueryToken = $this->resolveBooleanRuntimeSetting(
            'PLUGIN_AGENTS_ALLOW_QUERY_TOKEN',
            (bool)$profileDefaults['allowQueryToken'],
            'allowQueryToken',
            $profileDefaultsAppliedFields
        );

        $failOnMissingTokenInProd = $this->resolveBooleanRuntimeSetting(
            'PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD',
            (bool)$profileDefaults['failOnMissingTokenInProd'],
            'failOnMissingTokenInProd',
            $profileDefaultsAppliedFields
        );

        $redactEmail = $this->resolveBooleanRuntimeSetting(
            'PLUGIN_AGENTS_REDACT_EMAIL',
            (bool)$profileDefaults['redactEmail'],
            'redactEmail',
            $profileDefaultsAppliedFields
        );

        $tokenScopes = $this->parseScopes((string)App::env('PLUGIN_AGENTS_TOKEN_SCOPES'));
        if (empty($tokenScopes)) {
            $tokenScopes = $this->getManagedAccountDefaultScopes();
        }
        $tokenScopes = $this->filterUnavailableDefaultScopes($tokenScopes);

        $tokenRequirementForcedInProd = false;
        $requireToken = $requestedRequireToken;
        if (!$requireToken && $isProduction && !$allowInsecureNoTokenInProd) {
            $requireToken = true;
            $tokenRequirementForcedInProd = true;
        }

        $credentialsParseError = false;
        $envCredentials = $this->parseCredentials((string)App::env('PLUGIN_AGENTS_API_CREDENTIALS'), $tokenScopes, $credentialsParseError);

        $primaryApiToken = trim((string)App::env('PLUGIN_AGENTS_API_TOKEN'));
        if ($primaryApiToken !== '') {
            $envCredentials[] = [
                'id' => 'default',
                'token' => $primaryApiToken,
                'scopes' => $tokenScopes,
                'source' => 'env',
            ];
        }

        $managedCredentials = [];
        $plugin = Plugin::getInstance();
        if ($plugin !== null) {
            try {
                $managedCredentials = $plugin->getCredentialService()->getManagedCredentialsForRuntime($tokenScopes);
            } catch (\Throwable $e) {
                Craft::warning('Unable to load managed credentials for runtime auth: ' . $e->getMessage(), __METHOD__);
            }
        }

        $credentials = $this->deduplicateCredentials(array_merge($managedCredentials, $envCredentials), $tokenScopes);

        $envCredentialCount = 0;
        $managedCredentialCount = 0;
        foreach ($credentials as $credential) {
            if (($credential['source'] ?? 'env') === 'cp') {
                $managedCredentialCount++;
            } else {
                $envCredentialCount++;
            }
        }

        $rateLimitPerMinute = $this->resolvePositiveIntegerRuntimeSetting(
            'PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE',
            (int)$profileDefaults['rateLimitPerMinute'],
            self::DEFAULT_RATE_LIMIT_PER_MINUTE,
            'rateLimitPerMinute',
            $profileDefaultsAppliedFields
        );

        $rateLimitWindowSeconds = $this->resolvePositiveIntegerRuntimeSetting(
            'PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS',
            (int)$profileDefaults['rateLimitWindowSeconds'],
            self::DEFAULT_RATE_LIMIT_WINDOW_SECONDS,
            'rateLimitWindowSeconds',
            $profileDefaultsAppliedFields
        );

        $webhookTransport = $this->resolveWebhookTransportSettings();
        $webhookUrl = trim((string)($webhookTransport['url'] ?? ''));
        $webhookSecret = trim((string)($webhookTransport['secret'] ?? ''));
        $webhookTimeoutSeconds = $this->resolvePositiveIntegerRuntimeSetting(
            'PLUGIN_AGENTS_WEBHOOK_TIMEOUT_SECONDS',
            (int)$profileDefaults['webhookTimeoutSeconds'],
            self::DEFAULT_WEBHOOK_TIMEOUT_SECONDS,
            'webhookTimeoutSeconds',
            $profileDefaultsAppliedFields
        );
        $webhookTimeoutSeconds = min($webhookTimeoutSeconds, 60);

        $webhookMaxAttempts = $this->resolvePositiveIntegerRuntimeSetting(
            'PLUGIN_AGENTS_WEBHOOK_MAX_ATTEMPTS',
            (int)$profileDefaults['webhookMaxAttempts'],
            self::DEFAULT_WEBHOOK_MAX_ATTEMPTS,
            'webhookMaxAttempts',
            $profileDefaultsAppliedFields
        );
        $webhookMaxAttempts = min($webhookMaxAttempts, 10);

        $profileDefaultsAppliedFields = array_values(array_unique($profileDefaultsAppliedFields));
        sort($profileDefaultsAppliedFields);

        $this->runtimeConfig = [
            'environment' => $environment,
            'isProduction' => $isProduction,
            'environmentProfile' => $environmentProfile,
            'environmentProfileSource' => (string)$profileResolution['source'],
            'environmentProfileProvided' => $profileResolution['provided'],
            'environmentProfileInvalid' => (bool)$profileResolution['invalid'],
            'profileDefaultsApplied' => !empty($profileDefaultsAppliedFields),
            'profileDefaultsAppliedFields' => $profileDefaultsAppliedFields,
            'effectivePolicyVersion' => self::EFFECTIVE_POLICY_VERSION,
            'requestedRequireToken' => $requestedRequireToken,
            'requireToken' => $requireToken,
            'allowInsecureNoTokenInProd' => $allowInsecureNoTokenInProd,
            'tokenRequirementForcedInProd' => $tokenRequirementForcedInProd,
            'allowQueryToken' => $allowQueryToken,
            'failOnMissingTokenInProd' => $failOnMissingTokenInProd,
            'credentials' => $credentials,
            'credentialsParseError' => $credentialsParseError,
            'primaryApiTokenConfigured' => $primaryApiToken !== '',
            'envCredentialCount' => $envCredentialCount,
            'managedCredentialCount' => $managedCredentialCount,
            'tokenScopes' => $tokenScopes,
            'rateLimitPerMinute' => $rateLimitPerMinute,
            'rateLimitWindowSeconds' => $rateLimitWindowSeconds,
            'redactEmail' => $redactEmail,
            'webhookUrlConfigured' => $webhookUrl !== '',
            'webhookSecretConfigured' => $webhookSecret !== '',
            'webhookEnabled' => $webhookUrl !== '' && $webhookSecret !== '',
            'webhookUrl' => $webhookUrl,
            'webhookSecret' => $webhookSecret,
            'webhookUrlHost' => $this->extractUrlHost($webhookUrl),
            'webhookTimeoutSeconds' => $webhookTimeoutSeconds,
            'webhookMaxAttempts' => $webhookMaxAttempts,
        ];

        return $this->runtimeConfig;
    }

    public function getWarnings(): array
    {
        $config = $this->getRuntimeConfig();
        $warnings = [];

        if ($config['credentialsParseError']) {
            $warnings[] = [
                'level' => 'warning',
                'message' => 'Agents credential set (`PLUGIN_AGENTS_API_CREDENTIALS`) is present but could not be parsed as a non-empty JSON array/object.',
            ];
        }

        if ((bool)($config['environmentProfileInvalid'] ?? false)) {
            $provided = trim((string)($config['environmentProfileProvided'] ?? ''));
            $warnings[] = [
                'level' => 'warning',
                'message' => sprintf(
                    'Invalid `PLUGIN_AGENTS_ENV_PROFILE` value `%s`; falling back to `%s` profile defaults.',
                    $provided !== '' ? $provided : 'unknown',
                    (string)($config['environmentProfile'] ?? 'local')
                ),
            ];
        }

        if (!$config['requestedRequireToken']) {
            if ($config['isProduction'] && !$config['allowInsecureNoTokenInProd']) {
                $warnings[] = [
                    'level' => 'error',
                    'message' => 'Agents API token enforcement is set to false in production, but this is blocked unless `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD=true`.',
                ];
            } else {
                $warnings[] = [
                    'level' => 'warning',
                    'message' => 'Agents API token enforcement is disabled (`PLUGIN_AGENTS_REQUIRE_TOKEN=false`).',
                ];
            }
        }

        if ($config['allowQueryToken']) {
            $warnings[] = [
                'level' => 'warning',
                'message' => 'Agents API query token transport is enabled (`PLUGIN_AGENTS_ALLOW_QUERY_TOKEN=true`).',
            ];
        }

        if ($config['isProduction'] && !$config['requestedRequireToken'] && $config['allowInsecureNoTokenInProd']) {
            $warnings[] = [
                'level' => 'warning',
                'message' => 'Agents API token enforcement is explicitly disabled in production via `PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD=true`.',
            ];
        }

          if ($config['requireToken'] && empty($config['credentials'])) {
              if ($config['isProduction'] && $config['failOnMissingTokenInProd']) {
                  $warnings[] = [
                      'code' => 'missing_credentials',
                      'level' => 'error',
                      'message' => 'Agents API credentials are required but no usable env/managed credentials were found. Requests will fail-closed in production.',
                  ];
              } else {
                  $warnings[] = [
                      'code' => 'missing_credentials',
                      'level' => 'warning',
                      'message' => 'Agents API credentials are required but no usable env/managed credentials were found. Set `PLUGIN_AGENTS_REQUIRE_TOKEN=false` for explicit local-only bypass.',
                  ];
              }
        }

        if ($config['webhookUrlConfigured'] && !$config['webhookSecretConfigured']) {
            $warnings[] = [
                'level' => 'warning',
                'message' => 'Agents webhook URL is configured but `PLUGIN_AGENTS_WEBHOOK_SECRET` is missing. Webhook delivery is disabled until both are set.',
            ];
        }

        if (!$config['webhookUrlConfigured'] && $config['webhookSecretConfigured']) {
            $warnings[] = [
                'level' => 'warning',
                'message' => 'Agents webhook secret is configured but `PLUGIN_AGENTS_WEBHOOK_URL` is missing. Webhook delivery is disabled until both are set.',
            ];
        }

        return $warnings;
    }

    public function getCpPosture(): array
    {
        $config = $this->getRuntimeConfig();
        $credentialIds = [];
        foreach ($config['credentials'] as $credential) {
            $source = (string)($credential['source'] ?? 'env');
            $credentialIds[] = sprintf('%s:%s', $source, (string)($credential['id'] ?? 'default'));
        }

        $warnings = $this->getWarnings();
        $warningCount = 0;
        $errorCount = 0;
        foreach ($warnings as $warning) {
            if (($warning['level'] ?? 'warning') === 'error') {
                $errorCount++;
            } else {
                $warningCount++;
            }
        }

        return [
            'environment' => $config['environment'],
            'profile' => [
                'name' => (string)($config['environmentProfile'] ?? 'local'),
                'source' => (string)($config['environmentProfileSource'] ?? 'inferred'),
                'defaultsApplied' => (bool)($config['profileDefaultsApplied'] ?? false),
                'defaultsAppliedFields' => array_values((array)($config['profileDefaultsAppliedFields'] ?? [])),
                'effectivePolicyVersion' => (string)($config['effectivePolicyVersion'] ?? self::EFFECTIVE_POLICY_VERSION),
            ],
            'authentication' => [
                'required' => (bool)$config['requireToken'],
                'requestedRequireToken' => (bool)$config['requestedRequireToken'],
                'tokenRequirementForcedInProd' => (bool)$config['tokenRequirementForcedInProd'],
                'allowQueryToken' => (bool)$config['allowQueryToken'],
                'failOnMissingTokenInProd' => (bool)$config['failOnMissingTokenInProd'],
                'credentialCount' => count($config['credentials']),
                'envCredentialCount' => (int)$config['envCredentialCount'],
                'managedCredentialCount' => (int)$config['managedCredentialCount'],
                'credentialIds' => $credentialIds,
                'tokenScopes' => array_values((array)$config['tokenScopes']),
            ],
            'rateLimit' => [
                'perMinute' => (int)$config['rateLimitPerMinute'],
                'windowSeconds' => (int)$config['rateLimitWindowSeconds'],
            ],
            'privacy' => [
                'redactEmail' => (bool)$config['redactEmail'],
            ],
            'webhook' => [
                'enabled' => (bool)$config['webhookEnabled'],
                'urlConfigured' => (bool)$config['webhookUrlConfigured'],
                'secretConfigured' => (bool)$config['webhookSecretConfigured'],
                'destinationHost' => $config['webhookUrlHost'],
                'timeoutSeconds' => (int)$config['webhookTimeoutSeconds'],
                'maxAttempts' => (int)$config['webhookMaxAttempts'],
            ],
            'warnings' => $warnings,
            'warningCounts' => [
                'warnings' => $warningCount,
                'errors' => $errorCount,
            ],
        ];
    }

    private function resolveBooleanRuntimeSetting(
        string $envVar,
        bool $profileDefault,
        string $field,
        array &$profileDefaultsAppliedFields
    ): bool {
        $resolved = App::parseBooleanEnv('$' . $envVar);
        if ($resolved !== null) {
            return (bool)$resolved;
        }

        $profileDefaultsAppliedFields[] = $field;
        return $profileDefault;
    }

    private function resolvePositiveIntegerRuntimeSetting(
        string $envVar,
        int $profileDefault,
        int $fallbackDefault,
        string $field,
        array &$profileDefaultsAppliedFields
    ): int {
        $raw = App::env($envVar);
        $value = is_numeric($raw) ? (int)$raw : 0;
        if ($value > 0) {
            return $value;
        }

        $profileDefaultsAppliedFields[] = $field;
        if ($profileDefault > 0) {
            return $profileDefault;
        }

        return $fallbackDefault;
    }

    private function resolveWebhookTransportSettings(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin?->getSettings();

        return [
            'url' => $this->resolveEnvAwareStringSetting($settings?->webhookUrl ?? '$PLUGIN_AGENTS_WEBHOOK_URL'),
            'secret' => $this->resolveEnvAwareStringSetting($settings?->webhookSecret ?? '$PLUGIN_AGENTS_WEBHOOK_SECRET'),
        ];
    }

    private function resolveEnvAwareStringSetting(mixed $value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        $resolved = App::parseEnv($raw);
        if (is_scalar($resolved)) {
            return trim((string)$resolved);
        }

        return $raw;
    }

    private function resolveEnvironmentProfile(string $environment): array
    {
        $provided = trim((string)App::env('PLUGIN_AGENTS_ENV_PROFILE'));
        if ($provided === '') {
            return [
                'profile' => $this->inferEnvironmentProfile($environment),
                'source' => 'inferred',
                'provided' => null,
                'invalid' => false,
            ];
        }

        $normalized = $this->normalizeEnvironmentProfile($provided);
        if ($normalized !== null) {
            return [
                'profile' => $normalized,
                'source' => 'env',
                'provided' => $provided,
                'invalid' => false,
            ];
        }

        return [
            'profile' => 'local',
            'source' => 'env',
            'provided' => $provided,
            'invalid' => true,
        ];
    }

    private function inferEnvironmentProfile(string $environment): string
    {
        $normalized = $this->normalizeEnvironmentProfile($environment);
        if ($normalized !== null) {
            return $normalized;
        }

        return 'local';
    }

    private function normalizeEnvironmentProfile(string $profile): ?string
    {
        $normalized = strtolower(trim($profile));
        return match ($normalized) {
            'prod', 'production' => 'production',
            'stage', 'staging' => 'staging',
            'test', 'testing', 'ci' => 'test',
            'local', 'dev', 'development' => 'local',
            default => null,
        };
    }

    private function parseScopes(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', strtolower($raw)) ?: [];
        $scopes = [];
        foreach ($parts as $part) {
            $scope = trim($part);
            if ($scope === '') {
                continue;
            }

            $scope = preg_replace('/[^a-z0-9:_*.-]/', '', $scope) ?: '';
            if ($scope === '') {
                continue;
            }

            if ($scope === 'entries:write') {
                $scope = 'entries:write:draft';
            }

            $scopes[] = $scope;
        }

        $scopes = array_values(array_unique($scopes));
        sort($scopes);
        return $scopes;
    }

    private function parseCredentials(string $raw, array $defaultScopes, bool &$parseError): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $parseError = true;
            return [];
        }

        $credentials = [];

        if ($this->isSingleCredentialObject($decoded)) {
            $credential = $this->normalizeCredentialEntry($decoded, '', 1, $defaultScopes, $parseError);
            return $credential === null ? [] : [$credential];
        }

        $index = 0;
        if (array_is_list($decoded)) {
            foreach ($decoded as $value) {
                $index++;
                if (!is_array($value)) {
                    $parseError = true;
                    continue;
                }

                $credential = $this->normalizeCredentialEntry($value, '', $index, $defaultScopes, $parseError);
                if ($credential !== null) {
                    $credentials[] = $credential;
                }
            }

            return $credentials;
        }

        foreach ($decoded as $key => $value) {
            $index++;
            if (!is_array($value)) {
                $parseError = true;
                continue;
            }

            $fallbackId = is_string($key) ? trim($key) : '';
            $credential = $this->normalizeCredentialEntry($value, $fallbackId, $index, $defaultScopes, $parseError);
            if ($credential !== null) {
                $credentials[] = $credential;
            }
        }

        return $credentials;
    }

    private function isSingleCredentialObject(array $decoded): bool
    {
        return array_key_exists('token', $decoded) || array_key_exists('value', $decoded);
    }

    private function normalizeCredentialEntry(array $value, string $fallbackId, int $index, array $defaultScopes, bool &$parseError): ?array
    {
        $token = trim((string)($value['token'] ?? $value['value'] ?? ''));
        if ($token === '') {
            $parseError = true;
            return null;
        }

        $id = $fallbackId !== '' ? $fallbackId : trim((string)($value['id'] ?? $value['name'] ?? ''));
        if ($id === '') {
            $id = 'credential-' . $index;
        }

        $id = strtolower((string)(preg_replace('/[^a-z0-9:_-]+/i', '-', $id) ?: 'credential-' . $index));
        $id = trim($id, '-');
        if ($id === '') {
            $id = 'credential-' . $index;
        }

        return [
            'id' => $id,
            'token' => $token,
            'scopes' => $this->normalizeScopes($value['scopes'] ?? null, $defaultScopes),
            'source' => 'env',
        ];
    }

    private function normalizeScopes(mixed $rawScopes, array $defaultScopes): array
    {
        if (is_array($rawScopes)) {
            $parts = [];
            foreach ($rawScopes as $scope) {
                if (!is_string($scope) && !is_numeric($scope)) {
                    continue;
                }
                $parts[] = (string)$scope;
            }

            $parsed = $this->parseScopes(implode(' ', $parts));
            if (!empty($parsed)) {
                return $parsed;
            }
        } elseif (is_string($rawScopes)) {
            $parsed = $this->parseScopes($rawScopes);
            if (!empty($parsed)) {
                return $parsed;
            }
        }

        return $defaultScopes;
    }

    private function deduplicateCredentials(array $credentials, array $defaultScopes): array
    {
        $deduped = [];
        $seen = [];
        foreach ($credentials as $credential) {
            $token = (string)($credential['token'] ?? '');
            $tokenHash = strtolower(trim((string)($credential['tokenHash'] ?? '')));
            if ($token === '' && !preg_match('/^[a-f0-9]{64}$/', $tokenHash)) {
                continue;
            }

            $fingerprint = $token !== '' ? 'plain:' . sha1($token) : 'hash:' . $tokenHash;
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            $normalized = [
                'id' => (string)($credential['id'] ?? 'default'),
                'scopes' => $this->normalizeScopes($credential['scopes'] ?? null, $defaultScopes),
                'source' => (string)($credential['source'] ?? 'env'),
            ];

            if ($token !== '') {
                $normalized['token'] = $token;
            } else {
                $normalized['tokenHash'] = $tokenHash;
            }

            if (isset($credential['managedCredentialId'])) {
                $normalized['managedCredentialId'] = (int)$credential['managedCredentialId'];
            }

            $deduped[] = $normalized;
        }

        return $deduped;
    }

    private function filterUnavailableDefaultScopes(array $scopes): array
    {
        $plugin = Plugin::getInstance();
        $commerceEnabled = $plugin?->isCommercePluginEnabled() ?? false;
        $writesExperimentalEnabled = $plugin?->isWritesExperimentalEnabled() ?? false;

        if ($commerceEnabled && $writesExperimentalEnabled) {
            return array_values($scopes);
        }

        return array_values(array_filter($scopes, function (string $scope) use ($commerceEnabled, $writesExperimentalEnabled): bool {
            if (!$commerceEnabled && in_array($scope, $this->commerceScopeKeys(), true)) {
                return false;
            }

            if (!$writesExperimentalEnabled && in_array($scope, $this->governedWriteScopeKeys(), true)) {
                return false;
            }

            return true;
        }));
    }

    private function isScopeAvailableForCp(string $scope): bool
    {
        $plugin = Plugin::getInstance();
        $commerceEnabled = $plugin?->isCommercePluginEnabled() ?? false;
        $writesExperimentalEnabled = $plugin?->isWritesExperimentalEnabled() ?? false;
        $usersApiEnabled = $plugin?->isUsersApiEnabled() ?? false;
        $addressesApiEnabled = $plugin?->isAddressesApiEnabled() ?? false;

        if (!$commerceEnabled && in_array($scope, $this->commerceScopeKeys(), true)) {
            return false;
        }

        if (!$usersApiEnabled && str_starts_with($scope, 'users:')) {
            return false;
        }

        if (!$addressesApiEnabled && str_starts_with($scope, 'addresses:')) {
            return false;
        }

        if (!$writesExperimentalEnabled && in_array($scope, $this->governedWriteScopeKeys(), true)) {
            return false;
        }

        return true;
    }

    private function commerceScopeKeys(): array
    {
        return [
            'products:read',
            'variants:read',
            'subscriptions:read',
            'transfers:read',
            'donations:read',
            'orders:read',
            'orders:read_sensitive',
            'addresses:read',
            'addresses:read_sensitive',
        ];
    }

    private function governedWriteScopeKeys(): array
    {
        return [
            'entries:write:draft',
            'entries:write',
            'control:policies:read',
            'control:policies:write',
            'control:approvals:read',
            'control:approvals:request',
            'control:approvals:decide',
            'control:approvals:write',
            'control:executions:read',
            'control:actions:simulate',
            'control:actions:execute',
            'control:audit:read',
        ];
    }

    private function extractUrlHost(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return null;
        }

        $host = trim((string)($parsed['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        $scheme = trim((string)($parsed['scheme'] ?? ''));
        if ($scheme === '') {
            return $host;
        }

        return strtolower($scheme) . '://' . strtolower($host);
    }
}
