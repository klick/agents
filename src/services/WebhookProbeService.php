<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use Klick\Agents\Plugin;
use yii\db\Query;

class WebhookProbeService extends Component
{
    private const TABLE = '{{%agents_webhook_probe_runs}}';
    private const MAX_CP_ROWS = 12;
    private const COOLDOWN_SECONDS = 300;

    public function getCpSnapshot(int $limit = self::MAX_CP_ROWS): array
    {
        $config = Plugin::getInstance()->getWebhookService()->getWebhookConfig();
        $storageReady = $this->runsTableExists();
        $rows = $storageReady ? $this->getRecentRuns($limit) : [];
        $cooldown = $this->resolveCooldownState();

        $summary = [
            'total' => 0,
            'delivered' => 0,
            'failed' => 0,
            'lastAttemptAt' => null,
            'lastDeliveredAt' => null,
            'lastFailedAt' => null,
        ];

        if ($storageReady) {
            $summary['total'] = (int)((new Query())->from(self::TABLE)->count('*'));
            $summary['delivered'] = (int)((new Query())->from(self::TABLE)->where(['status' => 'delivered'])->count('*'));
            $summary['failed'] = (int)((new Query())->from(self::TABLE)->where(['status' => 'failed'])->count('*'));
            $summary['lastAttemptAt'] = $this->findLatestDateCreated();
            $summary['lastDeliveredAt'] = $this->findLatestDateCreated('delivered');
            $summary['lastFailedAt'] = $this->findLatestDateCreated('failed');
        }

        return [
            'config' => [
                'urlConfigured' => trim((string)($config['url'] ?? '')) !== '',
                'secretConfigured' => trim((string)($config['secret'] ?? '')) !== '',
                'enabled' => (bool)($config['enabled'] ?? false),
                'targetUrl' => (string)($config['url'] ?? ''),
                'storageReady' => $storageReady,
                'cooldownSeconds' => self::COOLDOWN_SECONDS,
                'cooldownActive' => (bool)($cooldown['active'] ?? false),
                'cooldownRemainingSeconds' => (int)($cooldown['remainingSeconds'] ?? 0),
                'nextAllowedAt' => $cooldown['nextAllowedAt'] ?? null,
            ],
            'summary' => $summary,
            'runs' => $rows,
        ];
    }

    public function sendProductionProbe(?User $actor = null): array
    {
        if (!$this->runsTableExists()) {
            throw new \RuntimeException('Webhook probe storage is unavailable. Run plugin migrations first.');
        }

        $config = Plugin::getInstance()->getWebhookService()->getWebhookConfig();
        if (!(bool)($config['enabled'] ?? false)) {
            throw new \RuntimeException('Webhook delivery is not fully configured.');
        }

        $cooldown = $this->resolveCooldownState();
        if ((bool)($cooldown['active'] ?? false)) {
            $remaining = max(1, (int)($cooldown['remainingSeconds'] ?? 0));
            throw new \RuntimeException(sprintf('Webhook probe cooldown is active for another %d second%s.', $remaining, $remaining === 1 ? '' : 's'));
        }

        $eventId = 'evt_webhook_probe_' . gmdate('YmdHis') . '_' . substr(str_replace('-', '', StringHelper::UUID()), 0, 8);
        $probeId = 'probe_' . gmdate('YmdHis') . '_' . substr(str_replace('-', '', StringHelper::UUID()), 0, 8);
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        $payload = $this->buildProbePayload($probeId, $eventId, $nowIso, $actor);
        $actorLabel = $this->resolveActorLabel($actor);
        $targetUrl = (string)($config['url'] ?? '');

        try {
            $delivery = Plugin::getInstance()->getWebhookService()->deliverPayloadNow($payload, [
                'url' => $targetUrl,
                'secret' => (string)($config['secret'] ?? ''),
                'timeoutSeconds' => (int)($config['timeoutSeconds'] ?? 5),
            ]);

            $status = (bool)($delivery['ok'] ?? false) ? 'delivered' : 'failed';
            $run = $this->storeRun([
                'probeId' => $probeId,
                'eventId' => $eventId,
                'status' => $status,
                'deliveryMode' => 'sync',
                'httpStatusCode' => (int)($delivery['statusCode'] ?? 0) ?: null,
                'httpReason' => trim((string)($delivery['reasonPhrase'] ?? '')) ?: null,
                'errorMessage' => $status === 'delivered'
                    ? null
                    : sprintf(
                        'Webhook delivery failed with HTTP %d%s.',
                        (int)($delivery['statusCode'] ?? 0),
                        trim((string)($delivery['reasonPhrase'] ?? '')) !== '' ? ' (' . trim((string)($delivery['reasonPhrase'] ?? '')) . ')' : ''
                    ),
                'triggeredByUserId' => $actor?->id,
                'triggeredByLabel' => $actorLabel,
                'targetUrl' => $targetUrl,
                'payload' => $payload,
            ]);

            if ($status !== 'delivered') {
                throw new \RuntimeException((string)($run['errorMessage'] ?? 'Webhook probe delivery failed.'));
            }

            return [
                'probeId' => $probeId,
                'eventId' => $eventId,
                'payload' => $payload,
                'run' => $run,
                'delivery' => $delivery,
            ];
        } catch (\Throwable $e) {
            $run = $this->storeRun([
                'probeId' => $probeId,
                'eventId' => $eventId,
                'status' => 'failed',
                'deliveryMode' => 'sync',
                'httpStatusCode' => null,
                'httpReason' => null,
                'errorMessage' => $e->getMessage(),
                'triggeredByUserId' => $actor?->id,
                'triggeredByLabel' => $actorLabel,
                'targetUrl' => $targetUrl,
                'payload' => $payload,
            ]);

            throw new \RuntimeException((string)($run['errorMessage'] ?? $e->getMessage()), 0, $e);
        }
    }

    public function getRecentRuns(int $limit = self::MAX_CP_ROWS): array
    {
        if (!$this->runsTableExists()) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($this->normalizeLimit($limit, self::MAX_CP_ROWS, 100))
            ->all();

        $runs = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $runs[] = $this->hydrateRun($row);
        }

        return $runs;
    }

    private function buildProbePayload(string $probeId, string $eventId, string $nowIso, ?User $actor = null): array
    {
        return [
            'id' => $eventId,
            'eventKind' => 'probe',
            'isProbe' => true,
            'probeId' => $probeId,
            'occurredAt' => $nowIso,
            'triggeredAt' => $nowIso,
            'resourceType' => 'system',
            'resourceId' => 'webhook-probe',
            'action' => 'probe',
            'updatedAt' => $nowIso,
            'snapshot' => [
                'title' => 'Agents Production Webhook Probe',
                'source' => 'cp-send-production-probe',
                'environment' => (string)(Craft::$app->env ?? App::env('ENVIRONMENT') ?? 'unknown'),
            ],
            'triggeredBy' => [
                'id' => $actor?->id,
                'username' => $actor?->username ?: null,
                'email' => $actor?->email ?: null,
                'label' => $this->resolveActorLabel($actor),
            ],
        ];
    }

    private function storeRun(array $attributes): array
    {
        $payloadJson = Json::encode($attributes['payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $now = gmdate('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
            'probeId' => $this->normalizeOptionalString($attributes['probeId'] ?? null, 64) ?? StringHelper::UUID(),
            'eventId' => $this->normalizeOptionalString($attributes['eventId'] ?? null, 64) ?? StringHelper::UUID(),
            'status' => $this->normalizeOptionalString($attributes['status'] ?? null, 16) ?? 'failed',
            'deliveryMode' => $this->normalizeOptionalString($attributes['deliveryMode'] ?? null, 16) ?? 'sync',
            'httpStatusCode' => isset($attributes['httpStatusCode']) ? (int)$attributes['httpStatusCode'] : null,
            'httpReason' => $this->normalizeOptionalString($attributes['httpReason'] ?? null, 255),
            'errorMessage' => $this->normalizeOptionalString($attributes['errorMessage'] ?? null, 65535),
            'triggeredByUserId' => isset($attributes['triggeredByUserId']) ? (int)$attributes['triggeredByUserId'] : null,
            'triggeredByLabel' => $this->normalizeOptionalString($attributes['triggeredByLabel'] ?? null, 255),
            'targetUrl' => $this->normalizeOptionalString($attributes['targetUrl'] ?? null, 2048),
            'payload' => $payloadJson,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['id' => (int)Craft::$app->getDb()->getLastInsertID(self::TABLE)])
            ->one();

        return is_array($row) ? $this->hydrateRun($row) : [];
    }

    private function hydrateRun(array $row): array
    {
        $payload = [];
        try {
            $decoded = Json::decodeIfJson((string)($row['payload'] ?? ''));
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        } catch (\Throwable) {
            $payload = [];
        }

        $payloadJson = Json::encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson) || $payloadJson === '') {
            $payloadJson = '{}';
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'probeId' => (string)($row['probeId'] ?? ''),
            'eventId' => (string)($row['eventId'] ?? ''),
            'status' => (string)($row['status'] ?? 'failed'),
            'deliveryMode' => (string)($row['deliveryMode'] ?? 'sync'),
            'httpStatusCode' => isset($row['httpStatusCode']) ? (int)$row['httpStatusCode'] : null,
            'httpReason' => (string)($row['httpReason'] ?? ''),
            'errorMessage' => (string)($row['errorMessage'] ?? ''),
            'triggeredByUserId' => isset($row['triggeredByUserId']) ? (int)$row['triggeredByUserId'] : null,
            'triggeredByLabel' => (string)($row['triggeredByLabel'] ?? ''),
            'targetUrl' => (string)($row['targetUrl'] ?? ''),
            'payload' => $payload,
            'payloadJson' => $payloadJson,
            'dateCreated' => (string)($row['dateCreated'] ?? ''),
            'dateUpdated' => (string)($row['dateUpdated'] ?? ''),
        ];
    }

    private function resolveCooldownState(): array
    {
        $lastCreatedAt = $this->findLatestDateCreated();
        if ($lastCreatedAt === null) {
            return [
                'active' => false,
                'remainingSeconds' => 0,
                'nextAllowedAt' => null,
            ];
        }

        $lastTimestamp = strtotime($lastCreatedAt . ' UTC');
        if ($lastTimestamp === false) {
            return [
                'active' => false,
                'remainingSeconds' => 0,
                'nextAllowedAt' => null,
            ];
        }

        $nextAllowedTimestamp = $lastTimestamp + self::COOLDOWN_SECONDS;
        $remainingSeconds = max(0, $nextAllowedTimestamp - time());

        return [
            'active' => $remainingSeconds > 0,
            'remainingSeconds' => $remainingSeconds,
            'nextAllowedAt' => gmdate('Y-m-d H:i:s', $nextAllowedTimestamp),
        ];
    }

    private function findLatestDateCreated(?string $status = null): ?string
    {
        if (!$this->runsTableExists()) {
            return null;
        }

        $query = (new Query())
            ->from(self::TABLE)
            ->select(['dateCreated'])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);

        if ($status !== null && $status !== '') {
            $query->where(['status' => $status]);
        }

        $value = $query->scalar();
        $normalized = trim((string)$value);

        return $normalized !== '' ? $normalized : null;
    }

    private function runsTableExists(): bool
    {
        return Craft::$app->getDb()->tableExists(self::TABLE);
    }

    private function normalizeLimit(int $limit, int $default, int $max): int
    {
        if ($limit <= 0) {
            return $default;
        }

        return min($limit, $max);
    }

    private function resolveActorLabel(?User $actor = null): string
    {
        if (!$actor instanceof User) {
            return 'Unknown admin';
        }

        $fullName = trim((string)$actor->getFriendlyName());
        if ($fullName !== '') {
            return $fullName;
        }

        $username = trim((string)$actor->username);
        if ($username !== '') {
            return $username;
        }

        $email = trim((string)$actor->email);
        if ($email !== '') {
            return $email;
        }

        return sprintf('User #%d', (int)$actor->id);
    }

    private function normalizeOptionalString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > $maxLength) {
            return mb_substr($normalized, 0, $maxLength);
        }

        return $normalized;
    }
}
