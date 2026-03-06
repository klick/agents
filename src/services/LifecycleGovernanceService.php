<?php

namespace Klick\Agents\services;

use craft\base\Component;
use craft\helpers\App;
use Klick\Agents\Plugin;

class LifecycleGovernanceService extends Component
{
    private const STALE_UNUSED_WARN_DAYS = 30;
    private const STALE_UNUSED_CRITICAL_DAYS = 90;
    private const STALE_NEVER_USED_WARN_DAYS = 30;
    private const STALE_NEVER_USED_CRITICAL_DAYS = 90;
    private const ROTATION_WARN_DAYS = 45;
    private const ROTATION_CRITICAL_DAYS = 120;
    private const METADATA_ENV_KEY = 'PLUGIN_AGENTS_LIFECYCLE_METADATA_MAP';

    public function getSnapshot(): array
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return [
                'service' => 'agents',
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'status' => 'unknown',
                'summary' => [],
                'topRisks' => [],
                'agents' => [],
            ];
        }

        $securityConfig = $plugin->getSecurityPolicyService()->getRuntimeConfig();
        $defaultScopes = (array)($securityConfig['tokenScopes'] ?? []);
        $managedCredentials = $plugin->getCredentialService()->getManagedCredentials($defaultScopes);
        $metadataMap = $this->parseMetadataMap((string)App::env(self::METADATA_ENV_KEY));

        $thresholds = [
            'staleUnusedWarnDays' => $this->resolvePositiveIntEnv('PLUGIN_AGENTS_LIFECYCLE_STALE_UNUSED_WARN_DAYS', self::STALE_UNUSED_WARN_DAYS),
            'staleUnusedCriticalDays' => $this->resolvePositiveIntEnv('PLUGIN_AGENTS_LIFECYCLE_STALE_UNUSED_CRITICAL_DAYS', self::STALE_UNUSED_CRITICAL_DAYS),
            'staleNeverUsedWarnDays' => $this->resolvePositiveIntEnv('PLUGIN_AGENTS_LIFECYCLE_STALE_NEVER_USED_WARN_DAYS', self::STALE_NEVER_USED_WARN_DAYS),
            'staleNeverUsedCriticalDays' => $this->resolvePositiveIntEnv('PLUGIN_AGENTS_LIFECYCLE_STALE_NEVER_USED_CRITICAL_DAYS', self::STALE_NEVER_USED_CRITICAL_DAYS),
            'rotationWarnDays' => $this->resolvePositiveIntEnv('PLUGIN_AGENTS_LIFECYCLE_ROTATION_WARN_DAYS', self::ROTATION_WARN_DAYS),
            'rotationCriticalDays' => $this->resolvePositiveIntEnv('PLUGIN_AGENTS_LIFECYCLE_ROTATION_CRITICAL_DAYS', self::ROTATION_CRITICAL_DAYS),
        ];

        $rows = [];
        $summary = [
            'total' => 0,
            'active' => 0,
            'paused' => 0,
            'revoked' => 0,
            'expired' => 0,
            'riskWarn' => 0,
            'riskCritical' => 0,
            'staleWarn' => 0,
            'staleCritical' => 0,
            'missingOwner' => 0,
            'metadataMapped' => 0,
        ];

        foreach ($managedCredentials as $credential) {
            if (!is_array($credential)) {
                continue;
            }

            $row = $this->buildLifecycleRow($credential, $metadataMap, $thresholds, (string)($securityConfig['environmentProfile'] ?? 'unknown'));
            $rows[] = $row;

            $summary['total']++;
            $mode = strtolower((string)($row['mode'] ?? 'active'));
            if ($mode === 'paused') {
                $summary['paused']++;
            } elseif ($mode === 'revoked') {
                $summary['revoked']++;
            } elseif ($mode === 'expired') {
                $summary['expired']++;
            } else {
                $summary['active']++;
            }

            $riskLevel = (string)($row['risk']['level'] ?? 'ok');
            if ($riskLevel === 'critical') {
                $summary['riskCritical']++;
            } elseif ($riskLevel === 'warn') {
                $summary['riskWarn']++;
            }

            $staleLevel = (string)($row['risk']['stale']['level'] ?? 'ok');
            if ($staleLevel === 'critical') {
                $summary['staleCritical']++;
            } elseif ($staleLevel === 'warn') {
                $summary['staleWarn']++;
            }

            $owner = trim((string)($row['metadata']['owner'] ?? ''));
            if ($owner === '' || $owner === 'unassigned') {
                $summary['missingOwner']++;
            }

            if ((string)($row['metadata']['source'] ?? '') === 'map') {
                $summary['metadataMapped']++;
            }
        }

        usort($rows, function(array $left, array $right): int {
            $riskCmp = $this->riskRank((string)($right['risk']['level'] ?? 'ok'))
                <=> $this->riskRank((string)($left['risk']['level'] ?? 'ok'));
            if ($riskCmp !== 0) {
                return $riskCmp;
            }

            return strcmp(
                strtolower((string)($left['handle'] ?? '')),
                strtolower((string)($right['handle'] ?? ''))
            );
        });

        $topRisks = array_values(array_filter($rows, static function(array $row): bool {
            return in_array((string)($row['risk']['level'] ?? 'ok'), ['warn', 'critical'], true);
        }));

        $status = 'ok';
        if ($summary['riskCritical'] > 0) {
            $status = 'critical';
        } elseif ($summary['riskWarn'] > 0) {
            $status = 'warn';
        }

        return [
            'service' => 'agents',
            'version' => $this->resolvePluginVersion(),
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'status' => $status,
            'runtime' => [
                'environment' => (string)($securityConfig['environment'] ?? 'unknown'),
                'environmentProfile' => (string)($securityConfig['environmentProfile'] ?? 'unknown'),
                'environmentProfileSource' => (string)($securityConfig['environmentProfileSource'] ?? 'unknown'),
            ],
            'thresholds' => $thresholds,
            'summary' => $summary,
            'topRisks' => array_slice($topRisks, 0, 20),
            'agents' => $rows,
        ];
    }

    private function buildLifecycleRow(array $credential, array $metadataMap, array $thresholds, string $runtimeProfile): array
    {
        $handle = trim((string)($credential['handle'] ?? ''));
        $normalizedHandle = strtolower($handle);
        $metadata = $this->resolveMetadata($credential, $metadataMap[$normalizedHandle] ?? [], $runtimeProfile);

        $lastUsedAt = $this->normalizeDate((string)($credential['lastUsedAt'] ?? ''));
        $createdAt = $this->normalizeDate((string)($credential['dateCreated'] ?? ''));
        $rotatedAt = $this->normalizeDate((string)($credential['rotatedAt'] ?? ''));
        $mode = strtolower(trim((string)($credential['mode'] ?? 'active')));
        if ($mode === '') {
            $mode = 'active';
        }

        $nowTs = time();
        $lastUsedTs = $this->toTimestamp($lastUsedAt);
        $createdTs = $this->toTimestamp($createdAt);
        $rotatedTs = $this->toTimestamp($rotatedAt);
        $daysSinceLastUsed = $lastUsedTs !== null ? max(0, (int)floor(($nowTs - $lastUsedTs) / 86400)) : null;
        $daysSinceCreated = $createdTs !== null ? max(0, (int)floor(($nowTs - $createdTs) / 86400)) : null;
        $daysSinceRotated = $rotatedTs !== null
            ? max(0, (int)floor(($nowTs - $rotatedTs) / 86400))
            : $daysSinceCreated;

        $factors = [];

        $expiryStatus = strtolower((string)($credential['expiryStatus'] ?? 'none'));
        if ($expiryStatus === 'expired') {
            $factors[] = $this->factor('expired', 'critical', 'Credential is expired and unusable.');
        } elseif ($expiryStatus === 'expiring_soon') {
            $daysLeft = $credential['expiresInDays'] ?? null;
            $factors[] = $this->factor(
                'expiring_soon',
                'warn',
                is_numeric($daysLeft)
                    ? sprintf('Credential expires in %d day(s).', (int)$daysLeft)
                    : 'Credential is expiring soon.'
            );
        } elseif ($expiryStatus === 'none') {
            $factors[] = $this->factor('no_expiry_policy', 'warn', 'No expiry policy configured for this credential.');
        }

        if ($mode === 'revoked') {
            $factors[] = $this->factor('revoked', 'warn', 'Credential is revoked.');
        } elseif ($mode === 'paused') {
            $factors[] = $this->factor('paused', 'warn', 'Credential is paused.');
        }

        $staleFactor = $this->buildStaleFactor($daysSinceLastUsed, $daysSinceCreated, $thresholds);
        if ($staleFactor !== null) {
            $factors[] = $staleFactor;
        }

        if ($daysSinceRotated !== null) {
            if ($daysSinceRotated >= (int)$thresholds['rotationCriticalDays']) {
                $factors[] = $this->factor(
                    'rotation_overdue',
                    'critical',
                    sprintf('Credential not rotated for %d day(s).', $daysSinceRotated)
                );
            } elseif ($daysSinceRotated >= (int)$thresholds['rotationWarnDays']) {
                $factors[] = $this->factor(
                    'rotation_due',
                    'warn',
                    sprintf('Credential not rotated for %d day(s).', $daysSinceRotated)
                );
            }
        }

        $mappedEnvironment = strtolower(trim((string)($metadata['environment'] ?? '')));
        if ($mappedEnvironment !== '' && $mappedEnvironment !== 'any' && $mappedEnvironment !== strtolower($runtimeProfile)) {
            $factors[] = $this->factor(
                'environment_mismatch',
                'warn',
                sprintf('Metadata environment `%s` differs from runtime profile `%s`.', $mappedEnvironment, $runtimeProfile)
            );
        }

        $riskLevel = 'ok';
        foreach ($factors as $factor) {
            $riskLevel = $this->maxRiskLevel($riskLevel, (string)($factor['level'] ?? 'ok'));
        }

        $recommendedAction = 'No immediate action required.';
        if ($riskLevel !== 'ok') {
            $recommendedAction = (string)($factors[0]['message'] ?? 'Review credential lifecycle posture.');
            foreach ($factors as $factor) {
                if ((string)($factor['level'] ?? 'ok') === 'critical') {
                    $recommendedAction = (string)($factor['message'] ?? $recommendedAction);
                    break;
                }
            }
        }

        return [
            'credentialId' => (int)($credential['id'] ?? 0),
            'handle' => $handle,
            'displayName' => (string)($credential['displayName'] ?? $handle),
            'mode' => $mode,
            'expiryStatus' => $expiryStatus,
            'expiresInDays' => is_numeric($credential['expiresInDays'] ?? null) ? (int)$credential['expiresInDays'] : null,
            'lastUsedAt' => $lastUsedAt,
            'daysSinceLastUsed' => $daysSinceLastUsed,
            'rotatedAt' => $rotatedAt,
            'daysSinceRotated' => $daysSinceRotated,
            'dateCreated' => $createdAt,
            'daysSinceCreated' => $daysSinceCreated,
            'metadata' => $metadata,
            'risk' => [
                'level' => $riskLevel,
                'factors' => $factors,
                'recommendedAction' => $recommendedAction,
                'stale' => $staleFactor !== null ? [
                    'level' => (string)($staleFactor['level'] ?? 'ok'),
                    'message' => (string)($staleFactor['message'] ?? ''),
                ] : [
                    'level' => 'ok',
                    'message' => '',
                ],
            ],
        ];
    }

    private function buildStaleFactor(?int $daysSinceLastUsed, ?int $daysSinceCreated, array $thresholds): ?array
    {
        if ($daysSinceLastUsed === null) {
            if ($daysSinceCreated === null) {
                return $this->factor('never_used', 'warn', 'Credential has not been used yet.');
            }
            if ($daysSinceCreated >= (int)$thresholds['staleNeverUsedCriticalDays']) {
                return $this->factor('never_used', 'critical', sprintf('Credential was never used (%d day(s) since creation).', $daysSinceCreated));
            }
            if ($daysSinceCreated >= (int)$thresholds['staleNeverUsedWarnDays']) {
                return $this->factor('never_used', 'warn', sprintf('Credential was never used (%d day(s) since creation).', $daysSinceCreated));
            }
            return null;
        }

        if ($daysSinceLastUsed >= (int)$thresholds['staleUnusedCriticalDays']) {
            return $this->factor('stale_usage', 'critical', sprintf('Credential has been inactive for %d day(s).', $daysSinceLastUsed));
        }
        if ($daysSinceLastUsed >= (int)$thresholds['staleUnusedWarnDays']) {
            return $this->factor('stale_usage', 'warn', sprintf('Credential has been inactive for %d day(s).', $daysSinceLastUsed));
        }

        return null;
    }

    private function resolveMetadata(array $credential, array $mappedMetadata, string $runtimeProfile): array
    {
        $handle = trim((string)($credential['handle'] ?? ''));
        $fallbackUseCase = trim((string)($credential['displayName'] ?? $handle));
        if ($fallbackUseCase === '') {
            $fallbackUseCase = $handle;
        }

        $credentialOwner = trim((string)($credential['owner'] ?? ''));
        $mappedOwner = trim((string)($mappedMetadata['owner'] ?? ''));
        $owner = $credentialOwner !== '' ? $credentialOwner : $mappedOwner;
        $useCase = trim((string)($mappedMetadata['useCase'] ?? $mappedMetadata['usecase'] ?? ''));
        $environment = trim((string)($mappedMetadata['environment'] ?? ''));
        $source = 'inferred';

        if ($mappedOwner !== '' || $useCase !== '' || $environment !== '') {
            $source = 'map';
        } elseif ($credentialOwner !== '') {
            $source = 'credential';
        }

        if ($owner === '') {
            $owner = 'unassigned';
        }
        if ($useCase === '') {
            $useCase = $fallbackUseCase;
        }
        if ($environment === '') {
            $environment = $runtimeProfile !== '' ? $runtimeProfile : 'unknown';
        }

        return [
            'owner' => $owner,
            'useCase' => $useCase,
            'environment' => strtolower($environment),
            'source' => $source,
        ];
    }

    private function parseMetadataMap(string $raw): array
    {
        $value = trim($raw);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $handle => $metadata) {
            if ((!is_string($handle) && !is_numeric($handle)) || !is_array($metadata)) {
                continue;
            }

            $normalizedHandle = strtolower(trim((string)$handle));
            if ($normalizedHandle === '') {
                continue;
            }

            $map[$normalizedHandle] = [
                'owner' => trim((string)($metadata['owner'] ?? '')),
                'useCase' => trim((string)($metadata['useCase'] ?? $metadata['usecase'] ?? '')),
                'environment' => trim((string)($metadata['environment'] ?? '')),
            ];
        }

        return $map;
    }

    private function factor(string $id, string $level, string $message): array
    {
        return [
            'id' => $id,
            'level' => $level,
            'message' => $message,
        ];
    }

    private function maxRiskLevel(string $left, string $right): string
    {
        return $this->riskRank($right) > $this->riskRank($left) ? $right : $left;
    }

    private function riskRank(string $level): int
    {
        return match (strtolower(trim($level))) {
            'critical' => 3,
            'warn' => 2,
            default => 1,
        };
    }

    private function normalizeDate(string $value): ?string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $timestamp = strtotime($normalized);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function toTimestamp(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    private function resolvePositiveIntEnv(string $key, int $default): int
    {
        $raw = App::env($key);
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $default;
        }

        return max(1, (int)$raw);
    }

    private function resolvePluginVersion(): string
    {
        $plugin = Plugin::getInstance();
        if ($plugin !== null) {
            $version = trim((string)$plugin->getVersion());
            if ($version !== '') {
                return $version;
            }

            $schemaVersion = trim((string)$plugin->schemaVersion);
            if ($schemaVersion !== '') {
                return $schemaVersion;
            }
        }

        return '0.9.2';
    }
}
