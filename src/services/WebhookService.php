<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\Entry;
use craft\helpers\App;
use craft\helpers\Queue;
use DateTimeInterface;
use Klick\Agents\Plugin;
use Klick\Agents\queue\jobs\DeliverWebhookJob;

class WebhookService extends Component
{
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

        $eventId = $this->generateEventId();
        $payload = [
            'id' => $eventId,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'resourceType' => $change['resourceType'],
            'resourceId' => $change['resourceId'],
            'action' => $change['action'],
            'updatedAt' => $change['updatedAt'],
            'snapshot' => $change['snapshot'],
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

        $url = trim((string)App::env('PLUGIN_AGENTS_WEBHOOK_URL'));
        $secret = trim((string)App::env('PLUGIN_AGENTS_WEBHOOK_SECRET'));

        $timeoutSeconds = (int)App::env('PLUGIN_AGENTS_WEBHOOK_TIMEOUT_SECONDS');
        if ($timeoutSeconds <= 0) {
            $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS;
        }
        $timeoutSeconds = min($timeoutSeconds, 60);

        $maxAttempts = (int)App::env('PLUGIN_AGENTS_WEBHOOK_MAX_ATTEMPTS');
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
}
