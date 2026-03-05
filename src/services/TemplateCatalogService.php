<?php

namespace Klick\Agents\services;

use craft\base\Component;
use Klick\Agents\Plugin;

class TemplateCatalogService extends Component
{
    public function getCatalog(string $basePath = '/agents/v1'): array
    {
        $normalizedBasePath = $this->normalizeBasePath($basePath);
        $templates = $this->buildTemplates($normalizedBasePath);

        return [
            'service' => 'agents',
            'version' => $this->resolvePluginVersion(),
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'basePath' => $normalizedBasePath,
            'contracts' => [
                'openapi' => $normalizedBasePath . '/openapi.json',
                'schema' => $normalizedBasePath . '/schema',
            ],
            'count' => count($templates),
            'templates' => $templates,
        ];
    }

    public function getTemplateById(string $templateId, string $basePath = '/agents/v1'): ?array
    {
        $normalizedId = strtolower(trim($templateId));
        if ($normalizedId === '') {
            return null;
        }

        $catalog = $this->getCatalog($basePath);
        foreach ((array)($catalog['templates'] ?? []) as $template) {
            if (strtolower((string)($template['id'] ?? '')) === $normalizedId) {
                return $template;
            }
        }

        return null;
    }

    private function buildTemplates(string $basePath): array
    {
        return [
            [
                'id' => 'catalog-sync-loop',
                'displayName' => 'Catalog + Content Sync Loop',
                'intent' => 'Sync products and entries, then continue from unified changes feed with checkpoints.',
                'requiredScopes' => [
                    'auth:read',
                    'products:read',
                    'entries:read',
                    'changes:read',
                    'consumers:write',
                ],
                'endpointSequence' => [
                    $this->step('GET', '/auth/whoami', 'auth.whoami'),
                    $this->step('GET', '/products', 'products.list'),
                    $this->step('GET', '/entries', 'entries.list'),
                    $this->step('GET', '/changes', 'changes.feed'),
                    $this->step('POST', '/consumers/checkpoint', 'consumers.checkpoint'),
                ],
                'sampleCommands' => [
                    'curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/auth/whoami"',
                    'curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/products?status=live&limit=100"',
                    'curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/entries?status=live&limit=100"',
                    'curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/changes?types=products,entries&limit=100"',
                    'curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" "$BASE_URL/consumers/checkpoint" -d @docs/reference-automations/fixtures/catalog-sync-checkpoint.json',
                ],
            ],
            [
                'id' => 'support-context-lookup',
                'displayName' => 'Support Context Lookup',
                'intent' => 'Fetch actionable order + content context for support conversations.',
                'requiredScopes' => [
                    'auth:read',
                    'orders:read',
                    'entries:read',
                ],
                'optionalScopes' => [
                    'entries:read_all_statuses',
                    'orders:read_sensitive',
                ],
                'endpointSequence' => [
                    $this->step('GET', '/auth/whoami', 'auth.whoami'),
                    $this->step('GET', '/orders', 'orders.list'),
                    $this->step('GET', '/orders/show', 'orders.show'),
                    $this->step('GET', '/entries', 'entries.list'),
                ],
                'sampleCommands' => [
                    'curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/orders?status=all&lastDays=14&limit=20"',
                    'curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/orders/show?number=A1B2C3D4"',
                    'curl -sS -H "Authorization: Bearer $AGENTS_TOKEN" "$BASE_URL/entries?section=support&status=live&limit=20"',
                ],
            ],
            [
                'id' => 'governed-return-approval-run',
                'displayName' => 'Governed Return Approval Run',
                'intent' => 'Execute return/refund workflows through policy + approval + idempotent execution.',
                'requiresExperimental' => true,
                'requiredScopes' => [
                    'control:approvals:request',
                    'control:approvals:read',
                    'control:approvals:decide',
                    'control:actions:execute',
                    'control:executions:read',
                ],
                'endpointSequence' => [
                    $this->step('POST', '/control/approvals/request', 'control.approvals.request'),
                    $this->step('GET', '/control/approvals', 'control.approvals.list'),
                    $this->step('POST', '/control/approvals/decide', 'control.approvals.decide'),
                    $this->step('POST', '/control/actions/execute', 'control.actions.execute'),
                    $this->step('GET', '/control/executions', 'control.executions.list'),
                ],
                'sampleCommands' => [
                    'curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" "$BASE_URL/control/approvals/request" -d @docs/reference-automations/fixtures/return-approval-request.json',
                    'curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" "$BASE_URL/control/approvals/decide" -d @docs/reference-automations/fixtures/return-approval-decide.json',
                    'curl -sS -X POST -H "Authorization: Bearer $AGENTS_TOKEN" -H "Content-Type: application/json" -H "X-Idempotency-Key: return-ret-100045-v1" "$BASE_URL/control/actions/execute" -d @docs/reference-automations/fixtures/return-action-execute.json',
                ],
            ],
        ];
    }

    private function step(string $method, string $path, string $schemaEndpoint): array
    {
        return [
            'method' => $method,
            'path' => $path,
            'schemaEndpoint' => $schemaEndpoint,
            'schemaRef' => '/agents/v1/schema?version=v1&endpoint=' . rawurlencode($schemaEndpoint),
            'openapiRef' => '/agents/v1/openapi.json',
        ];
    }

    private function normalizeBasePath(string $basePath): string
    {
        $normalized = trim($basePath);
        if ($normalized === '') {
            return '/agents/v1';
        }

        if (!str_starts_with($normalized, '/')) {
            $normalized = '/' . $normalized;
        }

        return rtrim($normalized, '/');
    }

    private function resolvePluginVersion(): string
    {
        $plugin = Plugin::getInstance();
        if ($plugin !== null) {
            $version = trim((string)$plugin->getVersion());
            if ($version !== '') {
                return $version;
            }

            $schemaVersion = trim((string)$plugin->schemaVersion);
            if ($schemaVersion !== '') {
                return $schemaVersion;
            }
        }

        return '0.8.7';
    }
}
