<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use yii\db\Query;

class CredentialService extends Component
{
    private const TABLE = '{{%agents_credentials}}';
    private const TOKEN_RANDOM_BYTES = 24;

    public function getManagedCredentialsForRuntime(array $defaultScopes): array
    {
        if (!$this->credentialsTableExists()) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE)
            ->where(['revokedAt' => null])
            ->orderBy(['handle' => SORT_ASC])
            ->all();

        $credentials = [];
        foreach ($rows as $row) {
            $tokenHash = strtolower(trim((string)($row['tokenHash'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $tokenHash)) {
                continue;
            }

            $credentials[] = [
                'id' => (string)($row['handle'] ?? ''),
                'tokenHash' => $tokenHash,
                'scopes' => $this->normalizeScopes($this->decodeScopes((string)($row['scopes'] ?? '[]')), $defaultScopes),
                'source' => 'cp',
                'managedCredentialId' => (int)($row['id'] ?? 0),
            ];
        }

        return $credentials;
    }

    public function getManagedCredentials(array $defaultScopes): array
    {
        if (!$this->credentialsTableExists()) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE)
            ->orderBy([
                'revokedAt' => SORT_ASC,
                'handle' => SORT_ASC,
            ])
            ->all();

        $credentials = [];
        foreach ($rows as $row) {
            $credentials[] = [
                'id' => (int)($row['id'] ?? 0),
                'handle' => (string)($row['handle'] ?? ''),
                'displayName' => (string)($row['displayName'] ?? ''),
                'tokenPrefix' => (string)($row['tokenPrefix'] ?? ''),
                'scopes' => $this->normalizeScopes($this->decodeScopes((string)($row['scopes'] ?? '[]')), $defaultScopes),
                'revoked' => !empty($row['revokedAt']),
                'revokedAt' => $this->toIso8601($row['revokedAt'] ?? null),
                'rotatedAt' => $this->toIso8601($row['rotatedAt'] ?? null),
                'lastUsedAt' => $this->toIso8601($row['lastUsedAt'] ?? null),
                'lastUsedIp' => $this->normalizeOptionalString($row['lastUsedIp'] ?? null),
                'lastAuthMethod' => $this->normalizeOptionalString($row['lastAuthMethod'] ?? null),
                'dateCreated' => $this->toIso8601($row['dateCreated'] ?? null),
                'dateUpdated' => $this->toIso8601($row['dateUpdated'] ?? null),
            ];
        }

        return $credentials;
    }

    public function createManagedCredential(string $handle, string $displayName, array $scopes, array $defaultScopes): array
    {
        if (!$this->credentialsTableExists()) {
            throw new \RuntimeException('Credential storage table is unavailable. Run plugin migrations.');
        }

        $normalizedHandle = $this->normalizeHandle($handle);
        if ($normalizedHandle === '') {
            throw new \InvalidArgumentException('Credential ID is required and may only contain letters, digits, dashes, underscores, colons, and periods.');
        }

        $normalizedDisplayName = $this->normalizeDisplayName($displayName, $normalizedHandle);
        $normalizedScopes = $this->normalizeScopes($scopes, $defaultScopes);

        $exists = (new Query())
            ->from(self::TABLE)
            ->where(['handle' => $normalizedHandle])
            ->exists();
        if ($exists) {
            throw new \InvalidArgumentException(sprintf('Credential `%s` already exists.', $normalizedHandle));
        }

        $token = $this->generateToken($normalizedHandle);
        $tokenHash = hash('sha256', $token);
        $tokenPrefix = substr($token, 0, 12);
        $now = gmdate('Y-m-d H:i:s');

        Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
            'handle' => $normalizedHandle,
            'displayName' => $normalizedDisplayName,
            'tokenHash' => $tokenHash,
            'tokenPrefix' => $tokenPrefix,
            'scopes' => json_encode($normalizedScopes, JSON_UNESCAPED_SLASHES),
            'rotatedAt' => null,
            'revokedAt' => null,
            'lastUsedAt' => null,
            'lastUsedIp' => null,
            'lastAuthMethod' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return [
            'token' => $token,
            'credential' => $this->getManagedCredentialByHandle($normalizedHandle, $defaultScopes),
        ];
    }

    public function rotateManagedCredential(int $id, array $defaultScopes): ?array
    {
        if ($id <= 0 || !$this->credentialsTableExists()) {
            return null;
        }

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();
        if (!is_array($row)) {
            return null;
        }

        $handle = (string)($row['handle'] ?? '');
        if ($handle === '') {
            return null;
        }

        $token = $this->generateToken($handle);
        $tokenHash = hash('sha256', $token);
        $tokenPrefix = substr($token, 0, 12);
        $now = gmdate('Y-m-d H:i:s');

        Craft::$app->getDb()->createCommand()->update(self::TABLE, [
            'tokenHash' => $tokenHash,
            'tokenPrefix' => $tokenPrefix,
            'rotatedAt' => $now,
            'revokedAt' => null,
            'dateUpdated' => $now,
        ], ['id' => $id])->execute();

        return [
            'token' => $token,
            'credential' => $this->getManagedCredentialByHandle($handle, $defaultScopes),
        ];
    }

    public function revokeManagedCredential(int $id): bool
    {
        if ($id <= 0 || !$this->credentialsTableExists()) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        $updated = Craft::$app->getDb()->createCommand()->update(self::TABLE, [
            'revokedAt' => $now,
            'dateUpdated' => $now,
        ], ['id' => $id])->execute();

        return $updated > 0;
    }

    public function updateManagedCredential(int $id, string $displayName, array $scopes, array $defaultScopes): ?array
    {
        if ($id <= 0 || !$this->credentialsTableExists()) {
            return null;
        }

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();
        if (!is_array($row)) {
            return null;
        }

        $handle = (string)($row['handle'] ?? '');
        if ($handle === '') {
            return null;
        }

        $normalizedDisplayName = $this->normalizeDisplayName($displayName, $handle);
        $normalizedScopes = $this->normalizeScopes($scopes, $defaultScopes);
        $encodedScopes = json_encode($normalizedScopes, JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedScopes)) {
            $encodedScopes = '[]';
        }

        Craft::$app->getDb()->createCommand()->update(self::TABLE, [
            'displayName' => $normalizedDisplayName,
            'scopes' => $encodedScopes,
            'dateUpdated' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id])->execute();

        return $this->getManagedCredentialById($id, $defaultScopes);
    }

    public function deleteManagedCredential(int $id): bool
    {
        if ($id <= 0 || !$this->credentialsTableExists()) {
            return false;
        }

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['id' => $id])
            ->execute();

        return $deleted > 0;
    }

    public function recordCredentialUse(int $id, string $authMethod, string $ip): void
    {
        if ($id <= 0 || !$this->credentialsTableExists()) {
            return;
        }

        $sanitizedAuthMethod = substr(trim($authMethod), 0, 32);
        $sanitizedIp = substr(trim($ip), 0, 64);
        if ($sanitizedIp === '') {
            $sanitizedIp = 'unknown';
        }

        Craft::$app->getDb()->createCommand()->update(self::TABLE, [
            'lastUsedAt' => gmdate('Y-m-d H:i:s'),
            'lastUsedIp' => $sanitizedIp,
            'lastAuthMethod' => $sanitizedAuthMethod !== '' ? $sanitizedAuthMethod : 'unknown',
            'dateUpdated' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id])->execute();
    }

    private function getManagedCredentialByHandle(string $handle, array $defaultScopes): ?array
    {
        $rows = $this->getManagedCredentials($defaultScopes);
        foreach ($rows as $credential) {
            if (($credential['handle'] ?? '') === $handle) {
                return $credential;
            }
        }

        return null;
    }

    private function getManagedCredentialById(int $id, array $defaultScopes): ?array
    {
        $rows = $this->getManagedCredentials($defaultScopes);
        foreach ($rows as $credential) {
            if ((int)($credential['id'] ?? 0) === $id) {
                return $credential;
            }
        }

        return null;
    }

    private function credentialsTableExists(): bool
    {
        return Craft::$app->getDb()->getTableSchema(self::TABLE, true) !== null;
    }

    private function normalizeHandle(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9:_\-.]+/', '-', $normalized) ?: '';
        return trim($normalized, '-');
    }

    private function normalizeDisplayName(string $value, string $fallback): string
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

    private function decodeScopes(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $scopes = [];
        foreach ($decoded as $scope) {
            if (!is_string($scope) && !is_numeric($scope)) {
                continue;
            }
            $scopes[] = (string)$scope;
        }

        return $scopes;
    }

    private function normalizeScopes(array $scopes, array $defaultScopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            $value = strtolower(trim((string)$scope));
            if ($value === '') {
                continue;
            }

            $value = preg_replace('/[^a-z0-9:_*.-]/', '', $value) ?: '';
            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        if (!empty($normalized)) {
            return $normalized;
        }

        $fallback = array_values(array_unique(array_map('strval', $defaultScopes)));
        sort($fallback);
        return $fallback;
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

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        return $normalized === '' ? null : $normalized;
    }

    private function generateToken(string $handle): string
    {
        $prefix = preg_replace('/[^a-z0-9]+/', '', strtolower($handle)) ?: 'credential';
        $prefix = substr($prefix, 0, 10);

        try {
            $random = bin2hex(random_bytes(self::TOKEN_RANDOM_BYTES));
        } catch (\Throwable) {
            $random = sha1(uniqid('', true) . microtime(true));
        }

        return sprintf('agt_%s_%s', $prefix, $random);
    }
}
