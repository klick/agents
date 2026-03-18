<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use yii\db\Query;

class TargetSetService extends Component
{
    private const TABLE_TARGET_SETS = '{{%agents_target_sets}}';
    private const TABLE_CREDENTIAL_TARGET_SETS = '{{%agents_credential_target_sets}}';
    private const TABLE_CREDENTIALS = '{{%agents_credentials}}';
    private const DEFAULT_ALLOWED_ACTION_TYPES = ['entry.updatedraft'];

    public function getTargetSets(): array
    {
        if (!$this->targetSetsTableExists()) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE_TARGET_SETS)
            ->orderBy(['name' => SORT_ASC, 'handle' => SORT_ASC])
            ->all();

        return $this->hydrateTargetSets($rows);
    }

    public function getTargetSetsForCredentialId(int $credentialId): array
    {
        if ($credentialId <= 0 || !$this->relationTableExists()) {
            return [];
        }

        return $this->getTargetSetsByCredentialIds([$credentialId])[$credentialId] ?? [];
    }

    public function getTargetSetsByCredentialIds(array $credentialIds): array
    {
        $normalizedCredentialIds = $this->normalizeIntegerIds($credentialIds);
        if (empty($normalizedCredentialIds) || !$this->relationTableExists()) {
            return [];
        }

        $relationRows = (new Query())
            ->from(self::TABLE_CREDENTIAL_TARGET_SETS)
            ->where(['credentialId' => $normalizedCredentialIds])
            ->orderBy(['credentialId' => SORT_ASC, 'targetSetId' => SORT_ASC])
            ->all();

        if (empty($relationRows)) {
            return [];
        }

        $targetSetIds = [];
        foreach ($relationRows as $row) {
            $targetSetId = (int)($row['targetSetId'] ?? 0);
            if ($targetSetId > 0 && !in_array($targetSetId, $targetSetIds, true)) {
                $targetSetIds[] = $targetSetId;
            }
        }

        $targetSetsById = $this->getTargetSetsByIds($targetSetIds);
        $map = [];
        foreach ($relationRows as $row) {
            $credentialId = (int)($row['credentialId'] ?? 0);
            $targetSetId = (int)($row['targetSetId'] ?? 0);
            if ($credentialId <= 0 || $targetSetId <= 0 || !isset($targetSetsById[$targetSetId])) {
                continue;
            }
            if (!isset($map[$credentialId])) {
                $map[$credentialId] = [];
            }
            $map[$credentialId][] = $targetSetsById[$targetSetId];
        }

        return $map;
    }

    public function createTargetSet(
        string $handle,
        string $name,
        string $description,
        array $allowedEntryIds,
        array $allowedSiteIds,
        array $allowedActionTypes = self::DEFAULT_ALLOWED_ACTION_TYPES
    ): array {
        if (!$this->targetSetsTableExists()) {
            throw new \RuntimeException('Target set storage table is unavailable. Run plugin migrations.');
        }

        $normalizedHandle = $this->normalizeHandle($handle);
        if ($normalizedHandle === '') {
            throw new \InvalidArgumentException('Target set handle is required and may only contain letters, digits, dashes, underscores, colons, and periods.');
        }

        $normalizedName = $this->normalizeName($name, $normalizedHandle);
        $normalizedDescription = $this->normalizeDescription($description);
        $normalizedAllowedEntryIds = $this->validateAllowedEntryIds($allowedEntryIds);
        $normalizedAllowedSiteIds = $this->validateAllowedSiteIds($allowedSiteIds);
        $normalizedAllowedActionTypes = $this->normalizeAllowedActionTypes($allowedActionTypes);

        $exists = (new Query())
            ->from(self::TABLE_TARGET_SETS)
            ->where(['handle' => $normalizedHandle])
            ->exists();
        if ($exists) {
            throw new \InvalidArgumentException(sprintf('Target set `%s` already exists.', $normalizedHandle));
        }

        $now = gmdate('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()->insert(self::TABLE_TARGET_SETS, [
            'handle' => $normalizedHandle,
            'name' => $normalizedName,
            'description' => $normalizedDescription !== '' ? $normalizedDescription : null,
            'allowedActionTypes' => $this->encodeJson($normalizedAllowedActionTypes),
            'allowedEntryIds' => $this->encodeJson($normalizedAllowedEntryIds),
            'allowedSiteIds' => $this->encodeJson($normalizedAllowedSiteIds),
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $id = (int)Craft::$app->getDb()->getLastInsertID();
        $targetSet = $this->getTargetSetById($id);
        if ($targetSet === null) {
            throw new \RuntimeException('Unable to load newly created target set.');
        }

        return $targetSet;
    }

    public function updateTargetSet(
        int $id,
        string $name,
        string $description,
        array $allowedEntryIds,
        array $allowedSiteIds,
        array $allowedActionTypes = self::DEFAULT_ALLOWED_ACTION_TYPES
    ): ?array {
        if ($id <= 0 || !$this->targetSetsTableExists()) {
            return null;
        }

        $existing = (new Query())
            ->from(self::TABLE_TARGET_SETS)
            ->where(['id' => $id])
            ->one();
        if (!is_array($existing)) {
            return null;
        }

        $normalizedName = $this->normalizeName($name, (string)($existing['handle'] ?? 'target-set'));
        $normalizedDescription = $this->normalizeDescription($description);
        $normalizedAllowedEntryIds = $this->validateAllowedEntryIds($allowedEntryIds);
        $normalizedAllowedSiteIds = $this->validateAllowedSiteIds($allowedSiteIds);
        $normalizedAllowedActionTypes = $this->normalizeAllowedActionTypes($allowedActionTypes);

        Craft::$app->getDb()->createCommand()->update(self::TABLE_TARGET_SETS, [
            'name' => $normalizedName,
            'description' => $normalizedDescription !== '' ? $normalizedDescription : null,
            'allowedActionTypes' => $this->encodeJson($normalizedAllowedActionTypes),
            'allowedEntryIds' => $this->encodeJson($normalizedAllowedEntryIds),
            'allowedSiteIds' => $this->encodeJson($normalizedAllowedSiteIds),
            'dateUpdated' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id])->execute();

        return $this->getTargetSetById($id);
    }

    public function deleteTargetSet(int $id): bool
    {
        if ($id <= 0 || !$this->targetSetsTableExists()) {
            return false;
        }

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE_TARGET_SETS, ['id' => $id])
            ->execute();

        return $deleted > 0;
    }

    public function saveCredentialTargetSetAssignments(int $credentialId, array $targetSetIds): void
    {
        if ($credentialId <= 0 || !$this->relationTableExists()) {
            return;
        }

        $normalizedTargetSetIds = $this->normalizeIntegerIds($targetSetIds);
        if (!empty($normalizedTargetSetIds)) {
            $knownTargetSetIds = array_keys($this->getTargetSetsByIds($normalizedTargetSetIds));
            sort($knownTargetSetIds);
            if ($knownTargetSetIds !== $normalizedTargetSetIds) {
                $missing = array_values(array_diff($normalizedTargetSetIds, $knownTargetSetIds));
                throw new \InvalidArgumentException(sprintf(
                    'Unknown target set IDs: %s.',
                    implode(', ', $missing)
                ));
            }
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand()
                ->delete(self::TABLE_CREDENTIAL_TARGET_SETS, ['credentialId' => $credentialId])
                ->execute();

            $now = gmdate('Y-m-d H:i:s');
            foreach ($normalizedTargetSetIds as $targetSetId) {
                $db->createCommand()->insert(self::TABLE_CREDENTIAL_TARGET_SETS, [
                    'credentialId' => $credentialId,
                    'targetSetId' => $targetSetId,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ])->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    public function resolveCredentialActionBounds(int $credentialId, string $actionType): array
    {
        $targetSets = $this->getTargetSetsForCredentialId($credentialId);
        if (empty($targetSets)) {
            return [
                'hasAssignments' => false,
                'matchingTargetSets' => [],
                'allowedEntryIds' => [],
                'allowedSiteIds' => [],
            ];
        }

        $normalizedActionType = $this->normalizeActionType($actionType);
        $matchingTargetSets = array_values(array_filter($targetSets, static function(array $targetSet) use ($normalizedActionType): bool {
            $allowedActionTypes = array_map(static fn($value): string => strtolower(trim((string)$value)), (array)($targetSet['allowedActionTypes'] ?? []));
            if (empty($allowedActionTypes)) {
                return false;
            }

            return in_array($normalizedActionType, $allowedActionTypes, true);
        }));

        $allowedEntryIds = [];
        $allowedSiteIds = [];
        foreach ($matchingTargetSets as $targetSet) {
            foreach ((array)($targetSet['allowedEntryIds'] ?? []) as $entryId) {
                $entryId = (int)$entryId;
                if ($entryId > 0 && !in_array($entryId, $allowedEntryIds, true)) {
                    $allowedEntryIds[] = $entryId;
                }
            }
            foreach ((array)($targetSet['allowedSiteIds'] ?? []) as $siteId) {
                $siteId = (int)$siteId;
                if ($siteId > 0 && !in_array($siteId, $allowedSiteIds, true)) {
                    $allowedSiteIds[] = $siteId;
                }
            }
        }

        sort($allowedEntryIds);
        sort($allowedSiteIds);

        return [
            'hasAssignments' => true,
            'matchingTargetSets' => $matchingTargetSets,
            'allowedEntryIds' => $allowedEntryIds,
            'allowedSiteIds' => $allowedSiteIds,
        ];
    }

    private function getTargetSetById(int $id): ?array
    {
        if ($id <= 0 || !$this->targetSetsTableExists()) {
            return null;
        }

        $rows = (new Query())
            ->from(self::TABLE_TARGET_SETS)
            ->where(['id' => $id])
            ->all();

        $hydrated = $this->hydrateTargetSets($rows);
        return $hydrated[0] ?? null;
    }

    private function getTargetSetsByIds(array $targetSetIds): array
    {
        $normalizedTargetSetIds = $this->normalizeIntegerIds($targetSetIds);
        if (empty($normalizedTargetSetIds) || !$this->targetSetsTableExists()) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE_TARGET_SETS)
            ->where(['id' => $normalizedTargetSetIds])
            ->orderBy(['name' => SORT_ASC, 'handle' => SORT_ASC])
            ->all();

        $byId = [];
        foreach ($this->hydrateTargetSets($rows) as $targetSet) {
            $targetSetId = (int)($targetSet['id'] ?? 0);
            if ($targetSetId > 0) {
                $byId[$targetSetId] = $targetSet;
            }
        }

        return $byId;
    }

    private function hydrateTargetSets(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $targetSetIds = [];
        $entryIds = [];
        foreach ($rows as $row) {
            $targetSetId = (int)($row['id'] ?? 0);
            if ($targetSetId > 0) {
                $targetSetIds[] = $targetSetId;
            }

            foreach ($this->decodeIntegerJsonArray($row['allowedEntryIds'] ?? null) as $entryId) {
                if (!in_array($entryId, $entryIds, true)) {
                    $entryIds[] = $entryId;
                }
            }
        }

        $assignmentRows = [];
        if ($this->relationTableExists() && !empty($targetSetIds)) {
            $assignmentRows = (new Query())
                ->from(self::TABLE_CREDENTIAL_TARGET_SETS)
                ->where(['targetSetId' => $targetSetIds])
                ->all();
        }

        $credentialIdsByTargetSetId = [];
        foreach ($assignmentRows as $assignmentRow) {
            $targetSetId = (int)($assignmentRow['targetSetId'] ?? 0);
            $credentialId = (int)($assignmentRow['credentialId'] ?? 0);
            if ($targetSetId <= 0 || $credentialId <= 0) {
                continue;
            }
            if (!isset($credentialIdsByTargetSetId[$targetSetId])) {
                $credentialIdsByTargetSetId[$targetSetId] = [];
            }
            if (!in_array($credentialId, $credentialIdsByTargetSetId[$targetSetId], true)) {
                $credentialIdsByTargetSetId[$targetSetId][] = $credentialId;
            }
        }

        $allAssignedCredentialIds = [];
        foreach ($credentialIdsByTargetSetId as $assignedCredentialIdsForTargetSet) {
            foreach ($assignedCredentialIdsForTargetSet as $assignedCredentialId) {
                if ($assignedCredentialId > 0 && !in_array($assignedCredentialId, $allAssignedCredentialIds, true)) {
                    $allAssignedCredentialIds[] = $assignedCredentialId;
                }
            }
        }

        $entrySummariesById = $this->loadEntrySummariesByIds($entryIds);
        $siteSummariesById = $this->loadSiteSummariesById();
        $assignedCredentialSummariesById = $this->loadCredentialSummariesByIds($allAssignedCredentialIds);
        $hydrated = [];
        foreach ($rows as $row) {
            $targetSetId = (int)($row['id'] ?? 0);
            $allowedEntryIds = $this->decodeIntegerJsonArray($row['allowedEntryIds'] ?? null);
            $allowedSiteIds = $this->decodeIntegerJsonArray($row['allowedSiteIds'] ?? null);
            $allowedActionTypes = $this->normalizeAllowedActionTypes($this->decodeStringJsonArray($row['allowedActionTypes'] ?? null));

            $allowedEntries = [];
            foreach ($allowedEntryIds as $entryId) {
                $allowedEntries[] = $entrySummariesById[$entryId] ?? [
                    'id' => $entryId,
                    'title' => sprintf('Entry #%d', $entryId),
                    'label' => sprintf('Entry #%d', $entryId),
                    'cpEditUrl' => null,
                ];
            }

            $allowedSites = [];
            foreach ($allowedSiteIds as $siteId) {
                $allowedSites[] = $siteSummariesById[$siteId] ?? [
                    'id' => $siteId,
                    'name' => sprintf('Site #%d', $siteId),
                    'handle' => '',
                    'label' => sprintf('Site #%d', $siteId),
                ];
            }

            $assignedCredentialIds = $credentialIdsByTargetSetId[$targetSetId] ?? [];
            sort($assignedCredentialIds);
            $assignedCredentials = [];
            foreach ($assignedCredentialIds as $credentialId) {
                if (!isset($assignedCredentialSummariesById[$credentialId])) {
                    continue;
                }
                $assignedCredentials[] = $assignedCredentialSummariesById[$credentialId];
            }

            $hydrated[] = [
                'id' => $targetSetId,
                'handle' => (string)($row['handle'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'description' => trim((string)($row['description'] ?? '')),
                'allowedActionTypes' => $allowedActionTypes,
                'allowedEntryIds' => $allowedEntryIds,
                'allowedSiteIds' => $allowedSiteIds,
                'allowedEntries' => $allowedEntries,
                'allowedSites' => $allowedSites,
                'assignmentCount' => count($assignedCredentialIds),
                'assignedCredentialIds' => $assignedCredentialIds,
                'assignedCredentials' => $assignedCredentials,
                'dateCreated' => $this->toIso8601($row['dateCreated'] ?? null),
                'dateUpdated' => $this->toIso8601($row['dateUpdated'] ?? null),
            ];
        }

        return $hydrated;
    }

    private function loadEntrySummariesByIds(array $entryIds): array
    {
        $normalizedEntryIds = $this->normalizeIntegerIds($entryIds);
        if (empty($normalizedEntryIds)) {
            return [];
        }

        $entriesById = [];
        foreach ($normalizedEntryIds as $entryId) {
            $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, null, ['status' => null]);
            if (!$entry instanceof Entry) {
                continue;
            }

            $title = trim((string)$entry->title);
            $entriesById[$entryId] = [
                'id' => $entryId,
                'title' => $title !== '' ? $title : sprintf('Entry #%d', $entryId),
                'label' => $title !== '' ? sprintf('%s (#%d)', $title, $entryId) : sprintf('Entry #%d', $entryId),
                'cpEditUrl' => $entry->getCpEditUrl(),
            ];
        }

        return $entriesById;
    }

    private function loadCredentialSummariesByIds(array $credentialIds): array
    {
        $normalizedCredentialIds = $this->normalizeIntegerIds($credentialIds);
        if (empty($normalizedCredentialIds)) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE_CREDENTIALS)
            ->where(['id' => $normalizedCredentialIds])
            ->orderBy(['displayName' => SORT_ASC, 'handle' => SORT_ASC])
            ->all();

        $credentialsById = [];
        foreach ($rows as $row) {
            $credentialId = (int)($row['id'] ?? 0);
            if ($credentialId <= 0) {
                continue;
            }

            $displayName = trim((string)($row['displayName'] ?? ''));
            $handle = trim((string)($row['handle'] ?? ''));
            $credentialsById[$credentialId] = [
                'id' => $credentialId,
                'displayName' => $displayName,
                'handle' => $handle,
                'label' => $displayName !== '' ? $displayName : $handle,
            ];
        }

        return $credentialsById;
    }

    private function loadSiteSummariesById(): array
    {
        $sitesById = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sitesById[(int)$site->id] = [
                'id' => (int)$site->id,
                'name' => (string)$site->name,
                'handle' => (string)$site->handle,
                'label' => trim((string)$site->name) !== '' ? sprintf('%s (#%d)', $site->name, (int)$site->id) : sprintf('Site #%d', (int)$site->id),
            ];
        }

        return $sitesById;
    }

    private function validateAllowedEntryIds(array $allowedEntryIds): array
    {
        $normalizedAllowedEntryIds = $this->normalizeIntegerIds($allowedEntryIds);
        if (empty($normalizedAllowedEntryIds)) {
            throw new \InvalidArgumentException('Target sets require at least one allowed entry.');
        }

        $knownEntryIds = array_keys($this->loadEntrySummariesByIds($normalizedAllowedEntryIds));
        sort($knownEntryIds);
        if ($knownEntryIds !== $normalizedAllowedEntryIds) {
            $missing = array_values(array_diff($normalizedAllowedEntryIds, $knownEntryIds));
            throw new \InvalidArgumentException(sprintf(
                'Unknown target-set entry IDs: %s.',
                implode(', ', $missing)
            ));
        }

        return $normalizedAllowedEntryIds;
    }

    private function validateAllowedSiteIds(array $allowedSiteIds): array
    {
        $normalizedAllowedSiteIds = $this->normalizeIntegerIds($allowedSiteIds);
        if (empty($normalizedAllowedSiteIds)) {
            throw new \InvalidArgumentException('Target sets require at least one allowed site.');
        }

        $knownSiteIds = array_keys($this->loadSiteSummariesById());
        sort($knownSiteIds);
        $missing = array_values(array_diff($normalizedAllowedSiteIds, $knownSiteIds));
        if (!empty($missing)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown target-set site IDs: %s.',
                implode(', ', $missing)
            ));
        }

        return $normalizedAllowedSiteIds;
    }

    private function targetSetsTableExists(): bool
    {
        return Craft::$app->getDb()->getTableSchema(self::TABLE_TARGET_SETS, true) !== null;
    }

    private function relationTableExists(): bool
    {
        return Craft::$app->getDb()->getTableSchema(self::TABLE_CREDENTIAL_TARGET_SETS, true) !== null;
    }

    private function normalizeHandle(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9:_\-.]+/', '-', $normalized) ?: '';
        return trim($normalized, '-');
    }

    private function normalizeName(string $value, string $fallback): string
    {
        $name = trim($value);
        if ($name === '') {
            $name = $fallback;
        }
        if (strlen($name) > 255) {
            $name = substr($name, 0, 255);
        }

        return $name;
    }

    private function normalizeDescription(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $description = trim((string)$value);
        if (strlen($description) > 255) {
            $description = substr($description, 0, 255);
        }

        return $description;
    }

    private function normalizeAllowedActionTypes(array $allowedActionTypes): array
    {
        $normalized = [];
        foreach ($allowedActionTypes as $allowedActionType) {
            $actionType = $this->normalizeActionType((string)$allowedActionType);
            if ($actionType === '' || in_array($actionType, $normalized, true)) {
                continue;
            }

            $normalized[] = $actionType;
        }

        if (empty($normalized)) {
            return self::DEFAULT_ALLOWED_ACTION_TYPES;
        }

        sort($normalized);
        return $normalized;
    }

    private function normalizeActionType(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9:_\-.]+/', '', $normalized) ?: '';
        return trim($normalized);
    }

    private function normalizeIntegerIds(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $id = (int)$value;
            if ($id <= 0 || in_array($id, $normalized, true)) {
                continue;
            }

            $normalized[] = $id;
        }

        sort($normalized);
        return $normalized;
    }

    private function decodeIntegerJsonArray(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeIntegerIds($decoded);
    }

    private function decodeStringJsonArray(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn($item): string => trim((string)$item), $decoded)));
    }

    private function encodeJson(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '[]';
    }

    private function toIso8601(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
