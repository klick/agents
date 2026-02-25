<?php

namespace Klick\Agents\services;

use Craft;
use Klick\Agents\models\Settings;
use Klick\Agents\Plugin;
use craft\base\Component;
use craft\helpers\UrlHelper;

class DiscoveryTxtService extends Component
{
    private const CACHE_KEY_LLMS = 'agents:discovery:llms:v1';
    private const CACHE_KEY_COMMERCE = 'agents:discovery:commerce:v1';

    public function getLlmsTxtDocument(): ?array
    {
        $settings = $this->getSettings();
        if (!$settings->enableLlmsTxt) {
            return null;
        }

        $ttl = $this->normalizeTtl($settings->llmsTxtCacheTtl, 86400);
        return $this->getCachedDocument(self::CACHE_KEY_LLMS, $ttl, function() use ($settings): string {
            return $this->renderLlmsTxt($settings);
        });
    }

    public function getCommerceTxtDocument(): ?array
    {
        $settings = $this->getSettings();
        if (!$settings->enableCommerceTxt) {
            return null;
        }

        $ttl = $this->normalizeTtl($settings->commerceTxtCacheTtl, 3600);
        return $this->getCachedDocument(self::CACHE_KEY_COMMERCE, $ttl, function() use ($settings): string {
            return $this->renderCommerceTxt($settings);
        });
    }

    public function invalidateAllCaches(): void
    {
        $cache = Craft::$app->getCache();
        $cache->delete(self::CACHE_KEY_LLMS);
        $cache->delete(self::CACHE_KEY_COMMERCE);
    }

    public function prewarm(string $target = 'all'): array
    {
        $target = strtolower(trim($target));
        if (!in_array($target, ['all', 'llms', 'commerce'], true)) {
            $target = 'all';
        }

        $documents = [];
        if ($target === 'all' || $target === 'llms') {
            Craft::$app->getCache()->delete(self::CACHE_KEY_LLMS);
            $documents['llms'] = $this->describeDocument($this->getLlmsTxtDocument());
        }

        if ($target === 'all' || $target === 'commerce') {
            Craft::$app->getCache()->delete(self::CACHE_KEY_COMMERCE);
            $documents['commerce'] = $this->describeDocument($this->getCommerceTxtDocument());
        }

        return [
            'target' => $target,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'documents' => $documents,
        ];
    }

    public function getDiscoveryStatus(): array
    {
        $settings = $this->getSettings();
        $llmsTtl = $this->normalizeTtl($settings->llmsTxtCacheTtl, 86400);
        $commerceTtl = $this->normalizeTtl($settings->commerceTxtCacheTtl, 3600);

        $llmsDocument = $settings->enableLlmsTxt ? $this->getLlmsTxtDocument() : null;
        $commerceDocument = $settings->enableCommerceTxt ? $this->getCommerceTxtDocument() : null;

        return [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'documents' => [
                'llms' => $this->buildDocumentStatus('llms.txt', '/llms.txt', $settings->enableLlmsTxt, $llmsTtl, $llmsDocument),
                'commerce' => $this->buildDocumentStatus('commerce.txt', '/commerce.txt', $settings->enableCommerceTxt, $commerceTtl, $commerceDocument),
            ],
        ];
    }

    private function describeDocument(?array $document): array
    {
        if ($document === null) {
            return [
                'enabled' => false,
                'etag' => null,
                'lastModified' => null,
                'bytes' => 0,
            ];
        }

        return [
            'enabled' => true,
            'etag' => (string)($document['etag'] ?? ''),
            'lastModified' => gmdate('Y-m-d\TH:i:s\Z', (int)($document['lastModified'] ?? time())),
            'bytes' => strlen((string)($document['body'] ?? '')),
        ];
    }

    private function buildDocumentStatus(
        string $name,
        string $path,
        bool $enabled,
        int $cacheTtlSeconds,
        ?array $document
    ): array {
        $body = (string)($document['body'] ?? '');
        $lastModified = null;
        if ($document !== null) {
            $lastModified = gmdate('Y-m-d\TH:i:s\Z', (int)($document['lastModified'] ?? time()));
        }

        return [
            'name' => $name,
            'path' => $path,
            'url' => $this->toAbsoluteUrl($path),
            'enabled' => $enabled,
            'cacheTtlSeconds' => $cacheTtlSeconds,
            'etag' => $document !== null ? (string)($document['etag'] ?? '') : null,
            'lastModified' => $lastModified,
            'bytes' => strlen($body),
            'lineCount' => $this->lineCount($body),
            'preview' => $this->buildPreview($body),
        ];
    }

    private function buildPreview(string $body, int $maxLines = 14, int $maxChars = 1800): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $lines = preg_split('/\R/', $body) ?: [];
        $previewLines = array_slice($lines, 0, max(1, $maxLines));
        $preview = implode("\n", $previewLines);
        if (strlen($preview) > $maxChars) {
            $preview = substr($preview, 0, $maxChars);
        }

        if (count($lines) > $maxLines || strlen($body) > strlen($preview)) {
            $preview = rtrim($preview) . "\n...";
        }

        return $preview;
    }

    private function lineCount(string $body): int
    {
        $body = trim($body);
        if ($body === '') {
            return 0;
        }

        return substr_count($body, "\n") + 1;
    }

    private function getCachedDocument(string $cacheKey, int $ttl, callable $renderer): array
    {
        $cache = Craft::$app->getCache();
        $cached = $cache->get($cacheKey);
        if (is_array($cached) && isset($cached['body'], $cached['etag'], $cached['lastModified'])) {
            $cached['maxAge'] = $ttl;
            return $cached;
        }

        $body = trim((string)call_user_func($renderer));
        if ($body === '') {
            $body = '# Empty';
        }
        $body .= "\n";

        $payload = [
            'body' => $body,
            'etag' => sha1($body),
            'lastModified' => time(),
            'maxAge' => $ttl,
        ];

        $cache->set($cacheKey, $payload, $ttl);
        return $payload;
    }

    private function renderLlmsTxt(Settings $settings): string
    {
        $siteName = $this->getSiteName();
        $siteUrl = $this->getSiteUrl();
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $summary = trim($settings->llmsSiteSummary);
        if ($summary === '') {
            $summary = 'Machine-readable discovery overview for assistants and indexing tools.';
        }

        $lines = [
            '# ' . $siteName,
            '> llms.txt proposal surface generated by the Agents plugin.',
            '',
            'site: ' . $siteUrl,
            'last_generated: ' . $generatedAt,
            'summary: ' . $summary,
            '',
            '## Links',
            '- Home: ' . $siteUrl,
        ];

        if ($settings->llmsIncludeSitemapLink) {
            $lines[] = '- Sitemap: ' . $this->toAbsoluteUrl('/sitemap.xml');
        }

        if ($settings->llmsIncludeAgentsLinks) {
            $lines[] = '- OpenAPI: ' . $this->toAbsoluteUrl('/agents/v1/openapi.json');
            $lines[] = '- Capabilities: ' . $this->toAbsoluteUrl('/agents/v1/capabilities');
            $lines[] = '- Products API: ' . $this->toAbsoluteUrl('/agents/v1/products?status=live&limit=50');
            $lines[] = '- Entries API: ' . $this->toAbsoluteUrl('/agents/v1/entries?status=live&limit=50');
        }

        foreach ($this->normalizeLinks($settings->llmsLinks, 'Link') as $link) {
            $lines[] = sprintf('- %s: %s', $link['label'], $link['url']);
        }

        return implode("\n", $lines);
    }

    private function renderCommerceTxt(Settings $settings): string
    {
        $siteName = $this->getSiteName();
        $siteUrl = $this->getSiteUrl();
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $commerceEnabled = (bool)Craft::$app->getPlugins()->getPlugin('commerce');
        $summary = trim($settings->commerceSummary);
        if ($summary === '') {
            $summary = 'Machine-readable commerce metadata and policy pointers.';
        }

        $lines = [
            '# commerce.txt',
            '# Proposal-oriented file generated by the Agents plugin.',
            'version: 1.0',
            'store_name: ' . $siteName,
            'store_url: ' . $siteUrl,
            'generated_at: ' . $generatedAt,
            'summary: ' . $summary,
            'commerce_plugin_enabled: ' . ($commerceEnabled ? 'true' : 'false'),
            'catalog_url: ' . $this->toAbsoluteUrl($settings->commerceCatalogUrl),
            'capabilities_url: ' . $this->toAbsoluteUrl('/agents/v1/capabilities'),
        ];

        $policyUrls = $this->normalizeKeyValueMap($settings->commercePolicyUrls);
        foreach ($policyUrls as $key => $value) {
            $lines[] = sprintf('policy_%s: %s', $key, $this->toAbsoluteUrl($value));
        }

        $support = $this->normalizeKeyValueMap($settings->commerceSupport);
        foreach ($support as $key => $value) {
            if (in_array($key, ['email', 'phone'], true)) {
                $lines[] = sprintf('support_%s: %s', $key, $value);
                continue;
            }

            $lines[] = sprintf('support_%s: %s', $key, $this->toAbsoluteUrl($value));
        }

        $attributes = $this->normalizeKeyValueMap($settings->commerceAttributes);
        foreach ($attributes as $key => $value) {
            $lines[] = sprintf('attribute_%s: %s', $key, $value);
        }

        return implode("\n", $lines);
    }

    private function normalizeLinks(array $links, string $defaultLabelPrefix): array
    {
        $normalized = [];
        $index = 1;

        foreach ($links as $key => $value) {
            $label = '';
            $url = '';
            if (is_array($value)) {
                $label = trim((string)($value['label'] ?? (is_string($key) ? $key : '')));
                $url = trim((string)($value['url'] ?? ''));
            } else {
                $label = is_string($key) ? trim($key) : '';
                $url = trim((string)$value);
            }

            if ($url === '') {
                continue;
            }

            if ($label === '') {
                $label = $defaultLabelPrefix . ' ' . $index;
            }

            $normalized[] = [
                'label' => $label,
                'url' => $this->toAbsoluteUrl($url),
            ];
            $index++;
        }

        usort($normalized, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
        return $normalized;
    }

    private function normalizeKeyValueMap(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $stringKey = strtolower(trim((string)$key));
            $stringKey = preg_replace('/[^a-z0-9_]+/', '_', $stringKey) ?? '';
            $stringKey = trim($stringKey, '_');
            if ($stringKey === '') {
                continue;
            }

            $stringValue = trim((string)$value);
            if ($stringValue === '') {
                continue;
            }

            $normalized[$stringKey] = $stringValue;
        }

        ksort($normalized);
        return $normalized;
    }

    private function toAbsoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $this->getSiteUrl();
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        $path = str_starts_with($url, '/') ? $url : '/' . $url;
        return rtrim($this->getSiteUrl(), '/') . $path;
    }

    private function normalizeTtl(int $ttl, int $default): int
    {
        if ($ttl <= 0) {
            return $default;
        }

        return min($ttl, 604800);
    }

    private function getSettings(): Settings
    {
        $settings = Plugin::getInstance()->getSettings();
        return $settings instanceof Settings ? $settings : new Settings();
    }

    private function getSiteName(): string
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $name = trim((string)($site?->name ?? Craft::$app->getSystemName()));
        return $name !== '' ? $name : 'Website';
    }

    private function getSiteUrl(): string
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $baseUrl = trim((string)($site?->getBaseUrl() ?? ''));
        if ($baseUrl !== '') {
            return rtrim(Craft::parseEnv($baseUrl), '/');
        }

        $url = trim((string)UrlHelper::siteUrl('/'));
        if ($url !== '') {
            return rtrim($url, '/');
        }

        return 'https://example.com';
    }
}
