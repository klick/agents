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
    private const DEFAULT_EXPIRY_REMINDER_DAYS = 7;
    private const WEBHOOK_RESOURCE_TYPES = ['entry', 'order', 'product'];
    private const WEBHOOK_ACTIONS = ['created', 'updated', 'deleted'];

    private ?bool $supportsWebhookSubscriptionColumns = null;
    private ?bool $supportsExpiryColumns = null;
    private ?bool $supportsIpAllowlistColumn = null;

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
            if ($this->isCredentialExpired($row)) {
                continue;
            }

            $tokenHash = strtolower(trim((string)($row['tokenHash'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $tokenHash)) {
                continue;
            }

            $credentials[] = [
                'id' => (string)($row['handle'] ?? ''),
                'tokenHash' => $tokenHash,
                'scopes' => $this->normalizeScopes($this->decodeScopes((string)($row['scopes'] ?? '[]')), $defaultScopes),
                'webhookSubscriptions' => $this->decodeWebhookSubscriptions($row),
                'ipAllowlist' => $this->decodeIpAllowlist($row),
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
            $expiry = $this->decodeExpiryPolicy($row);
            $credentials[] = [
                'id' => (int)($row['id'] ?? 0),
                'handle' => (string)($row['handle'] ?? ''),
                'displayName' => (string)($row['displayName'] ?? ''),
                'tokenPrefix' => (string)($row['tokenPrefix'] ?? ''),
                'scopes' => $this->normalizeScopes($this->decodeScopes((string)($row['scopes'] ?? '[]')), $defaultScopes),
                'webhookSubscriptions' => $this->decodeWebhookSubscriptions($row),
                'ipAllowlist' => $this->decodeIpAllowlist($row),
                'expiresAt' => $expiry['expiresAt'],
                'expiryReminderDays' => $expiry['expiryReminderDays'],
                'expiresInDays' => $expiry['expiresInDays'],
                'expiryStatus' => $expiry['status'],
                'expiryPolicy' => $expiry,
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

    public function getManagedWebhookSubscriptions(): array
    {
        if (!$this->credentialsTableExists() || !$this->supportsWebhookSubscriptionColumns()) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE)
            ->where(['revokedAt' => null])
            ->orderBy(['handle' => SORT_ASC])
            ->all();

        $subscriptions = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($this->isCredentialExpired($row)) {
                continue;
            }

            $decoded = $this->decodeWebhookSubscriptions($row);
            if (!(bool)($decoded['active'] ?? false)) {
                continue;
            }

            $subscriptions[] = [
                'credentialId' => (int)($row['id'] ?? 0),
                'handle' => (string)($row['handle'] ?? ''),
                'resourceTypes' => (array)($decoded['resourceTypes'] ?? []),
                'actions' => (array)($decoded['actions'] ?? []),
            ];
        }

        return $subscriptions;
    }

    public function createManagedCredential(
        string $handle,
        string $displayName,
        array $scopes,
        array $defaultScopes,
        array $webhookSubscriptions = [],
        array $expiryPolicy = [],
        array $networkPolicy = []
    ): array
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
        $normalizedWebhookSubscriptions = $this->normalizeWebhookSubscriptions($webhookSubscriptions);
        $normalizedExpiryPolicy = $this->resolveCreateExpiryPolicy($expiryPolicy);
        $normalizedIpAllowlist = $this->normalizeIpAllowlist($networkPolicy['ipAllowlist'] ?? null);

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

        $insertData = [
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
        ];
        if ($this->supportsWebhookSubscriptionColumns()) {
            $insertData['webhookResourceTypes'] = $this->encodeJson($normalizedWebhookSubscriptions['resourceTypes']);
            $insertData['webhookActions'] = $this->encodeJson($normalizedWebhookSubscriptions['actions']);
        }
        if ($this->supportsExpiryColumns()) {
            $insertData['expiresAt'] = $normalizedExpiryPolicy['expiresAt'];
            $insertData['expiryReminderDays'] = (int)$normalizedExpiryPolicy['expiryReminderDays'];
        }
        if ($this->supportsIpAllowlistColumn()) {
            $insertData['ipAllowlist'] = $this->encodeJson($normalizedIpAllowlist);
        }

        Craft::$app->getDb()->createCommand()->insert(self::TABLE, $insertData)->execute();

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

    public function updateManagedCredential(
        int $id,
        string $displayName,
        array $scopes,
        array $defaultScopes,
        array $webhookSubscriptions = [],
        array $expiryPolicy = [],
        array $networkPolicy = []
    ): ?array
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
        $normalizedWebhookSubscriptions = $this->normalizeWebhookSubscriptions($webhookSubscriptions);
        $normalizedExpiryPolicy = $this->resolveUpdateExpiryPolicy($row, $expiryPolicy);
        $normalizedIpAllowlist = $this->resolveUpdateIpAllowlist($row, $networkPolicy['ipAllowlist'] ?? null);
        $encodedScopes = json_encode($normalizedScopes, JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedScopes)) {
            $encodedScopes = '[]';
        }
        $updateData = [
            'displayName' => $normalizedDisplayName,
            'scopes' => $encodedScopes,
            'dateUpdated' => gmdate('Y-m-d H:i:s'),
        ];
        if ($this->supportsWebhookSubscriptionColumns()) {
            $updateData['webhookResourceTypes'] = $this->encodeJson($normalizedWebhookSubscriptions['resourceTypes']);
            $updateData['webhookActions'] = $this->encodeJson($normalizedWebhookSubscriptions['actions']);
        }
        if ($this->supportsExpiryColumns()) {
            $updateData['expiresAt'] = $normalizedExpiryPolicy['expiresAt'];
            $updateData['expiryReminderDays'] = (int)$normalizedExpiryPolicy['expiryReminderDays'];
        }
        if ($this->supportsIpAllowlistColumn()) {
            $updateData['ipAllowlist'] = $this->encodeJson($normalizedIpAllowlist);
        }

        Craft::$app->getDb()->createCommand()->update(self::TABLE, $updateData, ['id' => $id])->execute();

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

    private function supportsWebhookSubscriptionColumns(): bool
    {
        if ($this->supportsWebhookSubscriptionColumns !== null) {
            return $this->supportsWebhookSubscriptionColumns;
        }

        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            $this->supportsWebhookSubscriptionColumns = false;
            return false;
        }

        $this->supportsWebhookSubscriptionColumns = $schema->getColumn('webhookResourceTypes') !== null
            && $schema->getColumn('webhookActions') !== null;

        return $this->supportsWebhookSubscriptionColumns;
    }

    private function supportsExpiryColumns(): bool
    {
        if ($this->supportsExpiryColumns !== null) {
            return $this->supportsExpiryColumns;
        }

        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            $this->supportsExpiryColumns = false;
            return false;
        }

        $this->supportsExpiryColumns = $schema->getColumn('expiresAt') !== null
            && $schema->getColumn('expiryReminderDays') !== null;

        return $this->supportsExpiryColumns;
    }

    private function supportsIpAllowlistColumn(): bool
    {
        if ($this->supportsIpAllowlistColumn !== null) {
            return $this->supportsIpAllowlistColumn;
        }

        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            $this->supportsIpAllowlistColumn = false;
            return false;
        }

        $this->supportsIpAllowlistColumn = $schema->getColumn('ipAllowlist') !== null;
        return $this->supportsIpAllowlistColumn;
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

    private function decodeWebhookSubscriptions(array $row): array
    {
        if (!$this->supportsWebhookSubscriptionColumns()) {
            return [
                'resourceTypes' => [],
                'actions' => [],
                'active' => false,
            ];
        }

        $resourceTypes = $this->normalizeWebhookValues($row['webhookResourceTypes'] ?? null, self::WEBHOOK_RESOURCE_TYPES);
        $actions = $this->normalizeWebhookValues($row['webhookActions'] ?? null, self::WEBHOOK_ACTIONS);

        return [
            'resourceTypes' => $resourceTypes,
            'actions' => $actions,
            'active' => !empty($resourceTypes) || !empty($actions),
        ];
    }

    private function decodeIpAllowlist(array $row): array
    {
        if (!$this->supportsIpAllowlistColumn()) {
            return [];
        }

        return $this->normalizeIpAllowlist($row['ipAllowlist'] ?? null);
    }

    private function isCredentialExpired(array $row): bool
    {
        $expiry = $this->decodeExpiryPolicy($row);
        return (bool)($expiry['isExpired'] ?? false);
    }

    private function decodeExpiryPolicy(array $row): array
    {
        $default = [
            'expiresAt' => null,
            'expiryReminderDays' => self::DEFAULT_EXPIRY_REMINDER_DAYS,
            'expiresInDays' => null,
            'isExpired' => false,
            'isExpiringSoon' => false,
            'status' => 'none',
        ];

        if (!$this->supportsExpiryColumns()) {
            return $default;
        }

        $expiresAtRaw = $this->normalizeDateString($row['expiresAt'] ?? null);
        $expiryReminderDays = $this->normalizeReminderDays($row['expiryReminderDays'] ?? self::DEFAULT_EXPIRY_REMINDER_DAYS);
        if ($expiresAtRaw === null) {
            $default['expiryReminderDays'] = $expiryReminderDays;
            return $default;
        }

        $expiresTimestamp = strtotime($expiresAtRaw);
        if ($expiresTimestamp === false) {
            $default['expiryReminderDays'] = $expiryReminderDays;
            return $default;
        }

        $now = time();
        $remainingSeconds = $expiresTimestamp - $now;
        $expiresInDays = (int)floor($remainingSeconds / 86400);
        $isExpired = $remainingSeconds <= 0;
        $isExpiringSoon = !$isExpired && $expiresInDays <= $expiryReminderDays;

        return [
            'expiresAt' => $this->toIso8601($expiresAtRaw),
            'expiryReminderDays' => $expiryReminderDays,
            'expiresInDays' => $expiresInDays,
            'isExpired' => $isExpired,
            'isExpiringSoon' => $isExpiringSoon,
            'status' => $isExpired ? 'expired' : ($isExpiringSoon ? 'expiring_soon' : 'active'),
        ];
    }

    private function resolveCreateExpiryPolicy(array $input): array
    {
        $ttlDays = $this->normalizeNullableInt($input['ttlDays'] ?? null);
        $expiresAt = null;
        if ($ttlDays !== null && $ttlDays > 0) {
            $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlDays * 86400));
        }

        return [
            'expiresAt' => $expiresAt,
            'expiryReminderDays' => $this->normalizeReminderDays($input['expiryReminderDays'] ?? null),
        ];
    }

    private function resolveUpdateExpiryPolicy(array $row, array $input): array
    {
        $currentExpiry = $this->normalizeDateString($row['expiresAt'] ?? null);
        $currentReminder = $this->normalizeReminderDays($row['expiryReminderDays'] ?? self::DEFAULT_EXPIRY_REMINDER_DAYS);

        $ttlDays = $this->normalizeNullableInt($input['ttlDays'] ?? null);
        $reminderDays = $this->normalizeNullableInt($input['expiryReminderDays'] ?? null);

        $expiresAt = $currentExpiry;
        if ($ttlDays !== null) {
            if ($ttlDays <= 0) {
                $expiresAt = null;
            } else {
                $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlDays * 86400));
            }
        }

        return [
            'expiresAt' => $expiresAt,
            'expiryReminderDays' => $reminderDays === null ? $currentReminder : $this->normalizeReminderDays($reminderDays),
        ];
    }

    private function resolveUpdateIpAllowlist(array $row, mixed $input): array
    {
        if (!$this->supportsIpAllowlistColumn()) {
            return [];
        }

        if ($input === null) {
            return $this->decodeIpAllowlist($row);
        }

        if (is_string($input) && trim($input) === '') {
            return [];
        }

        if (is_array($input) && empty($input)) {
            return [];
        }

        return $this->normalizeIpAllowlist($input);
    }

    private function normalizeWebhookSubscriptions(array $subscriptions): array
    {
        $resourceTypes = $this->normalizeWebhookValues($subscriptions['resourceTypes'] ?? null, self::WEBHOOK_RESOURCE_TYPES);
        $actions = $this->normalizeWebhookValues($subscriptions['actions'] ?? null, self::WEBHOOK_ACTIONS);

        return [
            'resourceTypes' => $resourceTypes,
            'actions' => $actions,
            'active' => !empty($resourceTypes) || !empty($actions),
        ];
    }

    private function normalizeWebhookValues(mixed $raw, array $allowed): array
    {
        $tokens = [];

        if (is_array($raw)) {
            foreach ($raw as $value) {
                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }
                $tokens[] = (string)$value;
            }
        } elseif (is_string($raw) || is_numeric($raw)) {
            $stringValue = trim((string)$raw);
            $decoded = json_decode($stringValue, true);
            if (is_array($decoded)) {
                foreach ($decoded as $value) {
                    if (!is_string($value) && !is_numeric($value)) {
                        continue;
                    }
                    $tokens[] = (string)$value;
                }
            } else {
                $tokens[] = $stringValue;
            }
        }

        $parts = [];
        foreach ($tokens as $token) {
            $chunks = preg_split('/[\s,]+/', strtolower($token)) ?: [];
            foreach ($chunks as $chunk) {
                $normalized = trim((string)$chunk);
                if ($normalized === '') {
                    continue;
                }
                $parts[] = $normalized;
            }
        }

        if (in_array('*', $parts, true)) {
            $parts = $allowed;
        }

        $normalized = [];
        foreach ($parts as $part) {
            if (in_array($part, $allowed, true)) {
                $normalized[] = $part;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    private function normalizeIpAllowlist(mixed $raw): array
    {
        $tokens = [];
        if (is_array($raw)) {
            foreach ($raw as $value) {
                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }
                $tokens[] = (string)$value;
            }
        } elseif (is_string($raw) || is_numeric($raw)) {
            $tokens[] = (string)$raw;
        }

        $parts = [];
        foreach ($tokens as $token) {
            $chunks = preg_split('/[\s,]+/', trim($token)) ?: [];
            foreach ($chunks as $chunk) {
                $candidate = trim((string)$chunk);
                if ($candidate === '') {
                    continue;
                }

                $parts[] = $candidate;
            }
        }

        $normalized = [];
        foreach ($parts as $part) {
            $cidr = $this->normalizeCidr($part);
            if ($cidr === null) {
                continue;
            }

            $normalized[] = $cidr;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    private function normalizeCidr(string $raw): ?string
    {
        $candidate = trim($raw);
        if ($candidate === '') {
            return null;
        }

        if (str_contains($candidate, '/')) {
            [$ip, $prefix] = explode('/', $candidate, 2);
            $ip = trim($ip);
            $prefix = trim($prefix);
        } else {
            $ip = $candidate;
            $prefix = '';
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $isIpv6 = str_contains($ip, ':');
        if ($prefix === '') {
            $prefixInt = $isIpv6 ? 128 : 32;
        } elseif (preg_match('/^\d+$/', $prefix) === 1) {
            $prefixInt = (int)$prefix;
        } else {
            return null;
        }

        $maxPrefix = $isIpv6 ? 128 : 32;
        if ($prefixInt < 0 || $prefixInt > $maxPrefix) {
            return null;
        }

        return sprintf('%s/%d', $ip, $prefixInt);
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

    private function encodeJson(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '[]';
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

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    private function normalizeReminderDays(mixed $value): int
    {
        $normalized = $this->normalizeNullableInt($value);
        if ($normalized === null) {
            return self::DEFAULT_EXPIRY_REMINDER_DAYS;
        }

        if ($normalized < 1) {
            return self::DEFAULT_EXPIRY_REMINDER_DAYS;
        }

        return min($normalized, 365);
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
