#!/usr/bin/env php
<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\helpers\Json;
use GuzzleHttp\Exception\GuzzleException;
use Klick\Agents\Plugin;
use yii\db\Query;

require dirname(__DIR__, 5) . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

const DLQ_TABLE = '{{%agents_webhook_dlq}}';
const QA_SECTION_HANDLE = 'agentFlowTests';

$command = $argv[1] ?? '';

try {
    switch ($command) {
        case 'snapshot':
            $snapshot = Plugin::getInstance()->getWebhookTestSinkService()->getCpSnapshot();
            respond([
                'ok' => true,
                'snapshot' => $snapshot,
                'dlqCount' => countDlqRows(),
            ]);

        case 'clear':
            $deleted = Plugin::getInstance()->getWebhookTestSinkService()->clearCapturedEvents();
            respond([
                'ok' => true,
                'deleted' => $deleted,
            ]);

        case 'dlq-count':
            respond([
                'ok' => true,
                'count' => countDlqRows(),
            ]);

        case 'http-get':
            $url = requireArg(2, 'Usage: http-get <url>');
            respond(httpGet($url));

        case 'post-sample':
            $url = requireArg(2, 'Usage: post-sample <url> <valid|invalid|unsigned> [eventId]');
            $mode = strtolower(trim((string)($argv[3] ?? 'valid')));
            $eventId = trim((string)($argv[4] ?? 'evt_webhook_test_sink_' . gmdate('YmdHis')));
            respond(postSample($url, $mode, $eventId));

        case 'send-test-delivery':
            respond(sendTestDelivery());

        case 'touch-entry':
            respond(touchQaEntry());

        case 'restore-entry':
            $entryId = (int)requireArg(2, 'Usage: restore-entry <entryId> <siteId> <titleBase64>');
            $siteId = (int)requireArg(3, 'Usage: restore-entry <entryId> <siteId> <titleBase64>');
            $titleBase64 = requireArg(4, 'Usage: restore-entry <entryId> <siteId> <titleBase64>');
            respond(restoreQaEntry($entryId, $siteId, $titleBase64));

        default:
            fail('Unknown command.', [
                'command' => $command,
                'availableCommands' => [
                    'snapshot',
                    'clear',
                    'dlq-count',
                    'http-get',
                    'post-sample',
                    'send-test-delivery',
                    'touch-entry',
                    'restore-entry',
                ], 
            ]);
    }
} catch (Throwable $e) {
    fail($e->getMessage(), [
        'exception' => get_class($e),
    ]);
}

function respond(array $payload, int $exitCode = 0): never
{
    $encoded = Json::encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        $encoded = '{"ok":false,"message":"Unable to encode response."}';
        $exitCode = 1;
    }

    fwrite(STDOUT, $encoded . PHP_EOL);
    exit($exitCode);
}

function fail(string $message, array $context = []): never
{
    respond([
        'ok' => false,
        'message' => $message,
        'context' => $context,
    ], 1);
}

function requireArg(int $index, string $usage): string
{
    global $argv;

    $value = trim((string)($argv[$index] ?? ''));
    if ($value === '') {
        fail($usage);
    }

    return $value;
}

function countDlqRows(): int
{
    if (!Craft::$app->getDb()->tableExists(DLQ_TABLE)) {
        return 0;
    }

    return (int)(new Query())->from(DLQ_TABLE)->count('*');
}

function httpGet(string $url): array
{
    $client = Craft::createGuzzleClient([
        'timeout' => 10,
        'connect_timeout' => 3,
        'http_errors' => false,
    ]);

    try {
        $response = $client->get($url);
    } catch (GuzzleException $e) {
        fail('GET request failed.', [
            'url' => $url,
            'error' => $e->getMessage(),
        ]);
    }

    return normalizeHttpResponse($response->getStatusCode(), (string)$response->getBody());
}

function postSample(string $url, string $mode, string $eventId): array
{
    $secret = trim((string)getenv('PLUGIN_AGENTS_WEBHOOK_SECRET'));
    $timestamp = (string)time();
    $payload = [
        'id' => $eventId,
        'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'resourceType' => 'entry',
        'resourceId' => 'qa-webhook-test-sink',
        'action' => 'updated',
        'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'snapshot' => [
            'title' => 'Webhook Test Sink QA',
            'slug' => 'webhook-test-sink-qa',
        ],
        'subscriptions' => [
            'mode' => 'firehose',
            'credentialHandles' => [],
        ],
    ];

    $body = Json::encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        fail('Unable to encode sample payload.');
    }

    $headers = [
        'Content-Type' => 'application/json',
        'X-Agents-Webhook-Id' => $eventId,
        'X-Agents-Webhook-Timestamp' => $timestamp,
    ];

    if ($mode === 'valid') {
        $headers['X-Agents-Webhook-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    } elseif ($mode === 'invalid') {
        $headers['X-Agents-Webhook-Signature'] = 'sha256=' . str_repeat('0', 64);
    } elseif ($mode !== 'unsigned') {
        fail('Unsupported sample mode.', ['mode' => $mode]);
    }

    $client = Craft::createGuzzleClient([
        'timeout' => 10,
        'connect_timeout' => 3,
        'http_errors' => false,
    ]);

    try {
        $response = $client->post($url, [
            'headers' => $headers,
            'body' => $body,
        ]);
    } catch (GuzzleException $e) {
        fail('POST request failed.', [
            'url' => $url,
            'mode' => $mode,
            'error' => $e->getMessage(),
        ]);
    }

    $result = normalizeHttpResponse($response->getStatusCode(), (string)$response->getBody());
    $result['request'] = [
        'mode' => $mode,
        'eventId' => $eventId,
        'payload' => $payload,
    ];

    return $result;
}

function sendTestDelivery(): array
{
    $result = Plugin::getInstance()->getWebhookTestSinkService()->sendTestDelivery();

    return [
        'ok' => true,
        'eventId' => (string)($result['eventId'] ?? ''),
        'captured' => is_array($result['captured'] ?? null) ? $result['captured'] : null,
        'payload' => is_array($result['payload'] ?? null) ? $result['payload'] : [],
    ];
}

function touchQaEntry(): array
{
    $entry = Entry::find()
        ->section(QA_SECTION_HANDLE)
        ->status(null)
        ->siteId('*')
        ->orderBy('elements.id asc')
        ->one();

    if (!$entry instanceof Entry) {
        fail('No QA entry is available in the agentFlowTests section.');
    }

    $originalTitle = (string)$entry->title;
    $entry->title = trim($originalTitle . ' [Webhook QA ' . gmdate('Y-m-d H:i:s') . ']');

    $saved = Craft::$app->getElements()->saveElement($entry, saveContent: true);
    if (!$saved) {
        fail('Unable to save QA entry.', [
            'errors' => $entry->getErrors(),
            'entryId' => (int)$entry->id,
        ]);
    }

    return [
        'ok' => true,
        'entryId' => (int)$entry->id,
        'siteId' => (int)$entry->siteId,
        'section' => QA_SECTION_HANDLE,
        'originalTitleBase64' => base64_encode($originalTitle),
        'updatedTitle' => (string)$entry->title,
    ];
}

function restoreQaEntry(int $entryId, int $siteId, string $titleBase64): array
{
    $entry = Entry::find()
        ->id($entryId)
        ->status(null)
        ->siteId($siteId > 0 ? $siteId : '*')
        ->one();

    if (!$entry instanceof Entry) {
        fail('Unable to find QA entry to restore.', [
            'entryId' => $entryId,
            'siteId' => $siteId,
        ]);
    }

    $decodedTitle = base64_decode($titleBase64, true);
    if (!is_string($decodedTitle)) {
        fail('Unable to decode original QA entry title.');
    }

    $entry->title = $decodedTitle;
    $saved = Craft::$app->getElements()->saveElement($entry, saveContent: true);
    if (!$saved) {
        fail('Unable to restore QA entry.', [
            'errors' => $entry->getErrors(),
            'entryId' => $entryId,
            'siteId' => $siteId,
        ]);
    }

    return [
        'ok' => true,
        'entryId' => $entryId,
        'siteId' => $siteId,
        'restoredTitle' => $decodedTitle,
    ];
}

function normalizeHttpResponse(int $statusCode, string $body): array
{
    $decoded = json_decode($body, true);

    return [
        'ok' => $statusCode >= 200 && $statusCode < 300,
        'statusCode' => $statusCode,
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}
