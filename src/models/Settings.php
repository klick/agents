<?php

namespace agentreadiness\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $enableLlmsTxt = true;
    public bool $enableCommerceTxt = true;
    public int $llmsTxtCacheTtl = 86400;
    public int $commerceTxtCacheTtl = 3600;
    public string $llmsSiteSummary = '';
    public bool $llmsIncludeAgentsLinks = true;
    public bool $llmsIncludeSitemapLink = true;
    public array $llmsLinks = [];
    public string $commerceSummary = '';
    public array $commercePolicyUrls = [];
    public array $commerceSupport = [];
    public array $commerceAttributes = [];
    public string $commerceCatalogUrl = '/agents/v1/products?status=live&limit=200';

    public function rules(): array
    {
        return [
            [['enableLlmsTxt', 'enableCommerceTxt', 'llmsIncludeAgentsLinks', 'llmsIncludeSitemapLink'], 'boolean'],
            [['llmsTxtCacheTtl', 'commerceTxtCacheTtl'], 'integer', 'min' => 0, 'max' => 604800],
            [['llmsSiteSummary', 'commerceSummary', 'commerceCatalogUrl'], 'string'],
            [['llmsLinks', 'commercePolicyUrls', 'commerceSupport', 'commerceAttributes'], 'safe'],
        ];
    }
}
