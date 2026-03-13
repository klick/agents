#!/usr/bin/env php
<?php

declare(strict_types=1);

use craft\helpers\Json;
use Klick\Agents\Plugin;
use Klick\Agents\services\NotificationService;
use yii\db\Query;

require findBootstrapPath(__DIR__) . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

const LOG_TABLE = '{{%agents_notification_log}}';
const MONITOR_TABLE = '{{%agents_notification_monitors}}';

$command = $argv[1] ?? '';

try {
    switch ($command) {
        case 'capture-settings':
            respond([
                'ok' => true,
                'settings' => captureSettings(),
            ]);

        case 'restore-settings':
            $payload = requireArg(2, 'Usage: restore-settings <base64-json>');
            restoreSettings($payload);
            respond(['ok' => true]);

        case 'configure-test':
            configureTestSettings();
            respond(['ok' => true]);

        case 'reset':
            resetNotificationTables();
            respond(['ok' => true]);

        case 'queue-approval-request':
            maybeApplyTransientTestSettings();
            $approvalId = (int)($argv[2] ?? 101);
            $queued = notificationService()->queueApprovalRequested([
                'id' => $approvalId,
                'actionType' => 'return.approve',
                'actionLabel' => 'Approve Return',
                'requestedBy' => 'agent:return-ops',
                'reason' => 'QA approval request notification test',
                'requiredApprovals' => 1,
                'slaDueAt' => gmdate('Y-m-d H:i:s', time() + 3600),
            ]);
            respond([
                'ok' => true,
                'queued' => $queued,
                'logs' => fetchLogs(),
            ]);

        case 'queue-webhook-failure':
            maybeApplyTransientTestSettings();
            $eventId = trim((string)($argv[2] ?? 'evt_notification_qa'));
            $queued = notificationService()->queueWebhookDeadLetter([
                'eventId' => $eventId,
                'resourceType' => 'entry',
                'resourceId' => '123',
                'action' => 'updated',
                'attempts' => 3,
                'lastError' => 'QA webhook failure',
            ]);
            respond([
                'ok' => true,
                'queued' => $queued,
                'logs' => fetchLogs(),
            ]);

        case 'notifications-check':
            maybeApplyTransientTestSettings();
            $result = notificationService()->runStatusMonitor();
            respond([
                'ok' => true,
                'result' => $result,
                'monitors' => fetchMonitors(),
            ]);

        case 'logs':
            respond([
                'ok' => true,
                'logs' => fetchLogs(),
                'monitors' => fetchMonitors(),
            ]);

        default:
            fail('Unknown command.', [
                'command' => $command,
                'availableCommands' => [
                    'capture-settings',
                    'restore-settings',
                    'configure-test',
                    'reset',
                    'queue-approval-request',
                    'queue-webhook-failure',
                    'notifications-check',
                    'logs',
                ],
            ]);
    }
} catch (Throwable $e) {
    fail($e->getMessage(), [
        'exception' => get_class($e),
    ]);
}

function plugin(): Plugin
{
    return Plugin::getInstance();
}

function notificationService(): NotificationService
{
    $plugin = plugin();
    if (!method_exists($plugin, 'getNotificationService')) {
        fail('Installed Agents plugin does not expose NotificationService yet. Ensure the sandbox is running this branch or a build that includes F17.');
    }

    /** @var NotificationService $service */
    $service = $plugin->getNotificationService();
    return $service;
}

function findBootstrapPath(string $startDir): string
{
    $dir = $startDir;
    for ($i = 0; $i < 12; $i++) {
        $candidate = $dir . '/bootstrap.php';
        if (is_file($candidate)) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    fail('Unable to locate Craft bootstrap.php from notification harness.', [
        'startDir' => $startDir,
    ]);
}

function captureSettings(): string
{
    $settings = plugin()->getSettings();
    $payload = [
        'notificationsEnabled' => (bool)($settings->notificationsEnabled ?? false),
        'notificationRecipients' => (string)($settings->notificationRecipients ?? ''),
        'notificationApprovalRequested' => (bool)($settings->notificationApprovalRequested ?? true),
        'notificationApprovalDecided' => (bool)($settings->notificationApprovalDecided ?? true),
        'notificationExecutionFailed' => (bool)($settings->notificationExecutionFailed ?? true),
        'notificationWebhookDlqFailed' => (bool)($settings->notificationWebhookDlqFailed ?? true),
        'notificationStatusChanged' => (bool)($settings->notificationStatusChanged ?? true),
    ];

    return base64_encode(Json::encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
}

function restoreSettings(string $encoded): void
{
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || $decoded === '') {
        fail('Invalid settings payload.');
    }

    $settings = Json::decodeIfJson($decoded);
    if (!is_array($settings)) {
        fail('Decoded settings payload is invalid.');
    }

    Craft::$app->getPlugins()->savePluginSettings(plugin(), $settings);
}

function configureTestSettings(): void
{
    $settings = testSettingsPayload();
    Craft::$app->getPlugins()->savePluginSettings(plugin(), $settings);
    plugin()->setSettings($settings);
}

function maybeApplyTransientTestSettings(): void
{
    global $argv;

    if (!in_array('--test-settings', $argv, true)) {
        return;
    }

    plugin()->setSettings(testSettingsPayload());
}

function testSettingsPayload(): array
{
    return [
        'notificationsEnabled' => true,
        'notificationRecipients' => 'qa-notifications@example.test',
        'notificationApprovalRequested' => true,
        'notificationApprovalDecided' => true,
        'notificationExecutionFailed' => true,
        'notificationWebhookDlqFailed' => true,
        'notificationStatusChanged' => true,
    ];
}

function resetNotificationTables(): void
{
    $db = Craft::$app->getDb();
    if ($db->tableExists('{{%queue}}')) {
        $db->createCommand("DELETE FROM {{%queue}} WHERE description LIKE 'Send operator notification #%'")->execute();
    }
    if ($db->tableExists(LOG_TABLE)) {
        $db->createCommand()->delete(LOG_TABLE)->execute();
    }
    if ($db->tableExists(MONITOR_TABLE)) {
        $db->createCommand()->delete(MONITOR_TABLE)->execute();
    }
}

function fetchLogs(): array
{
    if (!Craft::$app->getDb()->tableExists(LOG_TABLE)) {
        return [];
    }

    $rows = (new Query())
        ->from(LOG_TABLE)
        ->orderBy(['id' => SORT_ASC])
        ->all();

    return array_map(static function(array $row): array {
        return [
            'id' => (int)($row['id'] ?? 0),
            'eventType' => (string)($row['eventType'] ?? ''),
            'fingerprint' => (string)($row['fingerprint'] ?? ''),
            'recipient' => (string)($row['recipient'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'attempts' => (int)($row['attempts'] ?? 0),
        ];
    }, $rows);
}

function fetchMonitors(): array
{
    if (!Craft::$app->getDb()->tableExists(MONITOR_TABLE)) {
        return [];
    }

    $rows = (new Query())
        ->from(MONITOR_TABLE)
        ->orderBy(['id' => SORT_ASC])
        ->all();

    return array_map(static function(array $row): array {
        return [
            'monitorKey' => (string)($row['monitorKey'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
        ];
    }, $rows);
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
