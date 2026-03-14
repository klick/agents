<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\StringHelper;
use Klick\Agents\Plugin;
use yii\db\Query;

class CredentialService extends Component
{
    private const TABLE = '{{%agents_credentials}}';
    private const TOKEN_RANDOM_SEGMENT_LENGTH = 18;
    private const TOKEN_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    private const DEFAULT_EXPIRY_REMINDER_DAYS = 7;
    private const WEBHOOK_RESOURCE_TYPES = ['entry', 'order', 'product'];
    private const WEBHOOK_ACTIONS = ['created', 'updated', 'deleted'];
    private const USAGE_PULSE_CACHE_KEY_PREFIX = 'agents:credentials:pulse:v1:';
    private const USAGE_PULSE_TTL_SECONDS = 15;
    private const USAGE_PULSE_READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    private const USAGE_PULSE_WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private ?bool $supportsWebhookSubscriptionColumns = null;
    private ?bool $supportsExpiryColumns = null;
    private ?bool $supportsIpAllowlistColumn = null;
    private ?bool $supportsPauseColumn = null;
    private ?bool $supportsOwnerColumn = null;
    private ?bool $supportsDescriptionColumn = null;
    private ?bool $supportsOwnerUserIdColumn = null;
    private ?bool $supportsForceHumanApprovalColumn = null;
    private ?bool $supportsApprovalRecipientUserIdsColumn = null;

    public function getManagedCredentialsForRuntime(array $defaultScopes): array
    {
        if (!$this->credentialsTableExists()) {
            return [];
        }

        $query = (new Query())
            ->from(self::TABLE)
            ->where(['revokedAt' => null]);
        if ($this->supportsPauseColumn()) {
            $query->andWhere(['pausedAt' => null]);
        }

        $rows = $query
            ->orderBy(['handle' => SORT_ASC])
            ->all();

        $credentials = [];
        foreach ($rows as $row) {
            if ($this->isCredentialExpired($row)) {
                continue;
            }
            if ($this->isCredentialPaused($row)) {
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
                'forceHumanApproval' => $this->decodeForceHumanApproval($row),
                'ownerUserId' => $this->decodeOwnerUserId($row),
                'approvalRecipientUserIds' => $this->decodeApprovalRecipientUserIds($row),
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

        $relatedUsersById = $this->loadReferencedUsers($rows);
        $credentials = [];
        foreach ($rows as $row) {
            $expiry = $this->decodeExpiryPolicy($row);
            $isRevoked = !empty($row['revokedAt']);
            $isPaused = $this->isCredentialPaused($row);
            $owner = $this->supportsOwnerColumn() ? $this->normalizeOwner($row['owner'] ?? null) : '';
            $ownerUserId = $this->decodeOwnerUserId($row);
            $approvalRecipientUserIds = $this->decodeApprovalRecipientUserIds($row);
            $ownerUser = $ownerUserId !== null ? ($relatedUsersById[$ownerUserId] ?? null) : null;
            $approvalRecipients = [];
            foreach ($approvalRecipientUserIds as $userId) {
                if (!isset($relatedUsersById[$userId])) {
                    continue;
                }
                $approvalRecipients[] = $relatedUsersById[$userId];
            }
            $credentials[] = [
                'id' => (int)($row['id'] ?? 0),
                'handle' => (string)($row['handle'] ?? ''),
                'displayName' => (string)($row['displayName'] ?? ''),
                'description' => $this->supportsDescriptionColumn() ? $this->normalizeDescription($row['description'] ?? null) : '',
                'owner' => $owner,
                'ownerLegacy' => $owner,
                'ownerUserId' => $ownerUserId,
                'ownerUser' => $ownerUser,
                'ownerLabel' => $ownerUser['label'] ?? ($owner !== '' ? $owner : ''),
                'forceHumanApproval' => $this->decodeForceHumanApproval($row),
                'approvalRecipientUserIds' => $approvalRecipientUserIds,
                'approvalRecipients' => $approvalRecipients,
                'tokenPrefix' => (string)($row['tokenPrefix'] ?? ''),
                'scopes' => $this->normalizeScopes($this->decodeScopes((string)($row['scopes'] ?? '[]')), $defaultScopes),
                'webhookSubscriptions' => $this->decodeWebhookSubscriptions($row),
                'ipAllowlist' => $this->decodeIpAllowlist($row),
                'expiresAt' => $expiry['expiresAt'],
                'expiryReminderDays' => $expiry['expiryReminderDays'],
                'expiresInDays' => $expiry['expiresInDays'],
                'expiryStatus' => $expiry['status'],
                'expiryPolicy' => $expiry,
                'mode' => $this->resolveCredentialMode($isRevoked, $isPaused, $expiry),
                'revoked' => $isRevoked,
                'revokedAt' => $this->toIso8601($row['revokedAt'] ?? null),
                'paused' => $isPaused,
                'pausedAt' => $this->toIso8601($row['pausedAt'] ?? null),
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

    public function getManagedCredentialByIdForCp(int $id, array $defaultScopes): ?array
    {
        return $this->getManagedCredentialById($id, $defaultScopes);
    }

    public function getManagedWebhookSubscriptions(): array
    {
        if (!$this->credentialsTableExists() || !$this->supportsWebhookSubscriptionColumns()) {
            return [];
        }

        $query = (new Query())
            ->from(self::TABLE)
            ->where(['revokedAt' => null]);
        if ($this->supportsPauseColumn()) {
            $query->andWhere(['pausedAt' => null]);
        }

        $rows = $query
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
            if ($this->isCredentialPaused($row)) {
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

    public function getNotificationTargetsForHandle(string $handle): array
    {
        if (!$this->credentialsTableExists()) {
            return [
                'owner' => '',
                'ownerUserId' => null,
                'ownerUser' => null,
                'approvalRecipientUserIds' => [],
                'approvalRecipients' => [],
            ];
        }

        $normalizedHandle = $this->normalizeHandle($handle);
        if ($normalizedHandle === '') {
            return [
                'owner' => '',
                'ownerUserId' => null,
                'ownerUser' => null,
                'approvalRecipientUserIds' => [],
                'approvalRecipients' => [],
            ];
        }

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['handle' => $normalizedHandle])
            ->one();

        if (!is_array($row)) {
            return [
                'owner' => '',
                'ownerUserId' => null,
                'ownerUser' => null,
                'approvalRecipientUserIds' => [],
                'approvalRecipients' => [],
            ];
        }

        $relatedUsersById = $this->loadReferencedUsers([$row]);
        $owner = $this->supportsOwnerColumn() ? $this->normalizeOwner($row['owner'] ?? null) : '';
        $ownerUserId = $this->decodeOwnerUserId($row);
        $approvalRecipientUserIds = $this->decodeApprovalRecipientUserIds($row);
        $approvalRecipients = [];
        foreach ($approvalRecipientUserIds as $userId) {
            if (isset($relatedUsersById[$userId])) {
                $approvalRecipients[] = $relatedUsersById[$userId];
            }
        }

        return [
            'owner' => $owner,
            'description' => $this->supportsDescriptionColumn() ? $this->normalizeDescription($row['description'] ?? null) : '',
            'ownerUserId' => $ownerUserId,
            'ownerUser' => $ownerUserId !== null ? ($relatedUsersById[$ownerUserId] ?? null) : null,
            'approvalRecipientUserIds' => $approvalRecipientUserIds,
            'approvalRecipients' => $approvalRecipients,
        ];
    }

    public function getApprovalRecipientEmailsForRuntime(array $defaultScopes): array
    {
        $emails = [];
        foreach ($this->getManagedCredentials($defaultScopes) as $credential) {
            if (!is_array($credential)) {
                continue;
            }

            $mode = strtolower(trim((string)($credential['mode'] ?? '')));
            if (!in_array($mode, ['active', 'expiring_soon'], true)) {
                continue;
            }

            foreach ((array)($credential['approvalRecipients'] ?? []) as $recipient) {
                if (!is_array($recipient)) {
                    continue;
                }

                $email = strtolower(trim((string)($recipient['email'] ?? '')));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $emails[$email] = true;
            }
        }

        return array_keys($emails);
    }

    public function createManagedCredential(
        string $handle,
        string $displayName,
        string $description,
        string $owner,
        ?int $ownerUserId,
        array $approvalRecipientUserIds,
        bool $forceHumanApproval,
        string $rawToken,
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
        $normalizedDescription = $this->normalizeDescription($description);
        $normalizedOwnerUserId = $this->normalizeUserId($ownerUserId);
        $normalizedOwner = $this->resolveOwnerLegacyValue($normalizedOwnerUserId, $owner);
        $normalizedScopes = $this->normalizeScopes($scopes, $defaultScopes);
        $normalizedWebhookSubscriptions = $this->normalizeWebhookSubscriptions($webhookSubscriptions);
        $normalizedExpiryPolicy = $this->resolveCreateExpiryPolicy($expiryPolicy);
        $normalizedIpAllowlist = $this->normalizeIpAllowlist($networkPolicy['ipAllowlist'] ?? null);
        $normalizedApprovalRecipientUserIds = $forceHumanApproval
            ? $this->normalizeUserIds($approvalRecipientUserIds)
            : [];

        $exists = (new Query())
            ->from(self::TABLE)
            ->where(['handle' => $normalizedHandle])
            ->exists();
        if ($exists) {
            throw new \InvalidArgumentException(sprintf('Credential `%s` already exists.', $normalizedHandle));
        }

        $token = $this->normalizeProvidedToken($rawToken);
        if ($token === '') {
            $token = $this->generateToken($normalizedHandle);
        }
        $tokenHash = hash('sha256', $token);
        $tokenPrefix = substr($token, 0, 12);
        $now = gmdate('Y-m-d H:i:s');

        $tokenHashExists = (new Query())
            ->from(self::TABLE)
            ->where(['tokenHash' => $tokenHash])
            ->exists();
        if ($tokenHashExists) {
            throw new \InvalidArgumentException('API token already exists. Generate a new token and try again.');
        }

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
        if ($this->supportsPauseColumn()) {
            $insertData['pausedAt'] = null;
        }
        if ($this->supportsOwnerColumn()) {
            $insertData['owner'] = $normalizedOwner !== '' ? $normalizedOwner : null;
        }
        if ($this->supportsDescriptionColumn()) {
            $insertData['description'] = $normalizedDescription !== '' ? $normalizedDescription : null;
        }
        if ($this->supportsOwnerUserIdColumn()) {
            $insertData['ownerUserId'] = $normalizedOwnerUserId;
        }
        if ($this->supportsForceHumanApprovalColumn()) {
            $insertData['forceHumanApproval'] = $forceHumanApproval ? 1 : 0;
        }
        if ($this->supportsApprovalRecipientUserIdsColumn()) {
            $insertData['approvalRecipientUserIds'] = $this->encodeJson($normalizedApprovalRecipientUserIds);
        }
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

        $updateData = [
            'tokenHash' => $tokenHash,
            'tokenPrefix' => $tokenPrefix,
            'rotatedAt' => $now,
            'revokedAt' => null,
            'dateUpdated' => $now,
        ];
        if ($this->supportsPauseColumn()) {
            $updateData['pausedAt'] = null;
        }

        Craft::$app->getDb()->createCommand()->update(self::TABLE, $updateData, ['id' => $id])->execute();

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
        $updateData = [
            'revokedAt' => $now,
            'dateUpdated' => $now,
        ];
        if ($this->supportsPauseColumn()) {
            $updateData['pausedAt'] = null;
        }

        $updated = Craft::$app->getDb()->createCommand()->update(self::TABLE, $updateData, ['id' => $id])->execute();

        return $updated > 0;
    }

    public function pauseManagedCredential(int $id): bool
    {
        if ($id <= 0 || !$this->credentialsTableExists() || !$this->supportsPauseColumn()) {
            return false;
        }

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();
        if (!is_array($row)) {
            return false;
        }
        if ($this->isCredentialRevoked($row)) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        $updated = Craft::$app->getDb()->createCommand()->update(self::TABLE, [
            'pausedAt' => $now,
            'dateUpdated' => $now,
        ], ['id' => $id])->execute();

        if ($updated > 0) {
            return true;
        }

        $fresh = (new Query())
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();
        if (!is_array($fresh) || $this->isCredentialRevoked($fresh)) {
            return false;
        }

        return $this->isCredentialPaused($fresh);
    }

    public function resumeManagedCredential(int $id): bool
    {
        if ($id <= 0 || !$this->credentialsTableExists() || !$this->supportsPauseColumn()) {
            return false;
        }

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();
        if (!is_array($row)) {
            return false;
        }
        if ($this->isCredentialRevoked($row)) {
            return false;
        }

        $updated = Craft::$app->getDb()->createCommand()->update(self::TABLE, [
            'pausedAt' => null,
            'dateUpdated' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id])->execute();

        if ($updated > 0) {
            return true;
        }

        $fresh = (new Query())
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();
        if (!is_array($fresh) || $this->isCredentialRevoked($fresh)) {
            return false;
        }

        return !$this->isCredentialPaused($fresh);
    }

    public function updateManagedCredential(
        int $id,
        string $displayName,
        string $description,
        string $owner,
        ?int $ownerUserId,
        array $approvalRecipientUserIds,
        bool $forceHumanApproval,
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
        $normalizedDescription = $this->normalizeDescription($description);
        $normalizedOwnerUserId = $this->normalizeUserId($ownerUserId);
        $normalizedOwner = $this->resolveOwnerLegacyValue($normalizedOwnerUserId, $owner);
        $normalizedScopes = $this->normalizeScopes($scopes, $defaultScopes);
        $normalizedWebhookSubscriptions = $this->normalizeWebhookSubscriptions($webhookSubscriptions);
        $normalizedExpiryPolicy = $this->resolveUpdateExpiryPolicy($row, $expiryPolicy);
        $normalizedIpAllowlist = $this->resolveUpdateIpAllowlist($row, $networkPolicy['ipAllowlist'] ?? null);
        $normalizedApprovalRecipientUserIds = $forceHumanApproval
            ? $this->normalizeUserIds($approvalRecipientUserIds)
            : [];
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
        if ($this->supportsOwnerColumn()) {
            $updateData['owner'] = $normalizedOwner !== '' ? $normalizedOwner : null;
        }
        if ($this->supportsDescriptionColumn()) {
            $updateData['description'] = $normalizedDescription !== '' ? $normalizedDescription : null;
        }
        if ($this->supportsOwnerUserIdColumn()) {
            $updateData['ownerUserId'] = $normalizedOwnerUserId;
        }
        if ($this->supportsForceHumanApprovalColumn()) {
            $updateData['forceHumanApproval'] = $forceHumanApproval ? 1 : 0;
        }
        if ($this->supportsApprovalRecipientUserIdsColumn()) {
            $updateData['approvalRecipientUserIds'] = $this->encodeJson($normalizedApprovalRecipientUserIds);
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

    public function recordCredentialUse(int $id, string $authMethod, string $ip, string $requestMethod = ''): void
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

        $this->recordCredentialPulse($id, $requestMethod);
    }

    public function getCredentialUsagePulseSnapshot(array $credentialIds, ?int $sinceMs = null): array
    {
        $normalizedIds = [];
        foreach ($credentialIds as $rawId) {
            $id = (int)$rawId;
            if ($id <= 0 || in_array($id, $normalizedIds, true)) {
                continue;
            }
            $normalizedIds[] = $id;
        }

        if (empty($normalizedIds)) {
            return [];
        }

        $events = [];
        $cache = Craft::$app->getCache();
        foreach ($normalizedIds as $id) {
            $key = $this->credentialPulseCacheKey($id);
            $payload = $cache->get($key);
            if (!is_array($payload)) {
                continue;
            }

            $lastSeenAt = isset($payload['lastSeenAt']) ? (int)$payload['lastSeenAt'] : 0;
            if ($lastSeenAt <= 0) {
                continue;
            }
            if ($sinceMs !== null && $sinceMs > 0 && $lastSeenAt <= $sinceMs) {
                continue;
            }

            $operation = strtolower(trim((string)($payload['op'] ?? 'read')));
            if (!in_array($operation, ['read', 'write'], true)) {
                $operation = 'read';
            }

            $events[(string)$id] = [
                'lastSeenAt' => $lastSeenAt,
                'op' => $operation,
            ];
        }

        return $events;
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

    private function supportsPauseColumn(): bool
    {
        if ($this->supportsPauseColumn !== null) {
            return $this->supportsPauseColumn;
        }

        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            $this->supportsPauseColumn = false;
            return false;
        }

        $this->supportsPauseColumn = $schema->getColumn('pausedAt') !== null;
        return $this->supportsPauseColumn;
    }

    private function supportsOwnerColumn(): bool
    {
        if ($this->supportsOwnerColumn !== null) {
            return $this->supportsOwnerColumn;
        }

        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            $this->supportsOwnerColumn = false;
            return false;
        }

        $this->supportsOwnerColumn = $schema->getColumn('owner') !== null;
        return $this->supportsOwnerColumn;
    }

    private function supportsDescriptionColumn(): bool
    {
        if ($this->supportsDescriptionColumn !== null) {
            return $this->supportsDescriptionColumn;
        }

        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            $this->supportsDescriptionColumn = false;
            return false;
        }

        $this->supportsDescriptionColumn = $schema->getColumn('description') !== null;
        return $this->supportsDescriptionColumn;
    }

    private function supportsForceHumanApprovalColumn(): bool
    {
        if ($this->supportsForceHumanApprovalColumn !== null) {
            return $this->supportsForceHumanApprovalColumn;
        }

        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            $this->supportsForceHumanApprovalColumn = false;
            return false;
        }

        $this->supportsForceHumanApprovalColumn = $schema->getColumn('forceHumanApproval') !== null;
        return $this->supportsForceHumanApprovalColumn;
    }

    private function supportsOwnerUserIdColumn(): bool
    {
        if ($this->supportsOwnerUserIdColumn !== null) {
            return $this->supportsOwnerUserIdColumn;
        }

        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            $this->supportsOwnerUserIdColumn = false;
            return false;
        }

        $this->supportsOwnerUserIdColumn = $schema->getColumn('ownerUserId') !== null;
        return $this->supportsOwnerUserIdColumn;
    }

    private function supportsApprovalRecipientUserIdsColumn(): bool
    {
        if ($this->supportsApprovalRecipientUserIdsColumn !== null) {
            return $this->supportsApprovalRecipientUserIdsColumn;
        }

        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            $this->supportsApprovalRecipientUserIdsColumn = false;
            return false;
        }

        $this->supportsApprovalRecipientUserIdsColumn = $schema->getColumn('approvalRecipientUserIds') !== null;
        return $this->supportsApprovalRecipientUserIdsColumn;
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

    private function normalizeOwner(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $owner = trim((string)$value);
        if ($owner === '') {
            return '';
        }

        if (strlen($owner) > 255) {
            $owner = substr($owner, 0, 255);
        }

        return $owner;
    }

    private function normalizeDescription(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $description = trim((string)$value);
        if ($description === '') {
            return '';
        }

        if (strlen($description) > 255) {
            $description = substr($description, 0, 255);
        }

        return $description;
    }

    private function normalizeUserId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    private function normalizeUserIds(mixed $value): array
    {
        $tokens = [];
        if (is_array($value)) {
            $tokens = $value;
        } elseif ($value !== null && $value !== '') {
            $tokens = [$value];
        }

        $ids = [];
        foreach ($tokens as $token) {
            if (!is_numeric($token)) {
                continue;
            }

            $id = (int)$token;
            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
        }

        sort($ids);
        return $ids;
    }

    private function decodeOwnerUserId(array $row): ?int
    {
        if (!$this->supportsOwnerUserIdColumn()) {
            return null;
        }

        return $this->normalizeUserId($row['ownerUserId'] ?? null);
    }

    private function decodeApprovalRecipientUserIds(array $row): array
    {
        if (!$this->supportsApprovalRecipientUserIdsColumn()) {
            return [];
        }

        $raw = $row['approvalRecipientUserIds'] ?? null;
        if (!is_string($raw) && !is_numeric($raw)) {
            return [];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeUserIds($decoded);
    }

    private function resolveOwnerLegacyValue(?int $ownerUserId, string $fallback): string
    {
        if ($ownerUserId !== null) {
            $user = User::find()
                ->id($ownerUserId)
                ->status(null)
                ->one();
            if ($user instanceof User) {
                $email = trim((string)$user->email);
                if ($email !== '') {
                    return $email;
                }

                $username = trim((string)$user->username);
                if ($username !== '') {
                    return $username;
                }

                $label = trim((string)$user->friendlyName);
                if ($label !== '') {
                    return $label;
                }
            }
        }

        return $this->normalizeOwner($fallback);
    }

    private function loadReferencedUsers(array $rows): array
    {
        $userIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $ownerUserId = $this->decodeOwnerUserId($row);
            if ($ownerUserId !== null && !in_array($ownerUserId, $userIds, true)) {
                $userIds[] = $ownerUserId;
            }

            foreach ($this->decodeApprovalRecipientUserIds($row) as $userId) {
                if (!in_array($userId, $userIds, true)) {
                    $userIds[] = $userId;
                }
            }
        }

        if ($userIds === []) {
            return [];
        }

        $users = User::find()
            ->id($userIds)
            ->status(null)
            ->all();

        $byId = [];
        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $email = trim((string)$user->email);
            $username = trim((string)$user->username);
            $label = trim((string)$user->friendlyName);
            if ($label === '') {
                $label = $email !== '' ? $email : $username;
            }

            $byId[(int)$user->id] = [
                'id' => (int)$user->id,
                'label' => $label,
                'email' => $email,
                'username' => $username,
            ];
        }

        return $byId;
    }

    private function decodeForceHumanApproval(array $row): bool
    {
        if (!$this->supportsForceHumanApprovalColumn()) {
            return true;
        }

        return (bool)($row['forceHumanApproval'] ?? true);
    }

    private function normalizeProvidedToken(string $value): string
    {
        $token = trim($value);
        if ($token === '') {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9:_\\-.]+$/', $token)) {
            throw new \InvalidArgumentException('API token may only contain letters, digits, colon, underscore, dash, and period.');
        }

        return $token;
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

    private function isCredentialPaused(array $row): bool
    {
        if (!$this->supportsPauseColumn()) {
            return false;
        }

        return $this->normalizeDateString($row['pausedAt'] ?? null) !== null;
    }

    private function isCredentialRevoked(array $row): bool
    {
        $value = $row['revokedAt'] ?? null;
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        return trim((string)$value) !== '';
    }

    private function recordCredentialPulse(int $id, string $requestMethod): void
    {
        if ($id <= 0) {
            return;
        }

        $payload = [
            'lastSeenAt' => (int)floor(microtime(true) * 1000),
            'op' => $this->resolvePulseOperation($requestMethod),
        ];

        Craft::$app->getCache()->set(
            $this->credentialPulseCacheKey($id),
            $payload,
            self::USAGE_PULSE_TTL_SECONDS
        );
    }

    private function resolvePulseOperation(string $requestMethod): string
    {
        $method = strtoupper(trim($requestMethod));
        if (in_array($method, self::USAGE_PULSE_WRITE_METHODS, true)) {
            return 'write';
        }
        if (in_array($method, self::USAGE_PULSE_READ_METHODS, true)) {
            return 'read';
        }

        return 'read';
    }

    private function credentialPulseCacheKey(int $id): string
    {
        return self::USAGE_PULSE_CACHE_KEY_PREFIX . $id;
    }

    private function resolveCredentialMode(bool $isRevoked, bool $isPaused, array $expiryPolicy): string
    {
        if ($isRevoked) {
            return 'revoked';
        }

        if ((bool)($expiryPolicy['isExpired'] ?? false)) {
            return 'expired';
        }

        if ($isPaused) {
            return 'paused';
        }

        if ((bool)($expiryPolicy['isExpiringSoon'] ?? false)) {
            return 'expiring_soon';
        }

        return 'active';
    }

    private function decodeExpiryPolicy(array $row): array
    {
        $default = [
            'expiresAt' => null,
            'expiryReminderDays' => self::DEFAULT_EXPIRY_REMINDER_DAYS,
            'expiresInDays' => null,
            'isExpired' => false,
            'isExpiringSoon' => false,
            'isNeverExpire' => false,
            'status' => 'none',
        ];

        if (!$this->supportsExpiryColumns()) {
            return $default;
        }

        $expiresAtRaw = $this->normalizeDateString($row['expiresAt'] ?? null);
        $expiryReminderDays = $this->normalizeReminderDays($row['expiryReminderDays'] ?? self::DEFAULT_EXPIRY_REMINDER_DAYS);
        if ($expiresAtRaw === null) {
            $isNeverExpire = $this->isNeverExpirePolicy($row);
            $default['expiryReminderDays'] = $isNeverExpire ? 0 : $expiryReminderDays;
            $default['isNeverExpire'] = $isNeverExpire;
            $default['status'] = $isNeverExpire ? 'never' : 'none';
            return $default;
        }

        $expiresTimestamp = strtotime($expiresAtRaw);
        if ($expiresTimestamp === false) {
            $isNeverExpire = $this->isNeverExpirePolicy($row);
            $default['expiryReminderDays'] = $isNeverExpire ? 0 : $expiryReminderDays;
            $default['isNeverExpire'] = $isNeverExpire;
            $default['status'] = $isNeverExpire ? 'never' : 'none';
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
            'isNeverExpire' => false,
            'status' => $isExpired ? 'expired' : ($isExpiringSoon ? 'expiring_soon' : 'active'),
        ];
    }

    private function resolveCreateExpiryPolicy(array $input): array
    {
        $ttlDays = $this->normalizeNullableInt($input['ttlDays'] ?? null);
        $expiryReminderDays = $this->normalizeReminderDays($input['expiryReminderDays'] ?? null);
        $expiresAt = null;
        if ($ttlDays === 0) {
            $expiresAt = null;
            $expiryReminderDays = 0;
        } elseif ($ttlDays !== null && $ttlDays > 0) {
            $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlDays * 86400));
        }

        return [
            'expiresAt' => $expiresAt,
            'expiryReminderDays' => $expiryReminderDays,
        ];
    }

    private function resolveUpdateExpiryPolicy(array $row, array $input): array
    {
        $currentExpiry = $this->normalizeDateString($row['expiresAt'] ?? null);
        $currentReminder = $this->isNeverExpirePolicy($row)
            ? 0
            : $this->normalizeReminderDays($row['expiryReminderDays'] ?? self::DEFAULT_EXPIRY_REMINDER_DAYS);

        $ttlDays = $this->normalizeNullableInt($input['ttlDays'] ?? null);
        $reminderDays = $this->normalizeNullableInt($input['expiryReminderDays'] ?? null);

        $expiresAt = $currentExpiry;
        $resolvedReminderDays = $reminderDays === null ? $currentReminder : $this->normalizeReminderDays($reminderDays);
        if ($ttlDays !== null) {
            if ($ttlDays === 0) {
                $expiresAt = null;
                $resolvedReminderDays = 0;
            } elseif ($ttlDays < 0) {
                $expiresAt = null;
            } else {
                $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlDays * 86400));
            }
        }

        return [
            'expiresAt' => $expiresAt,
            'expiryReminderDays' => $resolvedReminderDays,
        ];
    }

    private function isNeverExpirePolicy(array $row): bool
    {
        if (!$this->supportsExpiryColumns()) {
            return false;
        }

        $raw = $row['expiryReminderDays'] ?? null;
        if (!is_numeric($raw)) {
            return false;
        }

        return (int)$raw === 0;
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

            if ($value === 'entries:write') {
                $value = 'entries:write:draft';
            }

            $normalized[] = $value;
        }

        $normalized = array_values(array_unique($normalized));
        $normalized = $this->filterUnavailableScopes($normalized);
        sort($normalized);

        if (!empty($normalized)) {
            return $normalized;
        }

        $fallback = array_values(array_unique(array_map('strval', $defaultScopes)));
        $fallback = $this->filterUnavailableScopes($fallback);
        sort($fallback);
        return $fallback;
    }

    private function filterUnavailableScopes(array $scopes): array
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
        $prefixSource = trim($handle);
        if (method_exists(StringHelper::class, 'toKebabCase')) {
            $prefix = (string)StringHelper::toKebabCase($prefixSource);
        } elseif (method_exists(StringHelper::class, 'toHandle')) {
            $prefix = (string)StringHelper::toHandle($prefixSource);
        } else {
            $prefix = strtolower($prefixSource);
        }

        $prefix = strtolower($prefix);
        $prefix = preg_replace('/[_\s]+/', '-', $prefix) ?: '';
        $prefix = preg_replace('/[^a-z0-9-]+/', '-', $prefix) ?: '';
        $prefix = preg_replace('/-+/', '-', $prefix) ?: '';
        $prefix = trim($prefix, '-');
        if ($prefix === '') {
            $prefix = 'agent';
        }

        return sprintf('%s-%s', $prefix, $this->generateRandomTokenSegment(self::TOKEN_RANDOM_SEGMENT_LENGTH));
    }

    private function generateRandomTokenSegment(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $alphabet = self::TOKEN_ALPHABET;
        $alphabetLength = strlen($alphabet);
        if ($alphabetLength <= 0) {
            return str_repeat('a', $length);
        }

        $bytes = '';
        try {
            $bytes = random_bytes($length);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Unable to generate a secure API token because random_bytes() failed.', 0, $e);
        }

        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[ord($bytes[$i]) % $alphabetLength];
        }

        return $token;
    }
}
