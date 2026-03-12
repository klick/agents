<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use Klick\Agents\Plugin;
use Klick\Agents\queue\jobs\DeliverWebhookJob;
use yii\db\Query;
use yii\web\Request;

class WebhookTestSinkService extends Component
{
    private const TABLE = '{{%agents_webhook_test_sink_events}}';
    private const ROUTE_PATH = 'agents/dev/webhook-test-sink';
    private const MAX_CP_EVENTS = 20;
    private const MAX_STORED_EVENTS = 200;

    private ?array $sinkConfig = null;

    public function getConfig(): array
    {
        if ($this->sinkConfig !== null) {
            return $this->sinkConfig;
        }

        $requested = (bool)App::parseBooleanEnv('$PLUGIN_AGENTS_WEBHOOK_TEST_SINK');
        $devMode = (bool)Craft::$app->getConfig()->getGeneral()->devMode;
        $url = UrlHelper::siteUrl(self::ROUTE_PATH);
        $runtimeConfig = Plugin::getInstance()->getSecurityPolicyService()->getRuntimeConfig();
        $configuredWebhookUrl = trim((string)($runtimeConfig['webhookUrl'] ?? ''));
        $configuredWebhookSecret = trim((string)($runtimeConfig['webhookSecret'] ?? ''));

        $this->sinkConfig = [
            'requested' => $requested,
            'devMode' => $devMode,
            'enabled' => $requested && $devMode,
            'path' => self::ROUTE_PATH,
            'url' => $url,
            'webhookUrlConfigured' => $configuredWebhookUrl !== '',
            'webhookSecretConfigured' => $configuredWebhookSecret !== '',
            'webhookUrlMatchesSink' => $configuredWebhookUrl !== '' && $this->normalizeComparableUrl($configuredWebhookUrl) === $this->normalizeComparableUrl($url),
            'storageReady' => $this->eventsTableExists(),
            'retentionLimit' => self::MAX_STORED_EVENTS,
        ];

        return $this->sinkConfig;
    }

    public function isEnabled(): bool
    {
        return (bool)($this->getConfig()['enabled'] ?? false);
    }

    public function captureRequest(Request $request): array
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('Webhook test sink is unavailable.');
        }

        if (!$this->eventsTableExists()) {
            throw new \RuntimeException('Webhook test sink storage is unavailable. Run plugin migrations first.');
        }

        $headers = $this->normalizeHeaders($request->getHeaders()->toArray());
        $rawBody = (string)$request->getRawBody();
        $payload = $this->decodePayload($rawBody);
        $eventId = trim((string)($payload['id'] ?? $this->firstHeaderValue($headers, 'x-agents-webhook-id')));
        $resourceType = $this->normalizeOptionalString($payload['resourceType'] ?? null, 32);
        $resourceId = $this->normalizeOptionalString($payload['resourceId'] ?? null, 64);
        $action = $this->normalizeOptionalString($payload['action'] ?? null, 16);
        $requestMethod = strtoupper(trim((string)$request->getMethod()));
        if ($requestMethod === '') {
            $requestMethod = 'POST';
        }

        $contentType = $this->normalizeOptionalString($request->getContentType(), 128);
        $userAgent = $this->normalizeOptionalString((string)$request->getUserAgent(), 255);
        $remoteIp = $this->normalizeOptionalString((string)$request->getUserIP(), 64);
        $headersJson = Json::encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($headersJson)) {
            $headersJson = '{}';
        }

        $verificationStatus = $this->resolveVerificationStatus(
            $rawBody,
            $this->firstHeaderValue($headers, 'x-agents-webhook-timestamp'),
            $this->firstHeaderValue($headers, 'x-agents-webhook-signature')
        );

        $now = gmdate('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
            'eventId' => $eventId !== '' ? $eventId : null,
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'action' => $action,
            'verificationStatus' => $verificationStatus,
            'requestMethod' => $requestMethod,
            'contentType' => $contentType,
            'remoteIp' => $remoteIp,
            'userAgent' => $userAgent,
            'payloadBytes' => strlen($rawBody),
            'headers' => $headersJson,
            'payload' => $rawBody,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $id = (int)Craft::$app->getDb()->getLastInsertID(self::TABLE);
        $this->pruneCapturedEvents();

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();

        if (!is_array($row)) {
            return [
                'id' => $id,
                'eventId' => $eventId,
                'resourceType' => $resourceType,
                'resourceId' => $resourceId,
                'action' => $action,
                'verificationStatus' => $verificationStatus,
                'requestMethod' => $requestMethod,
                'payloadBytes' => strlen($rawBody),
                'dateCreated' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        }

        return $this->hydrateEvent($row);
    }

    public function getCpSnapshot(int $limit = self::MAX_CP_EVENTS): array
    {
        $config = $this->getConfig();
        $events = $this->getCapturedEvents($limit);
        $summary = [
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
            'unsigned' => 0,
            'secretMissing' => 0,
            'lastCapturedAt' => $events[0]['dateCreated'] ?? null,
        ];

        if ($config['storageReady']) {
            $summary['total'] = (int)((new Query())->from(self::TABLE)->count('*'));
            $summary['valid'] = (int)((new Query())->from(self::TABLE)->where(['verificationStatus' => 'valid'])->count('*'));
            $summary['invalid'] = (int)((new Query())->from(self::TABLE)->where(['verificationStatus' => 'invalid'])->count('*'));
            $summary['unsigned'] = (int)((new Query())->from(self::TABLE)->where(['verificationStatus' => 'unsigned'])->count('*'));
            $summary['secretMissing'] = (int)((new Query())->from(self::TABLE)->where(['verificationStatus' => 'secret-missing'])->count('*'));
        }

        return [
            'config' => $config,
            'summary' => $summary,
            'events' => $events,
        ];
    }

    public function getCapturedEvents(int $limit = self::MAX_CP_EVENTS): array
    {
        if (!$this->eventsTableExists()) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($this->normalizeLimit($limit, self::MAX_CP_EVENTS, self::MAX_STORED_EVENTS))
            ->all();

        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->hydrateEvent($row);
        }

        return $events;
    }

    public function clearCapturedEvents(): int
    {
        if (!$this->eventsTableExists()) {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()->delete(self::TABLE)->execute();
    }

    public function sendTestDelivery(): array
    {
        $config = $this->getConfig();
        if (!(bool)($config['enabled'] ?? false)) {
            throw new \RuntimeException('Webhook test sink is unavailable.');
        }

        if (!(bool)($config['storageReady'] ?? false)) {
            throw new \RuntimeException('Webhook test sink storage is unavailable. Run plugin migrations first.');
        }

        if (!(bool)($config['webhookUrlMatchesSink'] ?? false)) {
            throw new \RuntimeException('Configure PLUGIN_AGENTS_WEBHOOK_URL to point to the local sink before sending a test delivery.');
        }

        if (!(bool)($config['webhookSecretConfigured'] ?? false)) {
            throw new \RuntimeException('Configure PLUGIN_AGENTS_WEBHOOK_SECRET before sending a test delivery.');
        }

        $webhookConfig = Plugin::getInstance()->getWebhookService()->getWebhookConfig();
        if (!(bool)($webhookConfig['enabled'] ?? false)) {
            throw new \RuntimeException('Webhook delivery is not fully configured.');
        }

        $eventId = 'evt_test_sink_probe_' . gmdate('YmdHis') . '_' . substr(str_replace('-', '', StringHelper::UUID()), 0, 8);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $subscriptionState = Plugin::getInstance()->getWebhookService()->getCredentialSubscriptionStatePreview('entry', 'updated');
        $payload = [
            'id' => $eventId,
            'occurredAt' => $now,
            'resourceType' => 'entry',
            'resourceId' => 'webhook-test-sink-probe',
            'action' => 'updated',
            'updatedAt' => $now,
            'snapshot' => [
                'title' => 'Webhook Test Sink Probe',
                'source' => 'cp-send-test-webhook',
                'environment' => (string)(Craft::$app->env ?? App::env('ENVIRONMENT') ?? 'unknown'),
            ],
            'subscriptions' => [
                'mode' => (string)($subscriptionState['mode'] ?? 'firehose'),
                'credentialHandles' => array_values((array)($subscriptionState['credentialHandles'] ?? [])),
            ],
        ];

        $job = new DeliverWebhookJob([
            'url' => (string)($webhookConfig['url'] ?? ''),
            'secret' => (string)($webhookConfig['secret'] ?? ''),
            'payload' => $payload,
            'timeoutSeconds' => (int)($webhookConfig['timeoutSeconds'] ?? 5),
            'maxAttempts' => 1,
            'eventId' => $eventId,
        ]);

        $job->execute(Craft::$app->getQueue());

        $captured = $this->findCapturedEventByEventId($eventId);

        return [
            'eventId' => $eventId,
            'payload' => $payload,
            'captured' => $captured,
        ];
    }

    private function pruneCapturedEvents(): void
    {
        if (!$this->eventsTableExists()) {
            return;
        }

        $idsToDelete = (new Query())
            ->select(['id'])
            ->from(self::TABLE)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->offset(self::MAX_STORED_EVENTS)
            ->column();

        $ids = array_values(array_filter(array_map('intval', $idsToDelete), static fn(int $id): bool => $id > 0));
        if (empty($ids)) {
            return;
        }

        Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['id' => $ids])->execute();
    }

    private function hydrateEvent(array $row): array
    {
        $headers = $this->decodeJsonObject((string)($row['headers'] ?? '{}'));
        $payload = $this->decodePayload((string)($row['payload'] ?? ''));
        $subscriptionState = (array)($payload['subscriptions'] ?? []);

        $prettyPayload = (string)($row['payload'] ?? '');
        if (!empty($payload)) {
            $prettyCandidate = Json::encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($prettyCandidate)) {
                $prettyPayload = $prettyCandidate;
            }
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'eventId' => trim((string)($row['eventId'] ?? '')),
            'resourceType' => trim((string)($row['resourceType'] ?? '')),
            'resourceId' => trim((string)($row['resourceId'] ?? '')),
            'action' => trim((string)($row['action'] ?? '')),
            'verificationStatus' => trim((string)($row['verificationStatus'] ?? 'unsigned')),
            'requestMethod' => strtoupper(trim((string)($row['requestMethod'] ?? 'POST'))),
            'contentType' => trim((string)($row['contentType'] ?? '')),
            'remoteIp' => trim((string)($row['remoteIp'] ?? '')),
            'userAgent' => trim((string)($row['userAgent'] ?? '')),
            'payloadBytes' => (int)($row['payloadBytes'] ?? 0),
            'headers' => $headers,
            'headersJson' => Json::encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'payload' => $payload,
            'payloadJson' => $prettyPayload,
            'subscriptionMode' => trim((string)($subscriptionState['mode'] ?? '')),
            'subscriptionCredentialHandles' => array_values(array_filter(array_map(
                static fn(mixed $value): string => trim((string)$value),
                (array)($subscriptionState['credentialHandles'] ?? [])
            ))),
            'dateCreated' => (string)($row['dateCreated'] ?? ''),
            'dateUpdated' => (string)($row['dateUpdated'] ?? ''),
        ];
    }

    private function resolveVerificationStatus(string $rawBody, ?string $timestamp, ?string $signatureHeader): string
    {
        $secret = trim((string)(Plugin::getInstance()->getSecurityPolicyService()->getRuntimeConfig()['webhookSecret'] ?? ''));
        if ($secret === '') {
            return 'secret-missing';
        }

        $signatureHeader = trim((string)$signatureHeader);
        if ($timestamp === null || trim($timestamp) === '' || $signatureHeader === '') {
            return 'unsigned';
        }

        $providedSignature = $signatureHeader;
        if (str_starts_with($providedSignature, 'sha256=')) {
            $providedSignature = substr($providedSignature, 7);
        }

        $expectedSignature = hash_hmac('sha256', trim($timestamp) . '.' . $rawBody, $secret);
        return hash_equals($expectedSignature, $providedSignature) ? 'valid' : 'invalid';
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $headerName = trim((string)$name);
            if ($headerName === '') {
                continue;
            }

            $values = is_array($value) ? $value : [$value];
            $normalized[$headerName] = array_values(array_map(static fn(mixed $item): string => trim((string)$item), $values));
        }

        ksort($normalized);
        return $normalized;
    }

    private function firstHeaderValue(array $headers, string $headerName): ?string
    {
        foreach ($headers as $name => $values) {
            if (strcasecmp((string)$name, $headerName) !== 0) {
                continue;
            }

            $items = is_array($values) ? $values : [$values];
            foreach ($items as $item) {
                $value = trim((string)$item);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function decodePayload(string $rawBody): array
    {
        $trimmed = trim($rawBody);
        if ($trimmed === '') {
            return [];
        }

        try {
            $decoded = Json::decode($trimmed);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function decodeJsonObject(string $json): array
    {
        $trimmed = trim($json);
        if ($trimmed === '') {
            return [];
        }

        try {
            $decoded = Json::decode($trimmed);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeComparableUrl(string $url): string
    {
        $parsed = parse_url(trim($url));
        if (!is_array($parsed)) {
            return trim($url);
        }

        $scheme = strtolower((string)($parsed['scheme'] ?? ''));
        $host = strtolower((string)($parsed['host'] ?? ''));
        $port = isset($parsed['port']) ? ':' . (int)$parsed['port'] : '';
        $path = '/' . ltrim((string)($parsed['path'] ?? ''), '/');

        return sprintf('%s://%s%s%s', $scheme, $host, $port, $path);
    }

    private function normalizeOptionalString(mixed $value, int $maxLength): ?string
    {
        $string = trim((string)$value);
        if ($string === '') {
            return null;
        }

        return mb_substr($string, 0, max(1, $maxLength));
    }

    private function normalizeLimit(int $requested, int $default, int $max): int
    {
        if ($requested <= 0) {
            $requested = $default;
        }

        return min($requested, $max);
    }

    private function findCapturedEventByEventId(string $eventId): ?array
    {
        $eventId = trim($eventId);
        if ($eventId === '' || !$this->eventsTableExists()) {
            return null;
        }

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['eventId' => $eventId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        return is_array($row) ? $this->hydrateEvent($row) : null;
    }

    private function eventsTableExists(): bool
    {
        return Craft::$app->getDb()->tableExists(self::TABLE);
    }
}
