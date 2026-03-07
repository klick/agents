<?php

namespace Klick\Agents\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $enabled = true;
    public bool $allowCpApprovalRequests = false;
    public bool $enableCredentialUsageIndicator = true;
    public bool $enableLlmsTxt = true;
    public bool $enableLlmsFullTxt = false;
    public bool $enableCommerceTxt = true;
    public int $llmsTxtCacheTtl = 86400;
    public int $commerceTxtCacheTtl = 3600;
    public string $llmsTxtBody = '';
    public string $llmsSiteSummary = '';
    public bool $llmsIncludeAgentsLinks = true;
    public bool $llmsIncludeSitemapLink = true;
    public array $llmsLinks = [];
    public string $commerceTxtBody = '';
    public string $commerceSummary = '';
    public array $commercePolicyUrls = [];
    public array $commerceSupport = [];
    public array $commerceAttributes = [];
    public string $commerceCatalogUrl = '/agents/v1/products?status=live&limit=200';
    public int|string $reliabilityConsumerLagWarnSeconds = 300;
    public int|string $reliabilityConsumerLagCriticalSeconds = 900;

    public function rules(): array
    {
        return [
            [['enabled', 'allowCpApprovalRequests', 'enableCredentialUsageIndicator', 'enableLlmsTxt', 'enableLlmsFullTxt', 'enableCommerceTxt', 'llmsIncludeAgentsLinks', 'llmsIncludeSitemapLink'], 'boolean'],
            [['llmsTxtCacheTtl', 'commerceTxtCacheTtl'], 'integer', 'min' => 0, 'max' => 604800],
            [['reliabilityConsumerLagWarnSeconds', 'reliabilityConsumerLagCriticalSeconds'], 'safe'],
            [['llmsTxtBody', 'llmsSiteSummary', 'commerceTxtBody', 'commerceSummary', 'commerceCatalogUrl'], 'string'],
            [['llmsLinks', 'commercePolicyUrls', 'commerceSupport', 'commerceAttributes'], 'safe'],
        ];
    }
}
