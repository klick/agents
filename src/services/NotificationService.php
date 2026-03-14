<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\mail\Message;
use DateTimeInterface;
use Klick\Agents\Plugin;
use Klick\Agents\queue\jobs\SendOperatorNotificationJob;
use yii\db\Query;

class NotificationService extends Component
{
    public const TABLE_LOG = '{{%agents_notification_log}}';
    public const TABLE_MONITORS = '{{%agents_notification_monitors}}';

    public const EVENT_APPROVAL_REQUESTED = 'approval_requested';
    public const EVENT_APPROVAL_DECIDED = 'approval_decided';
    public const EVENT_EXECUTION_ISSUE = 'execution_issue';
    public const EVENT_WEBHOOK_DLQ_FAILED = 'webhook_dlq_failed';
    public const EVENT_STATUS_CHANGED = 'status_changed';

    private const STATUS_QUEUED = 'queued';
    private const STATUS_SENT = 'sent';
    private const STATUS_FAILED = 'failed';
    private const CHANNEL_EMAIL = 'email';
    private const SYSTEM_MONITOR_KEY = 'system-status';

    public function getRuntimeConfig(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $this->parseRecipientList((string)($settings->notificationRecipients ?? ''));

        return [
            'enabled' => (bool)($settings->notificationsEnabled ?? false),
            'recipients' => $recipients,
            'eventToggles' => [
                self::EVENT_APPROVAL_REQUESTED => (bool)($settings->notificationApprovalRequested ?? true),
                self::EVENT_APPROVAL_DECIDED => (bool)($settings->notificationApprovalDecided ?? true),
                self::EVENT_EXECUTION_ISSUE => (bool)($settings->notificationExecutionFailed ?? true),
                self::EVENT_WEBHOOK_DLQ_FAILED => (bool)($settings->notificationWebhookDlqFailed ?? true),
                self::EVENT_STATUS_CHANGED => (bool)($settings->notificationStatusChanged ?? true),
            ],
        ];
    }

    public function getCpSnapshot(int $limit = 20): array
    {
        $config = $this->getRuntimeConfig();
        $globalRecipients = $this->normalizeResolvedRecipients((array)($config['recipients'] ?? []));
        $accountRecipients = $this->getAccountApprovalRecipients();
        $effectiveRecipients = $globalRecipients;
        foreach ($accountRecipients as $recipient) {
            $effectiveRecipients[$recipient] = $recipient;
        }
        $effectiveRecipients = array_values($effectiveRecipients);
        $recentNotifications = $this->getRecentNotifications($limit);
        $recipientUsersByEmail = $this->resolveRecipientUsersByEmail(array_merge(
            $effectiveRecipients,
            array_map(static fn(array $row): string => (string)($row['recipient'] ?? ''), $recentNotifications)
        ));
        $recentNotifications = $this->enrichRecentNotifications($recentNotifications, $recipientUsersByEmail);
        $summary = [
            'total' => count($recentNotifications),
            'queued' => 0,
            'sent' => 0,
            'failed' => 0,
            'lastSentAt' => null,
            'lastSentRecipient' => null,
            'lastSentSubject' => null,
            'lastFailureAt' => null,
            'lastFailureRecipient' => null,
        ];

        foreach ($recentNotifications as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if ($status === self::STATUS_SENT) {
                $summary['sent']++;
                if ($summary['lastSentAt'] === null && !empty($row['sentAt'])) {
                    $summary['lastSentAt'] = (string)$row['sentAt'];
                    $summary['lastSentRecipient'] = (string)($row['recipient'] ?? '');
                    $summary['lastSentSubject'] = (string)($row['subject'] ?? '');
                }
            } elseif ($status === self::STATUS_FAILED) {
                $summary['failed']++;
                if ($summary['lastFailureAt'] === null) {
                    $summary['lastFailureAt'] = (string)($row['lastAttemptAt'] ?? $row['dateUpdated'] ?? $row['dateCreated'] ?? '');
                    $summary['lastFailureRecipient'] = (string)($row['recipient'] ?? '');
                }
            } elseif ($status === self::STATUS_QUEUED) {
                $summary['queued']++;
            }
        }

        return [
            'config' => [
                'enabled' => (bool)($config['enabled'] ?? false),
                'recipients' => $effectiveRecipients,
                'recipientCount' => count($effectiveRecipients),
                'globalRecipients' => $globalRecipients,
                'globalRecipientCount' => count($globalRecipients),
                'accountRecipients' => $accountRecipients,
                'accountRecipientCount' => count($accountRecipients),
                'eventToggles' => (array)($config['eventToggles'] ?? []),
            ],
            'summary' => $summary,
            'systemMonitor' => $this->getMonitorState(self::SYSTEM_MONITOR_KEY),
            'recentNotifications' => $recentNotifications,
        ];
    }

    public function queueApprovalRequested(array $approval): int
    {
        $approvalId = (int)($approval['id'] ?? 0);
        if ($approvalId <= 0) {
            return 0;
        }

        $subject = sprintf('[Agents] Approval requested: %s (#%d)', (string)($approval['actionLabel'] ?? $approval['actionType'] ?? 'action'), $approvalId);
        $body = $this->composeLines([
            sprintf('A new approval request is waiting for decision in Agents.'),
            '',
            sprintf('Request: #%d', $approvalId),
            sprintf('Action: %s', (string)($approval['actionLabel'] ?? $approval['actionType'] ?? 'Unknown action')),
            $this->lineIfPresent('Reference', $approval['actionRef'] ?? null),
            $this->lineIfPresent('Requested by', $approval['requestedByLabel'] ?? $approval['requestedBy'] ?? null),
            $this->lineIfPresent('Reason', $approval['reason'] ?? null),
            $this->lineIfPresent('Approvals required', isset($approval['requiredApprovals']) ? (string)$approval['requiredApprovals'] : null),
            $this->lineIfPresent('SLA due', $approval['slaDueAt'] ?? null),
            '',
            sprintf('Open Approvals: %s', UrlHelper::cpUrl('agents/approvals')),
        ]);

        return $this->queueEmailNotification(
            self::EVENT_APPROVAL_REQUESTED,
            sprintf('approval-requested:%d', $approvalId),
            $subject,
            $body,
            ['approval' => $approval],
            86400,
            $this->resolveManagedCredentialRecipientEmails((string)($approval['requestedBy'] ?? ''))
        );
    }

    public function queueApprovalDecided(array $approval): int
    {
        $approvalId = (int)($approval['id'] ?? 0);
        $status = strtolower(trim((string)($approval['status'] ?? '')));
        if ($approvalId <= 0 || !in_array($status, ['approved', 'rejected'], true)) {
            return 0;
        }

        $subject = sprintf(
            '[Agents] Approval %s: %s (#%d)',
            $status === 'approved' ? 'approved' : 'rejected',
            (string)($approval['actionLabel'] ?? $approval['actionType'] ?? 'action'),
            $approvalId
        );
        $body = $this->composeLines([
            sprintf('An approval request in Agents has been %s.', $status),
            '',
            sprintf('Request: #%d', $approvalId),
            sprintf('Action: %s', (string)($approval['actionLabel'] ?? $approval['actionType'] ?? 'Unknown action')),
            $this->lineIfPresent('Requested by', $approval['requestedByLabel'] ?? $approval['requestedBy'] ?? null),
            $this->lineIfPresent('Decided by', $approval['decidedByLabel'] ?? $approval['secondaryDecisionByLabel'] ?? $approval['decidedBy'] ?? null),
            $this->lineIfPresent('Decision reason', $approval['decisionReason'] ?? $approval['secondaryDecisionReason'] ?? null),
            '',
            sprintf('Open Approvals: %s', UrlHelper::cpUrl('agents/approvals')),
        ]);

        return $this->queueEmailNotification(
            self::EVENT_APPROVAL_DECIDED,
            sprintf('approval-decided:%d:%s', $approvalId, $status),
            $subject,
            $body,
            ['approval' => $approval],
            86400,
            $this->resolveManagedCredentialRecipientEmails((string)($approval['requestedBy'] ?? ''))
        );
    }

    public function queueExecutionIssue(array $execution): int
    {
        $executionId = (int)($execution['id'] ?? 0);
        $status = strtolower(trim((string)($execution['status'] ?? '')));
        if ($executionId <= 0 || !in_array($status, ['blocked', 'failed'], true)) {
            return 0;
        }

        $errorMessage = trim((string)($execution['errorMessage'] ?? ''));
        if (
            $status === 'blocked'
            && (int)($execution['approvalId'] ?? 0) <= 0
            && in_array($errorMessage, [
                'Approval is required before execution.',
                'This account requires human approval before execution.',
            ], true)
        ) {
            return 0;
        }

        $subject = sprintf('[Agents] Execution %s: %s (#%d)', $status, (string)($execution['actionLabel'] ?? $execution['actionType'] ?? 'action'), $executionId);
        $body = $this->composeLines([
            sprintf('A governed execution in Agents finished as %s.', $status),
            '',
            sprintf('Execution: #%d', $executionId),
            sprintf('Action: %s', (string)($execution['actionLabel'] ?? $execution['actionType'] ?? 'Unknown action')),
            $this->lineIfPresent('Requested by', $execution['requestedByLabel'] ?? $execution['requestedBy'] ?? null),
            $this->lineIfPresent('Approval request', isset($execution['approvalId']) && (int)$execution['approvalId'] > 0 ? '#' . (int)$execution['approvalId'] : null),
            $this->lineIfPresent('Error', $errorMessage),
            '',
            sprintf('Open Approvals: %s', UrlHelper::cpUrl('agents/approvals')),
        ]);

        return $this->queueEmailNotification(
            self::EVENT_EXECUTION_ISSUE,
            sprintf('execution-issue:%d:%s', $executionId, $status),
            $subject,
            $body,
            ['execution' => $execution],
            86400,
            $this->resolveManagedCredentialRecipientEmails((string)($execution['requestedBy'] ?? ''))
        );
    }

    public function queueWebhookDeadLetter(array $event): int
    {
        $eventId = trim((string)($event['eventId'] ?? $event['id'] ?? ''));
        if ($eventId === '') {
            return 0;
        }

        $subject = sprintf('[Agents] Webhook delivery failed: %s', $eventId);
        $body = $this->composeLines([
            'An outbound webhook delivery failed and was placed into the dead-letter queue.',
            '',
            $this->lineIfPresent('Event', $eventId),
            $this->lineIfPresent('Resource', $event['resourceType'] ?? null),
            $this->lineIfPresent('Resource ID', $event['resourceId'] ?? null),
            $this->lineIfPresent('Action', $event['action'] ?? null),
            $this->lineIfPresent('Attempts', isset($event['attempts']) ? (string)$event['attempts'] : null),
            $this->lineIfPresent('Last error', $event['lastError'] ?? null),
            '',
            sprintf('Open Status: %s', UrlHelper::cpUrl('agents/status#webhookDlqDetailSection')),
        ]);

        return $this->queueEmailNotification(
            self::EVENT_WEBHOOK_DLQ_FAILED,
            sprintf('webhook-dlq:%s', $eventId),
            $subject,
            $body,
            ['event' => $event],
            21600
        );
    }

    public function runStatusMonitor(): array
    {
        $snapshot = $this->buildSystemStatusSnapshot();
        $currentStatus = (string)($snapshot['status'] ?? 'ok');
        $monitor = $this->getMonitorState(self::SYSTEM_MONITOR_KEY);
        $previousStatus = (string)($monitor['status'] ?? 'unknown');
        $changed = $previousStatus !== $currentStatus;
        $notified = false;
        $queued = 0;

        $this->upsertMonitorState(self::SYSTEM_MONITOR_KEY, $currentStatus, $snapshot);

        if ($changed && (($previousStatus !== '' && $previousStatus !== 'unknown') || $currentStatus !== 'ok')) {
            $queued = $this->queueStatusChanged($previousStatus, $currentStatus, $snapshot);
            $notified = $queued > 0;
        }

        return [
            'monitorKey' => self::SYSTEM_MONITOR_KEY,
            'previousStatus' => $previousStatus,
            'currentStatus' => $currentStatus,
            'changed' => $changed,
            'notified' => $notified,
            'queued' => $queued,
            'snapshot' => $snapshot,
        ];
    }

    public function processNotificationLog(int $notificationId): bool
    {
        if ($notificationId <= 0 || !$this->tableExists(self::TABLE_LOG)) {
            return false;
        }

        $row = $this->findNotificationLogById($notificationId);
        if (!is_array($row)) {
            return false;
        }

        $recipient = trim((string)($row['recipient'] ?? ''));
        $subject = trim((string)($row['subject'] ?? ''));
        $body = (string)($row['bodyText'] ?? '');
        if ($recipient === '' || $subject === '' || $body === '') {
            $this->markNotificationFailed($notificationId, 'Notification log entry is incomplete.');
            return false;
        }

        $attempts = max(0, (int)($row['attempts'] ?? 0)) + 1;
        $lastAttemptAt = gmdate('Y-m-d H:i:s');

        try {
            $mailer = Craft::$app->getMailer();
            $message = new Message();

            $message
                ->setTo($recipient)
                ->setSubject($subject)
                ->setTextBody($body);

            if (!$mailer->send($message)) {
                throw new \RuntimeException('Craft mailer returned false while sending notification email.');
            }

            Craft::$app->getDb()->createCommand()->update(self::TABLE_LOG, [
                'status' => self::STATUS_SENT,
                'attempts' => $attempts,
                'lastAttemptAt' => $lastAttemptAt,
                'sentAt' => $lastAttemptAt,
                'errorMessage' => null,
                'dateUpdated' => $lastAttemptAt,
            ], ['id' => $notificationId])->execute();

            return true;
        } catch (\Throwable $e) {
            $this->markNotificationFailed($notificationId, $e->getMessage(), $attempts, $lastAttemptAt);
            Craft::warning('Unable to send operator notification: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    public function getRecentNotifications(int $limit = 20): array
    {
        if (!$this->tableExists(self::TABLE_LOG)) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE_LOG)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(max(1, min($limit, 100)))
            ->all();

        return array_map(fn(array $row) => $this->hydrateNotificationLog($row), $rows);
    }

    private function queueStatusChanged(string $previousStatus, string $currentStatus, array $snapshot): int
    {
        $transitionLabel = match ($currentStatus) {
            'critical' => 'blocked',
            'warn' => 'degraded',
            default => 'healthy',
        };

        $subject = match ($currentStatus) {
            'critical' => '[Agents] System status blocked',
            'warn' => '[Agents] System status degraded',
            default => '[Agents] System status recovered',
        };

        $detailLines = [];
        foreach ((array)($snapshot['issues'] ?? []) as $issue) {
            $detailLines[] = '- ' . (string)$issue;
        }
        if ($detailLines === []) {
            $detailLines[] = '- No active warning or critical issues remain.';
        }

        $body = $this->composeLines(array_merge([
            sprintf('Agents system status changed from %s to %s.', strtoupper($previousStatus), strtoupper($currentStatus)),
            sprintf('Current posture: %s.', $transitionLabel),
            '',
            sprintf('Auth status: %s', strtoupper((string)($snapshot['auth']['status'] ?? 'ok'))),
            sprintf('Reliability status: %s', strtoupper((string)($snapshot['reliability']['status'] ?? 'ok'))),
            sprintf('Lifecycle status: %s', strtoupper((string)($snapshot['lifecycle']['status'] ?? 'ok'))),
            '',
            'Current issues:',
        ], $detailLines, [
            '',
            sprintf('Open Status: %s', UrlHelper::cpUrl('agents/status')),
        ]));

        return $this->queueEmailNotification(
            self::EVENT_STATUS_CHANGED,
            sprintf('system-status:%s', $currentStatus),
            $subject,
            $body,
            ['snapshot' => $snapshot, 'previousStatus' => $previousStatus, 'currentStatus' => $currentStatus],
            1800
        );
    }

    private function buildSystemStatusSnapshot(): array
    {
        $plugin = Plugin::getInstance();
        $auth = $this->buildAuthStatus();
        $reliability = $plugin->getReliabilitySignalService()->evaluateSnapshot(
            $plugin->getObservabilityMetricsService()->getMetricsSnapshot()
        );
        $lifecycle = $plugin->getLifecycleGovernanceService()->getSnapshot();

        $status = 'ok';
        $issues = [];

        foreach ((array)($auth['errors'] ?? []) as $message) {
            $issues[] = (string)$message;
            $status = $this->maxStatus($status, 'critical');
        }
        foreach ((array)($auth['warnings'] ?? []) as $message) {
            $issues[] = (string)$message;
            $status = $this->maxStatus($status, 'warn');
        }

        $reliabilityStatus = (string)($reliability['status'] ?? 'ok');
        $status = $this->maxStatus($status, $reliabilityStatus);
        foreach ((array)($reliability['topSignals'] ?? []) as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $severity = strtolower(trim((string)($signal['severity'] ?? 'ok')));
            if (!in_array($severity, ['warn', 'critical'], true)) {
                continue;
            }
            $issues[] = sprintf(
                '%s is %s (value=%s).',
                (string)($signal['label'] ?? $signal['id'] ?? 'signal'),
                $severity,
                (string)($signal['value'] ?? '0')
            );
        }

        $lifecycleStatus = (string)($lifecycle['status'] ?? 'ok');
        $status = $this->maxStatus($status, $lifecycleStatus);
        foreach (array_slice((array)($lifecycle['topRisks'] ?? []), 0, 3) as $risk) {
            if (!is_array($risk)) {
                continue;
            }
            $recommendedAction = trim((string)($risk['risk']['recommendedAction'] ?? ''));
            $displayName = trim((string)($risk['displayName'] ?? $risk['handle'] ?? 'credential'));
            if ($recommendedAction !== '') {
                $issues[] = sprintf('%s: %s', $displayName, $recommendedAction);
            }
        }

        return [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'status' => $status,
            'auth' => $auth,
            'reliability' => [
                'status' => $reliabilityStatus,
                'summary' => (array)($reliability['summary'] ?? []),
                'topSignals' => (array)($reliability['topSignals'] ?? []),
            ],
            'lifecycle' => [
                'status' => $lifecycleStatus,
                'summary' => (array)($lifecycle['summary'] ?? []),
                'topRisks' => (array)($lifecycle['topRisks'] ?? []),
            ],
            'issues' => array_values(array_unique(array_filter($issues, static fn(string $message): bool => trim($message) !== ''))),
        ];
    }

    private function buildAuthStatus(): array
    {
        $service = Plugin::getInstance()->getSecurityPolicyService();
        $config = $service->getRuntimeConfig();
        $warningsRaw = $service->getWarnings();

        $errors = [];
        $warnings = [];

        foreach ($warningsRaw as $warning) {
            $message = trim((string)($warning['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            if (($warning['level'] ?? 'warning') === 'error') {
                $errors[] = $message;
            } else {
                $warnings[] = $message;
            }
        }

        if ((bool)($config['requireToken'] ?? true) && empty((array)($config['credentials'] ?? []))) {
            $errors[] = 'Token auth is required but no credentials are configured.';
        }

        $status = 'ok';
        if ($errors !== []) {
            $status = 'critical';
        } elseif ($warnings !== []) {
            $status = 'warn';
        }

        return [
            'status' => $status,
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
            'details' => [
                'requireToken' => (bool)($config['requireToken'] ?? true),
                'allowQueryToken' => (bool)($config['allowQueryToken'] ?? false),
                'credentialCount' => count((array)($config['credentials'] ?? [])),
                'managedCredentialCount' => (int)($config['managedCredentialCount'] ?? 0),
                'envCredentialCount' => (int)($config['envCredentialCount'] ?? 0),
            ],
        ];
    }

    private function queueEmailNotification(
        string $eventType,
        string $fingerprint,
        string $subject,
        string $bodyText,
        array $payload = [],
        int $dedupeWindowSeconds = 3600,
        ?array $recipientOverrides = null,
    ): int {
        $config = $this->getRuntimeConfig();
        if (!(bool)($config['enabled'] ?? false)) {
            return 0;
        }

        $eventToggles = (array)($config['eventToggles'] ?? []);
        if (!((bool)($eventToggles[$eventType] ?? false))) {
            return 0;
        }

        $recipients = $this->normalizeResolvedRecipients($recipientOverrides);
        if ($recipients === []) {
            $recipients = (array)($config['recipients'] ?? []);
        }
        if ($recipients === [] || !$this->tableExists(self::TABLE_LOG)) {
            return 0;
        }

        $queued = 0;
        foreach ($recipients as $recipient) {
            $normalizedRecipient = trim((string)$recipient);
            if ($normalizedRecipient === '') {
                continue;
            }

            if ($this->hasRecentNotification($eventType, $fingerprint, $normalizedRecipient, $dedupeWindowSeconds)) {
                continue;
            }

            $now = gmdate('Y-m-d H:i:s');
            Craft::$app->getDb()->createCommand()->insert(self::TABLE_LOG, [
                'eventType' => $eventType,
                'fingerprint' => $fingerprint,
                'channel' => self::CHANNEL_EMAIL,
                'recipient' => $normalizedRecipient,
                'subject' => $this->truncate($subject, 255),
                'bodyText' => $bodyText,
                'payload' => $this->encodeJson($payload),
                'status' => self::STATUS_QUEUED,
                'attempts' => 0,
                'lastAttemptAt' => null,
                'sentAt' => null,
                'errorMessage' => null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $notificationId = (int)Craft::$app->getDb()->getLastInsertID();
            try {
                Queue::push(new SendOperatorNotificationJob([
                    'notificationId' => $notificationId,
                ]));
            } catch (\Throwable $e) {
                $this->markNotificationFailed($notificationId, 'Unable to queue notification: ' . $e->getMessage(), 0, null);
                continue;
            }
            $queued++;
        }

        return $queued;
    }

    private function resolveManagedCredentialRecipientEmails(string $actorId): array
    {
        $normalizedActorId = trim($actorId);
        if ($normalizedActorId === '' || str_starts_with($normalizedActorId, 'cp:') || $normalizedActorId === 'cp-user') {
            return [];
        }

        $targets = Plugin::getInstance()
            ->getCredentialService()
            ->getNotificationTargetsForHandle($normalizedActorId);

        $emails = [];
        foreach ((array)($targets['approvalRecipients'] ?? []) as $recipient) {
            if (!is_array($recipient)) {
                continue;
            }

            $email = strtolower(trim((string)($recipient['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $emails[$email] = true;
        }

        return array_keys($emails);
    }

    private function normalizeResolvedRecipients(?array $recipients): array
    {
        if (!is_array($recipients)) {
            return [];
        }

        $normalized = [];
        foreach ($recipients as $recipient) {
            $email = strtolower(trim((string)$recipient));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $normalized[$email] = true;
        }

        return array_keys($normalized);
    }

    private function getAccountApprovalRecipients(): array
    {
        try {
            $posture = Plugin::getInstance()->getSecurityPolicyService()->getCpPosture();
            $defaultScopes = (array)($posture['authentication']['tokenScopes'] ?? []);
            return Plugin::getInstance()->getCredentialService()->getApprovalRecipientEmailsForRuntime($defaultScopes);
        } catch (\Throwable $e) {
            Craft::warning('Unable to resolve account-level approval recipients for CP snapshot: ' . $e->getMessage(), __METHOD__);
            return [];
        }
    }

    private function hasRecentNotification(string $eventType, string $fingerprint, string $recipient, int $windowSeconds): bool
    {
        if ($windowSeconds < 1 || !$this->tableExists(self::TABLE_LOG)) {
            return false;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - $windowSeconds);

        $row = (new Query())
            ->from(self::TABLE_LOG)
            ->where([
                'eventType' => $eventType,
                'fingerprint' => $fingerprint,
                'recipient' => $recipient,
                'channel' => self::CHANNEL_EMAIL,
            ])
            ->andWhere(['in', 'status', [self::STATUS_QUEUED, self::STATUS_SENT]])
            ->andWhere(['>=', 'dateCreated', $cutoff])
            ->one();

        return is_array($row);
    }

    private function getMonitorState(string $monitorKey): array
    {
        if (!$this->tableExists(self::TABLE_MONITORS)) {
            return [
                'status' => 'unknown',
                'payload' => [],
            ];
        }

        $row = (new Query())
            ->from(self::TABLE_MONITORS)
            ->where(['monitorKey' => $monitorKey])
            ->one();

        if (!is_array($row)) {
            return [
                'status' => 'unknown',
                'payload' => [],
            ];
        }

        return [
            'status' => strtolower(trim((string)($row['status'] ?? 'unknown'))),
            'payload' => $this->decodeJsonArray((string)($row['payload'] ?? '{}')),
        ];
    }

    private function upsertMonitorState(string $monitorKey, string $status, array $payload): void
    {
        if (!$this->tableExists(self::TABLE_MONITORS)) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $existing = (new Query())
            ->from(self::TABLE_MONITORS)
            ->where(['monitorKey' => $monitorKey])
            ->one();

        if (is_array($existing)) {
            Craft::$app->getDb()->createCommand()->update(self::TABLE_MONITORS, [
                'status' => $this->truncate($status, 16),
                'payload' => $this->encodeJson($payload),
                'dateUpdated' => $now,
            ], ['id' => (int)($existing['id'] ?? 0)])->execute();
            return;
        }

        Craft::$app->getDb()->createCommand()->insert(self::TABLE_MONITORS, [
            'monitorKey' => $this->truncate($monitorKey, 64),
            'status' => $this->truncate($status, 16),
            'payload' => $this->encodeJson($payload),
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function findNotificationLogById(int $notificationId): ?array
    {
        if ($notificationId <= 0 || !$this->tableExists(self::TABLE_LOG)) {
            return null;
        }

        $row = (new Query())
            ->from(self::TABLE_LOG)
            ->where(['id' => $notificationId])
            ->one();

        return is_array($row) ? $row : null;
    }

    private function markNotificationFailed(int $notificationId, string $message, ?int $attempts = null, ?string $lastAttemptAt = null): void
    {
        if ($notificationId <= 0 || !$this->tableExists(self::TABLE_LOG)) {
            return;
        }

        $now = $lastAttemptAt ?: gmdate('Y-m-d H:i:s');
        $update = [
            'status' => self::STATUS_FAILED,
            'errorMessage' => $this->truncate($message, 65535),
            'dateUpdated' => $now,
        ];
        if ($attempts !== null) {
            $update['attempts'] = max(0, $attempts);
        }
        if ($lastAttemptAt !== null) {
            $update['lastAttemptAt'] = $lastAttemptAt;
        }

        Craft::$app->getDb()->createCommand()->update(self::TABLE_LOG, $update, ['id' => $notificationId])->execute();
    }

    private function parseRecipientList(string $raw): array
    {
        $value = trim($raw);
        if ($value === '') {
            return [];
        }

        $parsed = App::parseEnv($value);
        if (is_array($parsed)) {
            $items = $parsed;
        } else {
            $stringValue = trim((string)$parsed);
            if ($stringValue === '') {
                return [];
            }

            $decoded = json_decode($stringValue, true);
            if (is_array($decoded)) {
                $items = $decoded;
            } else {
                $items = preg_split('/[;,\n]+/', $stringValue) ?: [];
            }
        }

        $emails = [];
        foreach ($items as $item) {
            $email = strtolower(trim((string)$item));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $emails[$email] = true;
        }

        return array_keys($emails);
    }

    private function tableExists(string $table): bool
    {
        return Craft::$app->getDb()->tableExists($table);
    }

    private function encodeJson(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '{}';
    }

    private function decodeJsonArray(string $raw): array
    {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function composeLines(array $lines): string
    {
        $filtered = [];
        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }
            $filtered[] = rtrim($line);
        }

        return trim(implode("\n", $filtered)) . "\n";
    }

    private function lineIfPresent(string $label, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            $rendered = $value->format(DATE_ATOM);
        } else {
            $rendered = trim((string)$value);
        }

        if ($rendered === '') {
            return null;
        }

        return sprintf('%s: %s', $label, $rendered);
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, max(0, $limit - 1));
    }

    private function maxStatus(string $left, string $right): string
    {
        return $this->statusRank($left) >= $this->statusRank($right) ? $left : $right;
    }

    private function statusRank(string $status): int
    {
        return match (strtolower(trim($status))) {
            'critical' => 3,
            'warn' => 2,
            default => 1,
        };
    }

    private function hydrateNotificationLog(array $row): array
    {
        $eventType = (string)($row['eventType'] ?? '');

        return [
            'id' => (int)($row['id'] ?? 0),
            'eventType' => $eventType,
            'eventLabel' => $this->eventLabel($eventType),
            'fingerprint' => (string)($row['fingerprint'] ?? ''),
            'channel' => (string)($row['channel'] ?? self::CHANNEL_EMAIL),
            'recipient' => (string)($row['recipient'] ?? ''),
            'subject' => (string)($row['subject'] ?? ''),
            'status' => (string)($row['status'] ?? self::STATUS_FAILED),
            'attempts' => (int)($row['attempts'] ?? 0),
            'lastAttemptAt' => $this->normalizeDateTimeString($row['lastAttemptAt'] ?? null),
            'sentAt' => $this->normalizeDateTimeString($row['sentAt'] ?? null),
            'errorMessage' => (string)($row['errorMessage'] ?? ''),
            'payload' => $this->decodeJsonArray((string)($row['payload'] ?? '{}')),
            'dateCreated' => $this->normalizeDateTimeString($row['dateCreated'] ?? null),
            'dateUpdated' => $this->normalizeDateTimeString($row['dateUpdated'] ?? null),
        ];
    }

    private function enrichRecentNotifications(array $rows, array $recipientUsersByEmail): array
    {
        $enriched = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $recipientEmail = strtolower(trim((string)($row['recipient'] ?? '')));
            $recipientUser = $recipientEmail !== '' ? ($recipientUsersByEmail[$recipientEmail] ?? null) : null;
            $channel = strtolower(trim((string)($row['channel'] ?? self::CHANNEL_EMAIL)));
            $channelLabel = $channel !== '' ? ucwords(str_replace(['_', '-'], ' ', $channel)) : 'Email';

            $row['recipientLabel'] = is_array($recipientUser)
                ? (string)($recipientUser['label'] ?? $recipientEmail)
                : (string)($row['recipient'] ?? '');
            $row['recipientCpEditUrl'] = is_array($recipientUser)
                ? ($recipientUser['cpEditUrl'] ?? null)
                : null;
            $row['recipientChannelLabel'] = $channelLabel;

            $enriched[] = $row;
        }

        return $enriched;
    }

    private function resolveRecipientUsersByEmail(array $emails): array
    {
        $normalizedEmails = [];
        foreach ($emails as $email) {
            $normalizedEmail = strtolower(trim((string)$email));
            if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $normalizedEmails[$normalizedEmail] = true;
        }

        if ($normalizedEmails === []) {
            return [];
        }

        $users = User::find()
            ->email(array_keys($normalizedEmails))
            ->status(null)
            ->all();

        $byEmail = [];
        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $email = strtolower(trim((string)$user->email));
            if ($email === '') {
                continue;
            }

            $label = trim((string)$user->friendlyName);
            if ($label === '') {
                $label = trim((string)$user->username);
            }
            if ($label === '') {
                $label = $email;
            }

            $cpEditUrl = trim((string)$user->getCpEditUrl());
            $byEmail[$email] = [
                'id' => (int)$user->id,
                'label' => $label,
                'email' => $email,
                'cpEditUrl' => $cpEditUrl !== '' ? $cpEditUrl : null,
            ];
        }

        return $byEmail;
    }

    private function normalizeDateTimeString(mixed $value): ?string
    {
        $string = trim((string)$value);
        return $string === '' ? null : $string;
    }

    private function eventLabel(string $eventType): string
    {
        return match (strtolower(trim($eventType))) {
            self::EVENT_APPROVAL_REQUESTED => 'Approval requested',
            self::EVENT_APPROVAL_DECIDED => 'Approval decided',
            self::EVENT_EXECUTION_ISSUE => 'Execution issue',
            self::EVENT_WEBHOOK_DLQ_FAILED => 'Webhook DLQ failure',
            self::EVENT_STATUS_CHANGED => 'Status changed',
            default => $eventType !== '' ? str_replace('_', ' ', $eventType) : 'Notification',
        };
    }
}
