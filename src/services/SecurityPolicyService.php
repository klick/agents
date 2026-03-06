<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use Klick\Agents\Plugin;

class SecurityPolicyService extends Component
{
    private const EFFECTIVE_POLICY_VERSION = 'env-profile-v1';
    private const DEFAULT_TOKEN_SCOPES = [
        'health:read',
        'readiness:read',
        'auth:read',
        'adoption:read',
        'metrics:read',
        'lifecycle:read',
        'diagnostics:read',
        'products:read',
        'variants:read',
        'subscriptions:read',
        'transfers:read',
        'donations:read',
        'orders:read',
        'entries:read',
        'assets:read',
        'categories:read',
        'tags:read',
        'globalsets:read',
        'addresses:read',
        'contentblocks:read',
        'changes:read',
        'sections:read',
        'users:read',
        'consumers:read',
        'templates:read',
        'schema:read',
        'capabilities:read',
        'openapi:read',
        'control:policies:read',
        'control:approvals:read',
        'control:executions:read',
        'control:audit:read',
        'webhooks:dlq:read',
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
            $tokenScopes = self::DEFAULT_TOKEN_SCOPES;
        }

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

        $webhookUrl = trim((string)App::env('PLUGIN_AGENTS_WEBHOOK_URL'));
        $webhookSecret = trim((string)App::env('PLUGIN_AGENTS_WEBHOOK_SECRET'));
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
                    'level' => 'error',
                    'message' => 'Agents API credentials are required but no usable env/managed credentials were found. Requests will fail-closed in production.',
                ];
            } else {
                $warnings[] = [
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
