<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use Klick\Agents\Plugin;
use Throwable;

class DiagnosticsBundleService extends Component
{
    public function getBundle(array $context = []): array
    {
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return [
                'service' => 'agents',
                'type' => 'diagnostics.bundle',
                'generatedAt' => $generatedAt,
                'status' => 'failed',
                'errors' => ['Plugin instance unavailable.'],
            ];
        }

        $securityService = $plugin->getSecurityPolicyService();
        $runtimeConfig = $securityService->getRuntimeConfig();
        $defaultScopes = (array)($runtimeConfig['tokenScopes'] ?? []);

        $collectionErrors = [];

        $readinessService = $plugin->getReadinessService();
        $consumerLagService = $plugin->getConsumerLagService();
        $webhookService = $plugin->getWebhookService();
        $observabilityService = $plugin->getObservabilityMetricsService();
        $adoptionService = $plugin->getAdoptionMetricsService();

        $securityPosture = $this->capture(
            'security.posture',
            fn(): array => $securityService->getCpPosture(),
            $collectionErrors,
            [
                'environment' => 'unknown',
                'profile' => [
                    'name' => 'local',
                    'source' => 'inferred',
                    'defaultsApplied' => false,
                    'defaultsAppliedFields' => [],
                    'effectivePolicyVersion' => '',
                ],
                'authentication' => [],
                'rateLimit' => [],
                'privacy' => [],
                'webhook' => [],
                'warnings' => [],
                'warningCounts' => ['warnings' => 0, 'errors' => 0],
            ]
        );

        $readinessHealth = $this->capture(
            'readiness.health',
            fn(): array => $readinessService->getHealthSummary(),
            $collectionErrors,
            []
        );
        $readinessSummary = $this->capture(
            'readiness.summary',
            fn(): array => $readinessService->getReadinessSummary(),
            $collectionErrors,
            []
        );
        $readinessDiagnostics = $this->capture(
            'readiness.diagnostics',
            fn(): array => $readinessService->getReadinessDiagnostics(),
            $collectionErrors,
            []
        );

        $consumerLagSnapshot = $this->capture(
            'consumers.lag',
            fn(): array => $consumerLagService->getLagSummary(200),
            $collectionErrors,
            [
                'summary' => [
                    'count' => 0,
                    'healthy' => 0,
                    'warning' => 0,
                    'critical' => 0,
                    'maxLagSeconds' => 0,
                ],
                'rows' => [],
            ]
        );
        $deadLetterEvents = $this->capture(
            'webhooks.dlq',
            fn(): array => $webhookService->getDeadLetterEvents([], 100),
            $collectionErrors,
            []
        );
        $observabilitySnapshot = $this->capture(
            'observability.metrics',
            fn(): array => $observabilityService->getMetricsSnapshot(),
            $collectionErrors,
            [
                'service' => 'agents',
                'generatedAt' => $generatedAt,
                'format' => 'json-metric-series',
                'metrics' => [],
            ]
        );
        $reliabilitySnapshot = $this->capture(
            'reliability.signals',
            fn(): array => $plugin->getReliabilitySignalService()->evaluateSnapshot((array)$observabilitySnapshot),
            $collectionErrors,
            [
                'status' => 'ok',
                'thresholdsVersion' => 'reliability-thresholds-v1',
                'generatedAt' => $generatedAt,
                'summary' => [
                    'signalsEvaluated' => 0,
                    'signalsWarn' => 0,
                    'signalsCritical' => 0,
                    'signalsOk' => 0,
                ],
                'topSignals' => [],
                'signals' => [],
            ]
        );
        $lifecycleSnapshot = $this->capture(
            'lifecycle.governance',
            fn(): array => $plugin->getLifecycleGovernanceService()->getSnapshot(),
            $collectionErrors,
            [
                'service' => 'agents',
                'generatedAt' => $generatedAt,
                'status' => 'unknown',
                'summary' => [],
                'topRisks' => [],
                'agents' => [],
            ]
        );
        $adoptionSnapshot = $this->capture(
            'adoption.metrics',
            fn(): array => $adoptionService->getSnapshot($defaultScopes),
            $collectionErrors,
            [
                'generatedAt' => $generatedAt,
                'funnel' => [],
                'timeToFirstSuccess' => [],
                'usage' => [],
            ]
        );

        $checks = [
            'auth' => $this->captureCheck('auth-check', function() use ($securityService): array {
                return $this->collectAuthCheck($securityService);
            }),
            'readiness' => $this->captureCheck('readiness-check', function() use ($readinessService): array {
                return $this->collectReadinessCheck($readinessService);
            }),
            'smoke' => $this->captureCheck('smoke', function() use ($plugin, $readinessService): array {
                return $this->collectSmokeCheck($plugin, $readinessService);
            }),
        ];
        $checkSummary = $this->buildCheckSummary($checks);

        $source = strtolower(trim((string)($context['source'] ?? 'runtime')));
        if ($source === '') {
            $source = 'runtime';
        }

        $requestId = $this->normalizeOptionalString($context['requestId'] ?? null, 128);
        $deadLetterSummary = $this->summarizeDeadLetterEvents((array)$deadLetterEvents);

        return [
            'service' => 'agents',
            'type' => 'diagnostics.bundle',
            'version' => $this->resolvePluginVersion($plugin),
            'generatedAt' => $generatedAt,
            'source' => $source,
            'requestId' => $requestId,
            'redactionPolicy' => [
                'excluded' => [
                    'api token values',
                    'credential token hashes',
                    'webhook secret values',
                ],
                'included' => [
                    'credential counts and scope grants',
                    'diagnostic warnings/errors',
                    'endpoint metadata',
                ],
            ],
            'runtime' => [
                'enabled' => (bool)($plugin->getAgentsEnabledState()['enabled'] ?? false),
                'enabledSource' => (string)($plugin->getAgentsEnabledState()['source'] ?? 'unknown'),
                'environment' => (string)($securityPosture['environment'] ?? 'unknown'),
                'environmentProfile' => (string)($securityPosture['profile']['name'] ?? ''),
                'environmentProfileSource' => (string)($securityPosture['profile']['source'] ?? ''),
                'profileDefaultsApplied' => (bool)($securityPosture['profile']['defaultsApplied'] ?? false),
                'effectivePolicyVersion' => (string)($securityPosture['profile']['effectivePolicyVersion'] ?? ''),
                'writesExperimentalEnabled' => $plugin->isWritesExperimentalEnabled(),
            ],
            'checks' => $checks,
            'summary' => [
                'checks' => $checkSummary,
                'collectionErrors' => count($collectionErrors),
                'reliability' => [
                    'status' => (string)($reliabilitySnapshot['status'] ?? 'ok'),
                    'signalsWarn' => (int)($reliabilitySnapshot['summary']['signalsWarn'] ?? 0),
                    'signalsCritical' => (int)($reliabilitySnapshot['summary']['signalsCritical'] ?? 0),
                    'topSignals' => count((array)($reliabilitySnapshot['topSignals'] ?? [])),
                ],
                'lifecycle' => [
                    'status' => (string)($lifecycleSnapshot['status'] ?? 'unknown'),
                    'agents' => (int)($lifecycleSnapshot['summary']['total'] ?? 0),
                    'riskWarn' => (int)($lifecycleSnapshot['summary']['riskWarn'] ?? 0),
                    'riskCritical' => (int)($lifecycleSnapshot['summary']['riskCritical'] ?? 0),
                ],
            ],
            'snapshots' => [
                'security' => $securityPosture,
                'readiness' => [
                    'health' => $readinessHealth,
                    'summary' => $readinessSummary,
                    'diagnostics' => $readinessDiagnostics,
                ],
                'consumers' => $consumerLagSnapshot,
                'webhooks' => $deadLetterSummary,
                'observability' => $observabilitySnapshot,
                'reliability' => $reliabilitySnapshot,
                'lifecycle' => $lifecycleSnapshot,
                'adoption' => $adoptionSnapshot,
            ],
            'collectionErrors' => $collectionErrors,
        ];
    }

    private function capture(string $section, callable $callback, array &$collectionErrors, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            $collectionErrors[] = [
                'section' => $section,
                'message' => $e->getMessage(),
            ];
            Craft::warning(sprintf('Diagnostics bundle collection failed for %s: %s', $section, $e->getMessage()), __METHOD__);
            return $fallback;
        }
    }

    private function captureCheck(string $name, callable $callback): array
    {
        try {
            $result = $callback();
            if (isset($result['check']) && is_string($result['check']) && $result['check'] !== '') {
                return $result;
            }
        } catch (Throwable $e) {
            Craft::warning(sprintf('Diagnostics check `%s` failed: %s', $name, $e->getMessage()), __METHOD__);
            return $this->formatCheckResult($name, [], [$e->getMessage()], []);
        }

        return $this->formatCheckResult($name, [], ['Unexpected diagnostics check result.'], []);
    }

    private function collectAuthCheck(SecurityPolicyService $securityService): array
    {
        $config = $securityService->getRuntimeConfig();
        $warningsRaw = $securityService->getWarnings();

        $errors = [];
        $warnings = [];

        foreach ($warningsRaw as $warning) {
            $message = trim((string)($warning['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            if (($warning['level'] ?? 'warning') === 'error') {
                $errors[] = $message;
            } else {
                $warnings[] = $message;
            }
        }

        if ((bool)($config['requireToken'] ?? true) && empty((array)($config['credentials'] ?? []))) {
            $errors[] = 'Token auth is required but no credentials are configured.';
        }

        $details = [
            'environment' => (string)($config['environment'] ?? ''),
            'environmentProfile' => (string)($config['environmentProfile'] ?? ''),
            'environmentProfileSource' => (string)($config['environmentProfileSource'] ?? ''),
            'profileDefaultsApplied' => (bool)($config['profileDefaultsApplied'] ?? false),
            'profileDefaultsAppliedFields' => array_values((array)($config['profileDefaultsAppliedFields'] ?? [])),
            'effectivePolicyVersion' => (string)($config['effectivePolicyVersion'] ?? ''),
            'requireToken' => (bool)($config['requireToken'] ?? true),
            'allowQueryToken' => (bool)($config['allowQueryToken'] ?? false),
            'credentialCount' => count((array)($config['credentials'] ?? [])),
            'managedCredentialCount' => (int)($config['managedCredentialCount'] ?? 0),
            'envCredentialCount' => (int)($config['envCredentialCount'] ?? 0),
            'tokenScopes' => array_values((array)($config['tokenScopes'] ?? [])),
            'rateLimitPerMinute' => (int)($config['rateLimitPerMinute'] ?? 0),
            'rateLimitWindowSeconds' => (int)($config['rateLimitWindowSeconds'] ?? 0),
            'webhookEnabled' => (bool)($config['webhookEnabled'] ?? false),
            'webhookUrlHost' => (string)($config['webhookUrlHost'] ?? ''),
            'webhookTimeoutSeconds' => (int)($config['webhookTimeoutSeconds'] ?? 0),
            'webhookMaxAttempts' => (int)($config['webhookMaxAttempts'] ?? 0),
        ];

        return $this->formatCheckResult('auth-check', $details, $errors, $warnings);
    }

    private function collectReadinessCheck(ReadinessService $service): array
    {
        $diagnostics = $service->getReadinessDiagnostics();
        $health = $service->getHealthSummary();

        $errors = [];
        $warnings = [];

        foreach ((array)($health['systemComponents'] ?? []) as $component => $available) {
            if (!(bool)$available) {
                $errors[] = sprintf('System component unavailable: %s', (string)$component);
            }
        }

        if (!((bool)($health['enabledPlugins']['commerce'] ?? false))) {
            $warnings[] = 'Commerce plugin is unavailable; commerce-dependent endpoints will be limited.';
        }

        foreach ((array)($diagnostics['warnings'] ?? []) as $warning) {
            $message = (string)$warning;
            if (str_contains($message, 'Web request context available')) {
                continue;
            }
            $warnings[] = $message;
        }

        return $this->formatCheckResult('readiness-check', $diagnostics, $errors, $warnings);
    }

    private function collectSmokeCheck(
        Plugin $plugin,
        ReadinessService $readinessService
    ): array {
        $commerceEnabled = $plugin->isCommercePluginEnabled();

        $errors = [];
        $warnings = [];

        $sections = $readinessService->getSectionsList();
        $entries = $readinessService->getEntriesList(['limit' => 1, 'status' => 'live']);
        $readiness = $readinessService->getReadinessDiagnostics();

        $sectionErrors = (array)($sections['meta']['errors'] ?? []);
        $entryErrors = (array)($entries['meta']['errors'] ?? []);
        foreach ($sectionErrors as $error) {
            $errors[] = 'sections: ' . (string)$error;
        }
        foreach ($entryErrors as $error) {
            $errors[] = 'entries: ' . (string)$error;
        }

        if ($commerceEnabled) {
            $products = $readinessService->getProductsList(['limit' => 1, 'status' => 'all']);
            $orders = $readinessService->getOrdersList(['limit' => 1, 'status' => 'all', 'lastDays' => 30]);
            foreach ((array)($products['meta']['errors'] ?? []) as $error) {
                $errors[] = 'products: ' . (string)$error;
            }
            foreach ((array)($orders['meta']['errors'] ?? []) as $error) {
                $errors[] = 'orders: ' . (string)$error;
            }
        } else {
            $warnings[] = 'Commerce plugin unavailable; products/orders smoke checks skipped.';
        }

        foreach ((array)($readiness['warnings'] ?? []) as $warning) {
            $message = (string)$warning;
            if (str_contains($message, 'Web request context available')) {
                continue;
            }
            $warnings[] = 'readiness: ' . $message;
        }

        $summary = [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => [
                'sections' => [
                    'count' => (int)($sections['meta']['count'] ?? 0),
                    'errors' => $sectionErrors,
                ],
                'entries' => [
                    'count' => (int)($entries['meta']['count'] ?? 0),
                    'errors' => $entryErrors,
                ],
                'readiness' => [
                    'status' => (string)($readiness['status'] ?? 'unknown'),
                    'readinessScore' => (int)($readiness['readinessScore'] ?? 0),
                ],
                'commerceChecksEnabled' => $commerceEnabled,
            ],
        ];

        return $this->formatCheckResult('smoke', $summary, $errors, $warnings);
    }

    private function formatCheckResult(string $check, array $details, array $errors, array $warnings): array
    {
        $normalizedErrors = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $errors
        ), static fn(string $message): bool => $message !== '')));
        $normalizedWarnings = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $warnings
        ), static fn(string $message): bool => $message !== '')));

        sort($normalizedErrors);
        sort($normalizedWarnings);

        return [
            'check' => $check,
            'status' => empty($normalizedErrors) ? 'ok' : 'failed',
            'strict' => false,
            'errors' => $normalizedErrors,
            'warnings' => $normalizedWarnings,
            'details' => $details,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function buildCheckSummary(array $checks): array
    {
        $checksTotal = 0;
        $checksFailed = 0;
        $warningsTotal = 0;
        $errorsTotal = 0;

        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $checksTotal++;
            $errors = (array)($check['errors'] ?? []);
            $warnings = (array)($check['warnings'] ?? []);
            $errorsTotal += count($errors);
            $warningsTotal += count($warnings);
            if (((string)($check['status'] ?? 'failed')) !== 'ok') {
                $checksFailed++;
            }
        }

        return [
            'checksTotal' => $checksTotal,
            'checksFailed' => $checksFailed,
            'checksPassed' => max(0, $checksTotal - $checksFailed),
            'errorsTotal' => $errorsTotal,
            'warningsTotal' => $warningsTotal,
            'status' => $checksFailed === 0 ? 'ok' : 'failed',
        ];
    }

    private function summarizeDeadLetterEvents(array $events, int $maxEvents = 50): array
    {
        $summary = [
            'total' => count($events),
            'failed' => 0,
            'queued' => 0,
            'events' => [],
        ];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $status = strtolower(trim((string)($event['status'] ?? 'failed')));
            if ($status === 'queued') {
                $summary['queued']++;
            } else {
                $summary['failed']++;
            }
        }

        foreach (array_slice($events, 0, max(1, $maxEvents)) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $payload = (array)($event['payload'] ?? []);
            $summary['events'][] = [
                'id' => (int)($event['id'] ?? 0),
                'eventId' => (string)($event['eventId'] ?? ''),
                'resourceType' => (string)($event['resourceType'] ?? ''),
                'resourceId' => (string)($event['resourceId'] ?? ''),
                'action' => (string)($event['action'] ?? ''),
                'status' => (string)($event['status'] ?? 'failed'),
                'attempts' => (int)($event['attempts'] ?? 0),
                'lastError' => $this->normalizeOptionalString($event['lastError'] ?? null, 1024),
                'dateCreated' => (string)($event['dateCreated'] ?? ''),
                'dateUpdated' => (string)($event['dateUpdated'] ?? ''),
                'payloadMeta' => [
                    'id' => (string)($payload['id'] ?? ''),
                    'occurredAt' => (string)($payload['occurredAt'] ?? ''),
                    'resourceType' => (string)($payload['resourceType'] ?? ''),
                    'resourceId' => (string)($payload['resourceId'] ?? ''),
                    'action' => (string)($payload['action'] ?? ''),
                    'updatedAt' => (string)($payload['updatedAt'] ?? ''),
                ],
            ];
        }

        return $summary;
    }

    private function resolvePluginVersion(Plugin $plugin): string
    {
        $version = trim((string)$plugin->getVersion());
        if ($version !== '') {
            return $version;
        }

        $schemaVersion = trim((string)$plugin->schemaVersion);
        if ($schemaVersion !== '') {
            return $schemaVersion;
        }

        return '0.9.2';
    }

    private function normalizeOptionalString(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $normalized = trim((string)$value);
        if ($normalized === '') {
            return null;
        }

        if (strlen($normalized) > $maxLength) {
            $normalized = substr($normalized, 0, $maxLength);
        }

        return $normalized;
    }
}
