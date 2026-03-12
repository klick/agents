<?php

namespace Klick\Agents\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $enabled = true;
    public bool $enableWritesExperimental = false;
    public bool $allowCpApprovalRequests = false;
    public bool $enableCredentialUsageIndicator = true;
    public string $webhookUrl = '$PLUGIN_AGENTS_WEBHOOK_URL';
    public string $webhookSecret = '$PLUGIN_AGENTS_WEBHOOK_SECRET';
    public int|string $reliabilityConsumerLagWarnSeconds = 300;
    public int|string $reliabilityConsumerLagCriticalSeconds = 900;

    public function rules(): array
    {
        return [
            [['enabled', 'enableWritesExperimental', 'allowCpApprovalRequests', 'enableCredentialUsageIndicator'], 'boolean'],
            [['reliabilityConsumerLagWarnSeconds', 'reliabilityConsumerLagCriticalSeconds'], 'safe'],
            [['webhookUrl', 'webhookSecret'], 'string'],
        ];
    }
}
