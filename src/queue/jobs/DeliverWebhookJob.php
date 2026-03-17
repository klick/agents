<?php

namespace Klick\Agents\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use Klick\Agents\Plugin;
use yii\base\InvalidConfigException;
use yii\queue\RetryableJobInterface;

class DeliverWebhookJob extends BaseJob implements RetryableJobInterface
{
    public string $url = '';
    public string $secret = '';
    public array $payload = [];
    public int $timeoutSeconds = 5;
    public int $maxAttempts = 3;
    public string $eventId = '';

    public function execute($queue): void
    {
        if ($this->url === '') {
            throw new InvalidConfigException('Webhook URL is required.');
        }

        if ($this->secret === '') {
            throw new InvalidConfigException('Webhook secret is required.');
        }

        $result = Plugin::getInstance()->getWebhookService()->deliverPayloadNow($this->payload, [
            'url' => $this->url,
            'secret' => $this->secret,
            'timeoutSeconds' => $this->timeoutSeconds,
        ]);

        $statusCode = (int)($result['statusCode'] ?? 0);
        if (!(bool)($result['ok'] ?? false)) {
            $reason = trim((string)($result['reasonPhrase'] ?? ''));
            throw new \RuntimeException(sprintf(
                'Webhook delivery failed with HTTP %d%s.',
                $statusCode,
                $reason !== '' ? ' (' . $reason . ')' : ''
            ));
        }
    }

    public function getTtr(): int
    {
        return max(30, $this->timeoutSeconds + 15);
    }

    public function canRetry($attempt, $error): bool
    {
        if ($attempt >= max(1, $this->maxAttempts)) {
            try {
                Plugin::getInstance()?->getWebhookService()->recordDeadLetter(
                    is_array($this->payload) ? $this->payload : [],
                    (int)$attempt,
                    (string)($error instanceof \Throwable ? $error->getMessage() : 'Webhook delivery failed.')
                );
            } catch (\Throwable $e) {
                Craft::warning('Unable to store webhook dead-letter event: ' . $e->getMessage(), __METHOD__);
            }
        }

        return $attempt < max(1, $this->maxAttempts);
    }

    protected function defaultDescription(): ?string
    {
        $eventId = (string)($this->payload['id'] ?? $this->eventId);
        $resourceType = (string)($this->payload['resourceType'] ?? 'resource');
        $resourceId = (string)($this->payload['resourceId'] ?? '?');

        return sprintf('Delivering agents webhook %s (%s:%s)', $eventId, $resourceType, $resourceId);
    }
}
