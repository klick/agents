<?php

namespace Klick\Agents\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $enabled = true;
    public bool $enableWritesExperimental = false;
    public bool $allowCpApprovalRequests = false;
    public bool $enableCredentialUsageIndicator = true;
    public bool $notificationsEnabled = false;
    public string $notificationRecipients = '$PLUGIN_AGENTS_NOTIFICATION_RECIPIENTS';
    public bool $notificationApprovalRequested = true;
    public bool $notificationApprovalDecided = true;
    public bool $notificationExecutionFailed = true;
    public bool $notificationWebhookDlqFailed = true;
    public bool $notificationStatusChanged = true;
    public string $webhookUrl = '$PLUGIN_AGENTS_WEBHOOK_URL';
    public string $webhookSecret = '$PLUGIN_AGENTS_WEBHOOK_SECRET';
    public int|string $reliabilityConsumerLagWarnSeconds = 300;
    public int|string $reliabilityConsumerLagCriticalSeconds = 900;

    public function rules(): array
    {
        return [
            [[
                'enabled',
                'enableWritesExperimental',
                'allowCpApprovalRequests',
                'enableCredentialUsageIndicator',
                'notificationsEnabled',
                'notificationApprovalRequested',
                'notificationApprovalDecided',
                'notificationExecutionFailed',
                'notificationWebhookDlqFailed',
                'notificationStatusChanged',
            ], 'boolean'],
            [['reliabilityConsumerLagWarnSeconds', 'reliabilityConsumerLagCriticalSeconds'], 'safe'],
            [['webhookUrl', 'webhookSecret', 'notificationRecipients'], 'string'],
        ];
    }
}
