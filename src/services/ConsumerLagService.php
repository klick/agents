<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use yii\db\Query;

class ConsumerLagService extends Component
{
    private const TABLE = '{{%agents_consumer_checkpoints}}';

    public function upsertCheckpoint(array $input): array
    {
        $this->requireTable();

        $credentialId = $this->normalizeIntegrationKey((string)($input['credentialId'] ?? ''));
        $credentialCount = max(0, (int)($input['credentialCount'] ?? 0));
        $integrationKey = $this->resolveAuthorizedIntegrationKey(
            $input['integrationKey'] ?? '',
            $credentialId,
            $credentialCount
        );

        $resourceType = $this->normalizeResourceType((string)($input['resourceType'] ?? ''));
        if ($resourceType === '') {
            throw new \InvalidArgumentException('`resourceType` is required.');
        }

        $cursor = $this->normalizeOptionalString($input['cursor'] ?? null, 255);
        $updatedSince = $this->normalizeDateString($input['updatedSince'] ?? null);
        $checkpointAt = $this->normalizeDateString($input['checkpointAt'] ?? null);
        if ($checkpointAt === null) {
            $checkpointAt = gmdate('Y-m-d H:i:s');
        }

        $checkpointTimestamp = strtotime($checkpointAt);
        if ($checkpointTimestamp === false) {
            throw new \InvalidArgumentException('`checkpointAt` must be a valid datetime.');
        }

        $lagSeconds = max(0, time() - $checkpointTimestamp);
        $metadata = $this->normalizeArray($input['metadata'] ?? []);
        $now = gmdate('Y-m-d H:i:s');

        $existing = (new Query())
            ->from(self::TABLE)
            ->where([
                'integrationKey' => $integrationKey,
                'resourceType' => $resourceType,
            ])
            ->one();

        if (is_array($existing)) {
            Craft::$app->getDb()->createCommand()->update(self::TABLE, [
                'cursor' => $cursor,
                'updatedSince' => $updatedSince,
                'checkpointAt' => $checkpointAt,
                'lagSeconds' => $lagSeconds,
                'metadata' => $this->encodeJson($metadata),
                'dateUpdated' => $now,
            ], ['id' => (int)($existing['id'] ?? 0)])->execute();
        } else {
            Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
                'integrationKey' => $integrationKey,
                'resourceType' => $resourceType,
                'cursor' => $cursor,
                'updatedSince' => $updatedSince,
                'checkpointAt' => $checkpointAt,
                'lagSeconds' => $lagSeconds,
                'metadata' => $this->encodeJson($metadata),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        $row = (new Query())
            ->from(self::TABLE)
            ->where([
                'integrationKey' => $integrationKey,
                'resourceType' => $resourceType,
            ])
            ->one();

        if (!is_array($row)) {
            throw new \RuntimeException('Unable to load consumer checkpoint after save.');
        }

        return $this->hydrateRow($row);
    }

    private function resolveAuthorizedIntegrationKey(mixed $requestedIntegrationKey, string $credentialId, int $credentialCount): string
    {
        $integrationKey = $this->normalizeIntegrationKey((string)$requestedIntegrationKey);
        if ($credentialId === '') {
            if ($credentialCount === 0) {
                if ($integrationKey === '') {
                    throw new \InvalidArgumentException('`integrationKey` is required when token auth is disabled.');
                }

                return $integrationKey;
            }

            throw new \InvalidArgumentException('Authenticated credential context is required.');
        }

        if ($credentialId === 'default') {
            if ($credentialCount > 1) {
                throw new \InvalidArgumentException('Legacy default token cannot write sync-state when multiple credentials are configured. Use a dedicated credential-scoped token.');
            }

            if ($integrationKey === '') {
                throw new \InvalidArgumentException('`integrationKey` is required for legacy default-token sync-state writes.');
            }

            return $integrationKey;
        }

        if ($integrationKey === '') {
            return $credentialId;
        }

        if (!hash_equals($credentialId, $integrationKey)) {
            throw new \InvalidArgumentException(sprintf(
                '`integrationKey` must match the authenticated credential id `%s`.',
                $credentialId
            ));
        }

        return $credentialId;
    }

    public function getConsumerLag(array $filters = [], int $limit = 200): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $query = (new Query())
            ->from(self::TABLE)
            ->orderBy(['lagSeconds' => SORT_DESC, 'dateUpdated' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($this->normalizeLimit($limit, 200, 1000));

        $integrationKey = $this->normalizeIntegrationKey((string)($filters['integrationKey'] ?? ''));
        if ($integrationKey !== '') {
            $query->andWhere(['integrationKey' => $integrationKey]);
        }

        $resourceType = $this->normalizeResourceType((string)($filters['resourceType'] ?? ''));
        if ($resourceType !== '') {
            $query->andWhere(['resourceType' => $resourceType]);
        }

        $rows = $query->all();
        $lag = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lag[] = $this->hydrateRow($row);
        }

        return $lag;
    }

    public function getLagSummary(int $limit = 200): array
    {
        $rows = $this->getConsumerLag([], $limit);
        $summary = [
            'count' => count($rows),
            'healthy' => 0,
            'warning' => 0,
            'critical' => 0,
            'maxLagSeconds' => 0,
        ];

        foreach ($rows as $row) {
            $bucket = (string)($row['lagBucket'] ?? 'healthy');
            if (!isset($summary[$bucket])) {
                $summary[$bucket] = 0;
            }
            $summary[$bucket]++;
            $summary['maxLagSeconds'] = max($summary['maxLagSeconds'], (int)($row['lagSeconds'] ?? 0));
        }

        return [
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    private function tableExists(): bool
    {
        return Craft::$app->getDb()->getTableSchema(self::TABLE, true) !== null;
    }

    private function requireTable(): void
    {
        if ($this->tableExists()) {
            return;
        }

        throw new \RuntimeException('Consumer lag storage table is unavailable. Run plugin migrations.');
    }

    private function hydrateRow(array $row): array
    {
        $lagSeconds = max(0, (int)($row['lagSeconds'] ?? 0));

        return [
            'id' => (int)($row['id'] ?? 0),
            'integrationKey' => (string)($row['integrationKey'] ?? ''),
            'resourceType' => (string)($row['resourceType'] ?? ''),
            'cursor' => $this->normalizeOptionalString($row['cursor'] ?? null, 255),
            'updatedSince' => $this->toIso8601($row['updatedSince'] ?? null),
            'checkpointAt' => $this->toIso8601($row['checkpointAt'] ?? null),
            'lagSeconds' => $lagSeconds,
            'lagBucket' => $this->bucketLag($lagSeconds),
            'metadata' => $this->decodeJsonArray((string)($row['metadata'] ?? '[]')),
            'dateCreated' => $this->toIso8601($row['dateCreated'] ?? null),
            'dateUpdated' => $this->toIso8601($row['dateUpdated'] ?? null),
        ];
    }

    private function bucketLag(int $lagSeconds): string
    {
        if ($lagSeconds <= 300) {
            return 'healthy';
        }

        if ($lagSeconds <= 1800) {
            return 'warning';
        }

        return 'critical';
    }

    private function normalizeIntegrationKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9:_\-.]+/', '-', $normalized) ?: '';
        $normalized = trim($normalized, '-');
        if (strlen($normalized) > 64) {
            $normalized = substr($normalized, 0, 64);
        }

        return $normalized;
    }

    private function normalizeResourceType(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9:_\-.]+/', '', $normalized) ?: '';
        if (strlen($normalized) > 32) {
            $normalized = substr($normalized, 0, 32);
        }

        return $normalized;
    }

    private function normalizeDateString(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
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

    private function normalizeArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function decodeJsonArray(string $raw): array
    {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '[]';
    }

    private function toIso8601(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function normalizeLimit(int $limit, int $default, int $max): int
    {
        if ($limit <= 0) {
            return $default;
        }

        return min($limit, $max);
    }
}
