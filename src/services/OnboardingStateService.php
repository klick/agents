<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use yii\db\Query;

class OnboardingStateService extends Component
{
    private const TABLE = '{{%agents_onboarding_state}}';

    public function getCpState(array $defaultScopes, ?string $previewStage = null): array
    {
        $credentials = $this->loadManagedCredentials($defaultScopes);
        $persistedState = $this->loadPersistedState($credentials);
        $state = $this->buildStatePayload($persistedState, $credentials);
        $normalizedPreviewStage = $this->normalizePreviewStage($previewStage);

        if ($normalizedPreviewStage !== null) {
            return $this->buildPreviewState($state, $normalizedPreviewStage);
        }

        return $state;
    }

    public function markFirstAccountCreated(?string $timestamp = null): void
    {
        $this->updateSingleRowState(function(array $row) use ($timestamp): array {
            if (($row['firstAccountCreatedAt'] ?? null) === null) {
                $row['firstAccountCreatedAt'] = $timestamp ?? gmdate('Y-m-d H:i:s');
            }

            return $row;
        });
    }

    public function markDismissed(?string $timestamp = null): void
    {
        $this->updateSingleRowState(function(array $row) use ($timestamp): array {
            if (($row['completedAt'] ?? null) === null && ($row['firstSuccessfulAuthAt'] ?? null) === null) {
                $row['dismissedAt'] = $timestamp ?? gmdate('Y-m-d H:i:s');
            }

            return $row;
        });
    }

    public function isOnboardingActive(array $defaultScopes, ?string $previewStage = null): bool
    {
        $state = $this->getCpState($defaultScopes, $previewStage);
        return (bool)($state['active'] ?? false);
    }

    public function normalizePreviewStage(?string $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'welcome', 'start' => 'welcome',
            'create', 'account', 'form', 'bootstrap' => 'create',
            'ready', 'finish', 'finished', 'complete', 'completed' => 'ready',
            default => null,
        };
    }

    private function loadManagedCredentials(array $defaultScopes): array
    {
        try {
            return \Klick\Agents\Plugin::getInstance()->getCredentialService()->getManagedCredentials($defaultScopes);
        } catch (\Throwable $e) {
            Craft::warning('Unable to load onboarding credentials snapshot: ' . $e->getMessage(), __METHOD__);
            return [];
        }
    }

    private function loadPersistedState(array $credentials): array
    {
        if (!$this->stateTableExists()) {
            return $this->buildSyntheticStateRow($credentials);
        }

        $row = (new Query())
            ->from(self::TABLE)
            ->orderBy(['id' => SORT_ASC])
            ->one();

        if (!is_array($row)) {
            $row = $this->createInitialStateRow();
        }

        $row = $this->backfillStateFromCredentials($row, $credentials);

        return $row;
    }

    private function buildStatePayload(array $row, array $credentials): array
    {
        $managedCredentials = $this->normalizeManagedCredentials($credentials);
        $firstCredential = $managedCredentials[0] ?? null;
        $managedCredentialCount = count($managedCredentials);
        $firstSuccessfulAuthAt = $this->normalizeTimestampValue($row['firstSuccessfulAuthAt'] ?? null);
        $dismissedAt = $this->normalizeTimestampValue($row['dismissedAt'] ?? null);
        $completedAt = $this->normalizeTimestampValue($row['completedAt'] ?? null);
        $active = $dismissedAt === null && $completedAt === null && $firstSuccessfulAuthAt === null;

        return [
            'active' => $active,
            'preview' => false,
            'previewStage' => null,
            'stage' => $active
                ? ($managedCredentialCount > 0 ? 'ready' : 'welcome')
                : 'completed',
            'startedAt' => $this->normalizeTimestampValue($row['startedAt'] ?? null),
            'firstAccountCreatedAt' => $this->normalizeTimestampValue($row['firstAccountCreatedAt'] ?? null),
            'firstSuccessfulAuthAt' => $firstSuccessfulAuthAt,
            'dismissedAt' => $dismissedAt,
            'completedAt' => $completedAt,
            'managedCredentialCount' => $managedCredentialCount,
            'firstCredential' => $firstCredential,
            'managedCredentials' => $managedCredentials,
        ];
    }

    private function buildPreviewState(array $liveState, string $previewStage): array
    {
        $previewFirstCredential = [
            'id' => 1,
            'handle' => 'agent-change-audit',
            'displayName' => 'Operations integration',
            'description' => 'What this account is for',
        ];

        return [
            'active' => true,
            'preview' => true,
            'previewStage' => $previewStage,
            'stage' => $previewStage,
            'startedAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime('-5 minutes')),
            'firstAccountCreatedAt' => $previewStage === 'ready' ? gmdate('Y-m-d\TH:i:s\Z', strtotime('-2 minutes')) : null,
            'firstSuccessfulAuthAt' => null,
            'dismissedAt' => null,
            'completedAt' => null,
            'managedCredentialCount' => $previewStage === 'ready' ? 1 : 0,
            'firstCredential' => $previewStage === 'ready' ? $previewFirstCredential : null,
            'managedCredentials' => $previewStage === 'ready' ? [$previewFirstCredential] : [],
            'liveState' => $liveState,
        ];
    }

    private function backfillStateFromCredentials(array $row, array $credentials): array
    {
        if (!$this->stateTableExists()) {
            return $row;
        }

        $normalizedCredentials = $this->normalizeManagedCredentials($credentials);
        $updates = [];

        if (($row['firstAccountCreatedAt'] ?? null) === null && count($normalizedCredentials) > 0) {
            $firstCredentialCreatedAt = $this->resolveEarliestCredentialTimestamp($normalizedCredentials, 'dateCreated');
            if ($firstCredentialCreatedAt !== null) {
                $updates['firstAccountCreatedAt'] = $firstCredentialCreatedAt;
            }
        }

        if (($row['firstSuccessfulAuthAt'] ?? null) === null) {
            $firstSuccessfulAuthAt = $this->resolveEarliestCredentialTimestamp($normalizedCredentials, 'lastUsedAt');
            if ($firstSuccessfulAuthAt !== null) {
                $updates['firstSuccessfulAuthAt'] = $firstSuccessfulAuthAt;
                if (($row['completedAt'] ?? null) === null) {
                    $updates['completedAt'] = $firstSuccessfulAuthAt;
                }
            }
        }

        if (!$updates) {
            return $row;
        }

        $updates['dateUpdated'] = gmdate('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()
            ->update(self::TABLE, $updates, ['id' => (int)$row['id']])
            ->execute();

        return array_merge($row, $updates);
    }

    private function createInitialStateRow(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'startedAt' => $now,
            'firstAccountCreatedAt' => null,
            'firstSuccessfulAuthAt' => null,
            'dismissedAt' => null,
            'completedAt' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => \craft\helpers\StringHelper::UUID(),
        ];

        $db = Craft::$app->getDb();
        $db->createCommand()->insert(self::TABLE, $row)->execute();
        $row['id'] = (int)$db->getLastInsertID();

        return $row;
    }

    private function updateSingleRowState(callable $mutator): void
    {
        if (!$this->stateTableExists()) {
            return;
        }

        $row = (new Query())
            ->from(self::TABLE)
            ->orderBy(['id' => SORT_ASC])
            ->one();

        if (!is_array($row)) {
            $row = $this->createInitialStateRow();
        }

        $updatedRow = $mutator($row);
        if (!is_array($updatedRow)) {
            return;
        }

        unset($updatedRow['id'], $updatedRow['uid'], $updatedRow['dateCreated']);
        $updatedRow['dateUpdated'] = gmdate('Y-m-d H:i:s');

        Craft::$app->getDb()->createCommand()
            ->update(self::TABLE, $updatedRow, ['id' => (int)$row['id']])
            ->execute();
    }

    private function buildSyntheticStateRow(array $credentials): array
    {
        $normalizedCredentials = $this->normalizeManagedCredentials($credentials);
        $firstAccountCreatedAt = $this->resolveEarliestCredentialTimestamp($normalizedCredentials, 'dateCreated');
        $firstSuccessfulAuthAt = $this->resolveEarliestCredentialTimestamp($normalizedCredentials, 'lastUsedAt');

        return [
            'id' => 0,
            'startedAt' => $firstAccountCreatedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
            'firstAccountCreatedAt' => $firstAccountCreatedAt,
            'firstSuccessfulAuthAt' => $firstSuccessfulAuthAt,
            'dismissedAt' => null,
            'completedAt' => $firstSuccessfulAuthAt,
        ];
    }

    private function normalizeManagedCredentials(array $credentials): array
    {
        $normalized = array_values(array_filter($credentials, static fn(mixed $credential): bool => is_array($credential) && !(bool)($credential['revoked'] ?? false)));

        usort($normalized, function(array $left, array $right): int {
            $leftTimestamp = strtotime((string)($left['dateCreated'] ?? '')) ?: PHP_INT_MAX;
            $rightTimestamp = strtotime((string)($right['dateCreated'] ?? '')) ?: PHP_INT_MAX;

            if ($leftTimestamp === $rightTimestamp) {
                return strcmp((string)($left['handle'] ?? ''), (string)($right['handle'] ?? ''));
            }

            return $leftTimestamp <=> $rightTimestamp;
        });

        return $normalized;
    }

    private function resolveEarliestCredentialTimestamp(array $credentials, string $field): ?string
    {
        $timestamps = [];
        foreach ($credentials as $credential) {
            if (!is_array($credential)) {
                continue;
            }

            $value = $this->normalizeTimestampValue($credential[$field] ?? null);
            if ($value !== null) {
                $timestamps[] = strtotime($value);
            }
        }

        if ($timestamps === []) {
            return null;
        }

        sort($timestamps);
        return gmdate('Y-m-d\TH:i:s\Z', (int)$timestamps[0]);
    }

    private function normalizeTimestampValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function stateTableExists(): bool
    {
        return Craft::$app->getDb()->tableExists(self::TABLE);
    }
}
