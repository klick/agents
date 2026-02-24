<?php

namespace Klick\Agents\queue\jobs;

use Craft;
use craft\queue\BaseJob;
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

        $body = json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            throw new \RuntimeException('Unable to encode webhook payload.');
        }

        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);

        $client = Craft::createGuzzleClient([
            'timeout' => max(1, $this->timeoutSeconds),
            'connect_timeout' => max(1, min($this->timeoutSeconds, 5)),
            'http_errors' => false,
        ]);

        $response = $client->post($this->url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'klick-agents-webhook/0.1.2',
                'X-Agents-Webhook-Id' => (string)($this->payload['id'] ?? $this->eventId),
                'X-Agents-Webhook-Timestamp' => $timestamp,
                'X-Agents-Webhook-Signature' => 'sha256=' . $signature,
            ],
            'body' => $body,
        ]);

        $statusCode = (int)$response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $reason = trim((string)$response->getReasonPhrase());
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
