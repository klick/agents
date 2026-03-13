<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\Entry;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use DateTimeInterface;
use Klick\Agents\Plugin;
use Klick\Agents\queue\jobs\DeliverWebhookJob;
use yii\db\Query;

class WebhookService extends Component
{
    private const TABLE_DLQ = '{{%agents_webhook_dlq}}';
    private const PRODUCT_CLASS = 'craft\\commerce\\elements\\Product';
    private const ORDER_CLASS = 'craft\\commerce\\elements\\Order';
    private const VARIANT_CLASS = 'craft\\commerce\\elements\\Variant';
    private const DEFAULT_TIMEOUT_SECONDS = 5;
    private const DEFAULT_MAX_ATTEMPTS = 3;

    private ?array $webhookConfig = null;

    public function queueElementChange(Element $element, string $operation, bool $isNew = false): void
    {
        if (!Plugin::getInstance()->isAgentsEnabled()) {
            return;
        }

        $config = $this->getWebhookConfig();
        if (!$config['enabled']) {
            return;
        }

        if ($element->propagating) {
            return;
        }

        if (method_exists($element, 'getIsDraft') && $element->getIsDraft()) {
            return;
        }

        if (method_exists($element, 'getIsRevision') && $element->getIsRevision()) {
            return;
        }

        $change = $this->mapElementChange($element, $operation, $isNew);
        if ($change === null) {
            return;
        }

        $subscriptionState = $this->resolveCredentialSubscriptionState(
            (string)$change['resourceType'],
            (string)$change['action']
        );
        if (!(bool)($subscriptionState['shouldDeliver'] ?? true)) {
            return;
        }

        $eventId = $this->generateEventId();
        $payload = [
            'id' => $eventId,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'resourceType' => $change['resourceType'],
            'resourceId' => $change['resourceId'],
            'action' => $change['action'],
            'updatedAt' => $change['updatedAt'],
            'snapshot' => $change['snapshot'],
            'subscriptions' => [
                'mode' => (string)($subscriptionState['mode'] ?? 'firehose'),
                'credentialHandles' => (array)($subscriptionState['credentialHandles'] ?? []),
            ],
        ];

        try {
            Queue::push(new DeliverWebhookJob([
                'url' => $config['url'],
                'secret' => $config['secret'],
                'payload' => $payload,
                'timeoutSeconds' => $config['timeoutSeconds'],
                'maxAttempts' => $config['maxAttempts'],
                'eventId' => $eventId,
            ]));
        } catch (\Throwable $e) {
            Craft::warning(
                sprintf(
                    'Unable to enqueue webhook delivery for %s:%s (%s): %s',
                    $payload['resourceType'],
                    $payload['resourceId'],
                    $payload['action'],
                    $e->getMessage()
                ),
                __METHOD__
            );
        }
    }

    public function getWebhookConfig(): array
    {
        if ($this->webhookConfig !== null) {
            return $this->webhookConfig;
        }

        $runtimeConfig = Plugin::getInstance()->getSecurityPolicyService()->getRuntimeConfig();
        $url = trim((string)($runtimeConfig['webhookUrl'] ?? ''));
        $secret = trim((string)($runtimeConfig['webhookSecret'] ?? ''));
        $timeoutSeconds = (int)($runtimeConfig['webhookTimeoutSeconds'] ?? self::DEFAULT_TIMEOUT_SECONDS);
        if ($timeoutSeconds <= 0) {
            $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS;
        }
        $timeoutSeconds = min($timeoutSeconds, 60);

        $maxAttempts = (int)($runtimeConfig['webhookMaxAttempts'] ?? self::DEFAULT_MAX_ATTEMPTS);
        if ($maxAttempts <= 0) {
            $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;
        }
        $maxAttempts = min($maxAttempts, 10);

        $this->webhookConfig = [
            'enabled' => $url !== '' && $secret !== '',
            'url' => $url,
            'secret' => $secret,
            'timeoutSeconds' => $timeoutSeconds,
            'maxAttempts' => $maxAttempts,
        ];

        return $this->webhookConfig;
    }

    public function getCredentialSubscriptionStatePreview(string $resourceType, string $action): array
    {
        return $this->resolveCredentialSubscriptionState($resourceType, $action);
    }

    public function recordDeadLetter(array $payload, int $attempts, string $lastError): void
    {
        if (!$this->deadLetterTableExists()) {
            return;
        }

        $eventId = trim((string)($payload['id'] ?? ''));
        if ($eventId === '') {
            $eventId = $this->generateEventId();
        }

        $resourceType = $this->normalizeOptionalString($payload['resourceType'] ?? null, 32);
        $resourceId = $this->normalizeOptionalString($payload['resourceId'] ?? null, 64);
        $action = $this->normalizeOptionalString($payload['action'] ?? null, 16);
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encodedPayload)) {
            $encodedPayload = '{}';
        }

        $now = gmdate('Y-m-d H:i:s');
        $errorMessage = $this->normalizeOptionalString($lastError, 65535);

        $existing = (new Query())
            ->from(self::TABLE_DLQ)
            ->where(['eventId' => $eventId])
            ->one();

        if (is_array($existing)) {
            Craft::$app->getDb()->createCommand()->update(self::TABLE_DLQ, [
                'status' => 'failed',
                'attempts' => max((int)($existing['attempts'] ?? 0), max(1, $attempts)),
                'lastError' => $errorMessage,
                'payload' => $encodedPayload,
                'dateUpdated' => $now,
            ], ['id' => (int)($existing['id'] ?? 0)])->execute();
            $this->queueDeadLetterNotification([
                'id' => (int)($existing['id'] ?? 0),
                'eventId' => $eventId,
                'resourceType' => $resourceType,
                'resourceId' => $resourceId,
                'action' => $action,
                'attempts' => max((int)($existing['attempts'] ?? 0), max(1, $attempts)),
                'lastError' => $errorMessage,
            ]);
            return;
        }

        Craft::$app->getDb()->createCommand()->insert(self::TABLE_DLQ, [
            'eventId' => $eventId,
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'action' => $action,
            'status' => 'failed',
            'attempts' => max(1, $attempts),
            'lastError' => $errorMessage,
            'payload' => $encodedPayload,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        $this->queueDeadLetterNotification([
            'id' => (int)Craft::$app->getDb()->getLastInsertID(),
            'eventId' => $eventId,
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'action' => $action,
            'attempts' => max(1, $attempts),
            'lastError' => $errorMessage,
        ]);
    }

    public function getDeadLetterEvents(array $filters = [], int $limit = 100): array
    {
        if (!$this->deadLetterTableExists()) {
            return [];
        }

        $query = (new Query())
            ->from(self::TABLE_DLQ)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($this->normalizeLimit($limit, 100, 500));

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $query->andWhere(['status' => strtolower($status)]);
        }

        $rows = $query->all();
        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->hydrateDeadLetterEvent($row);
        }

        return $events;
    }

    public function replayDeadLetterEvent(int $id): ?array
    {
        if ($id <= 0 || !$this->deadLetterTableExists()) {
            return null;
        }

        $row = (new Query())
            ->from(self::TABLE_DLQ)
            ->where(['id' => $id])
            ->one();
        if (!is_array($row)) {
            return null;
        }

        $payload = $this->decodePayload((string)($row['payload'] ?? ''));
        $eventId = trim((string)($row['eventId'] ?? ''));
        if ($eventId === '') {
            $eventId = trim((string)($payload['id'] ?? ''));
        }
        if ($eventId === '') {
            $eventId = $this->generateEventId();
        }

        $config = $this->getWebhookConfig();
        if (!(bool)($config['enabled'] ?? false)) {
            throw new \RuntimeException('Webhook replay is unavailable: webhook URL/secret is not configured.');
        }

        Queue::push(new DeliverWebhookJob([
            'url' => (string)$config['url'],
            'secret' => (string)$config['secret'],
            'payload' => $payload,
            'timeoutSeconds' => (int)$config['timeoutSeconds'],
            'maxAttempts' => (int)$config['maxAttempts'],
            'eventId' => $eventId,
        ]));

        Craft::$app->getDb()->createCommand()->update(self::TABLE_DLQ, [
            'status' => 'queued',
            'lastError' => null,
            'dateUpdated' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id])->execute();

        $row['status'] = 'queued';
        $row['lastError'] = null;
        $row['dateUpdated'] = gmdate('Y-m-d H:i:s');
        return $this->hydrateDeadLetterEvent($row);
    }

    public function replayDeadLetterEvents(int $limit = 25): array
    {
        $rows = $this->getDeadLetterEvents(['status' => 'failed'], $limit);
        $replayed = 0;
        $errors = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            try {
                $result = $this->replayDeadLetterEvent($id);
                if (is_array($result)) {
                    $replayed++;
                }
            } catch (\Throwable $e) {
                $errors[] = sprintf('ID %d: %s', $id, $e->getMessage());
            }
        }

        return [
            'attempted' => count($rows),
            'replayed' => $replayed,
            'errors' => $errors,
        ];
    }

    private function resolveCredentialSubscriptionState(string $resourceType, string $action): array
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return [
                'mode' => 'firehose',
                'credentialHandles' => [],
                'shouldDeliver' => true,
            ];
        }

        try {
            $subscriptions = $plugin->getCredentialService()->getManagedWebhookSubscriptions();
        } catch (\Throwable $e) {
            Craft::warning('Unable to evaluate credential webhook subscriptions: ' . $e->getMessage(), __METHOD__);
            return [
                'mode' => 'firehose',
                'credentialHandles' => [],
                'shouldDeliver' => true,
            ];
        }

        if (empty($subscriptions)) {
            return [
                'mode' => 'firehose',
                'credentialHandles' => [],
                'shouldDeliver' => true,
            ];
        }

        $matches = [];
        foreach ($subscriptions as $subscription) {
            if (!$this->matchesCredentialSubscription($subscription, $resourceType, $action)) {
                continue;
            }

            $handle = trim((string)($subscription['handle'] ?? ''));
            if ($handle !== '') {
                $matches[] = $handle;
            }
        }

        $matches = array_values(array_unique($matches));
        sort($matches);

        return [
            'mode' => 'targeted',
            'credentialHandles' => $matches,
            'shouldDeliver' => !empty($matches),
        ];
    }

    private function matchesCredentialSubscription(array $subscription, string $resourceType, string $action): bool
    {
        $subscribedResources = array_values(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($subscription['resourceTypes'] ?? [])
        )));
        $subscribedActions = array_values(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($subscription['actions'] ?? [])
        )));

        $resourceMatches = empty($subscribedResources) || in_array(strtolower($resourceType), $subscribedResources, true);
        if (!$resourceMatches) {
            return false;
        }

        return empty($subscribedActions) || in_array(strtolower($action), $subscribedActions, true);
    }

    private function mapElementChange(Element $element, string $operation, bool $isNew): ?array
    {
        $operation = strtolower(trim($operation));
        $isDeletedOperation = $operation === 'deleted';
        $action = $isDeletedOperation ? 'deleted' : ($isNew ? 'created' : 'updated');
        $updatedAt = $this->resolveUpdatedAt($element, $isDeletedOperation);

        if ($element instanceof Entry) {
            return [
                'resourceType' => 'entry',
                'resourceId' => (string)$element->id,
                'action' => $action,
                'updatedAt' => $updatedAt,
                'snapshot' => $isDeletedOperation ? null : [
                    'id' => (int)$element->id,
                    'title' => (string)$element->title,
                    'slug' => (string)$element->slug,
                    'uri' => (string)$element->uri,
                    'status' => $element->getStatus() ?? null,
                    'updatedAt' => $updatedAt,
                    'url' => $element->getUrl(),
                ],
            ];
        }

        $productClass = self::PRODUCT_CLASS;
        if (class_exists($productClass) && $element instanceof $productClass) {
            return [
                'resourceType' => 'product',
                'resourceId' => (string)$element->id,
                'action' => $action,
                'updatedAt' => $updatedAt,
                'snapshot' => $isDeletedOperation ? null : [
                    'id' => (int)$element->id,
                    'title' => (string)$element->title,
                    'slug' => (string)$element->slug,
                    'uri' => (string)$element->uri,
                    'status' => $element->getStatus() ?? null,
                    'updatedAt' => $updatedAt,
                    'url' => $element->getUrl(),
                ],
            ];
        }

        $orderClass = self::ORDER_CLASS;
        if (class_exists($orderClass) && $element instanceof $orderClass) {
            $orderStatus = $element->getOrderStatus();

            return [
                'resourceType' => 'order',
                'resourceId' => (string)$element->id,
                'action' => $action,
                'updatedAt' => $updatedAt,
                'snapshot' => $isDeletedOperation ? null : [
                    'id' => (int)$element->id,
                    'number' => (string)($element->number ?? ''),
                    'reference' => (string)($element->reference ?? ''),
                    'status' => $orderStatus?->handle,
                    'statusName' => $orderStatus?->name,
                    'isCompleted' => (bool)$element->isCompleted,
                    'isPaid' => (bool)$element->isPaid,
                    'totalPrice' => $element->totalPrice === null ? null : (float)$element->totalPrice,
                    'updatedAt' => $updatedAt,
                ],
            ];
        }

        $variantClass = self::VARIANT_CLASS;
        if (class_exists($variantClass) && $element instanceof $variantClass) {
            $productId = (int)($element->productId ?? 0);
            if ($productId <= 0) {
                return null;
            }

            // Variant changes impact product state, so emit as product updates.
            return [
                'resourceType' => 'product',
                'resourceId' => (string)$productId,
                'action' => 'updated',
                'updatedAt' => $updatedAt,
                'snapshot' => [
                    'id' => $productId,
                    'updatedAt' => $updatedAt,
                ],
            ];
        }

        return null;
    }

    private function resolveUpdatedAt(Element $element, bool $isDeletedOperation): string
    {
        $date = null;
        if ($isDeletedOperation) {
            $date = $element->dateDeleted ?? $element->dateUpdated ?? null;
        } else {
            $date = $element->dateUpdated ?? $element->dateCreated ?? null;
        }

        if ($date instanceof DateTimeInterface) {
            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function generateEventId(): string
    {
        try {
            return 'evt_' . bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return 'evt_' . str_replace('.', '', (string)microtime(true)) . '_' . substr(sha1(uniqid('', true)), 0, 8);
        }
    }

    private function deadLetterTableExists(): bool
    {
        return Craft::$app->getDb()->getTableSchema(self::TABLE_DLQ, true) !== null;
    }

    private function decodePayload(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function hydrateDeadLetterEvent(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'eventId' => (string)($row['eventId'] ?? ''),
            'resourceType' => $this->normalizeOptionalString($row['resourceType'] ?? null, 32),
            'resourceId' => $this->normalizeOptionalString($row['resourceId'] ?? null, 64),
            'action' => $this->normalizeOptionalString($row['action'] ?? null, 16),
            'status' => strtolower(trim((string)($row['status'] ?? 'failed'))),
            'attempts' => (int)($row['attempts'] ?? 0),
            'lastError' => $this->normalizeOptionalString($row['lastError'] ?? null, 65535),
            'payload' => $this->decodePayload((string)($row['payload'] ?? '{}')),
            'dateCreated' => $this->toIso8601($row['dateCreated'] ?? null),
            'dateUpdated' => $this->toIso8601($row['dateUpdated'] ?? null),
        ];
    }

    private function normalizeOptionalString(mixed $value, int $maxLength = 255): ?string
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

    private function queueDeadLetterNotification(array $event): void
    {
        try {
            Plugin::getInstance()->getNotificationService()->queueWebhookDeadLetter($event);
        } catch (\Throwable $e) {
            Craft::warning('Unable to queue webhook dead-letter notification: ' . $e->getMessage(), __METHOD__);
        }
    }
}
