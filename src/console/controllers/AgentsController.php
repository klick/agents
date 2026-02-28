<?php

namespace Klick\Agents\console\controllers;

use Klick\Agents\Plugin;
use craft\console\Controller;
use craft\helpers\Json;
use yii\console\Exception;
use yii\console\ExitCode;

class AgentsController extends Controller
{
    public string $status = '';
    public string $search = '';
    public int $limit = 50;
    public string $sort = 'updatedAt';
    public bool $json = false;
    public bool $lowStock = false;
    public int $lowStockThreshold = 10;
    public int $lastDays = 30;
    public int $resourceId = 0;
    public string $number = '';
    public string $slug = '';
    public string $section = '';
    public string $type = '';
    public string $target = 'all';
    public bool $strict = false;

    public function init(): void
    {
        parent::init();
        $this->silentExitOnException = false;
    }

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'product-list' => array_merge($options, ['status', 'search', 'limit', 'sort', 'lowStock', 'lowStockThreshold', 'json']),
            'order-list' => array_merge($options, ['status', 'lastDays', 'limit', 'json']),
            'order-show' => array_merge($options, ['resourceId', 'number', 'json']),
            'entry-list' => array_merge($options, ['section', 'type', 'status', 'search', 'limit', 'json']),
            'entry-show' => array_merge($options, ['resourceId', 'slug', 'section', 'json']),
            'section-list' => array_merge($options, ['json']),
            'discovery-prewarm' => array_merge($options, ['target', 'json']),
            'auth-check', 'discovery-check', 'readiness-check', 'smoke' => array_merge($options, ['json', 'strict']),
            default => $options,
        };
    }

    public function optionAliases(): array
    {
        return [
            'q' => 'search',
            'j' => 'json',
            'i' => 'resourceId',
        ];
    }

    public function actionProductList(): int
    {
        $lowStock = $this->lowStock || $this->readFlagOption('low-stock');
        $lowStockThreshold = $this->readIntOption('low-stock-threshold') ?? $this->lowStockThreshold;

        $payload = Plugin::getInstance()->getReadinessService()->getProductsList([
            'status' => $this->readStringOption('status') ?? ($this->status !== '' ? $this->status : 'live'),
            'search' => $this->search,
            'limit' => $this->limit,
            'sort' => $this->sort,
            'lowStock' => $lowStock,
            'lowStockThreshold' => $lowStockThreshold,
        ]);

        if ($errorCode = $this->writeErrorPayload($payload)) {
            return $errorCode;
        }

        if ($this->json) {
            return $this->emitJson($payload);
        }

        $data = $payload['data'] ?? [];
        $this->stdout(sprintf("Products: %d\n", count($data)));
        foreach ($data as $product) {
            $stockLabel = ($product['hasUnlimitedStock'] ?? false) ? 'unlimited' : (string)($product['totalStock'] ?? 0);
            $this->stdout(sprintf(
                "- #%d %s [%s] status=%s stock=%s updated=%s\n",
                (int)($product['id'] ?? 0),
                (string)($product['title'] ?? ''),
                (string)($product['slug'] ?? ''),
                (string)($product['status'] ?? 'unknown'),
                $stockLabel,
                (string)($product['updatedAt'] ?? '')
            ));
        }

        return ExitCode::OK;
    }

    public function actionOrderList(): int
    {
        $lastDays = $this->readIntOption('last-days') ?? $this->lastDays;
        $status = $this->readStringOption('status') ?? ($this->status !== '' ? $this->status : 'all');

        $payload = Plugin::getInstance()->getReadinessService()->getOrdersList([
            'status' => $status,
            'lastDays' => $lastDays,
            'limit' => $this->limit,
        ]);

        if ($errorCode = $this->writeErrorPayload($payload)) {
            return $errorCode;
        }

        if ($this->json) {
            return $this->emitJson($payload);
        }

        $data = $payload['data'] ?? [];
        $this->stdout(sprintf("Orders: %d\n", count($data)));
        foreach ($data as $order) {
            $this->stdout(sprintf(
                "- #%d %s status=%s total=%0.2f created=%s\n",
                (int)($order['id'] ?? 0),
                (string)($order['number'] ?? ''),
                (string)($order['status'] ?? 'unknown'),
                (float)($order['totalPrice'] ?? 0),
                (string)($order['dateCreated'] ?? '')
            ));
        }

        return ExitCode::OK;
    }

    public function actionOrderShow(): int
    {
        $payload = Plugin::getInstance()->getReadinessService()->getOrderByIdOrNumber([
            'id' => $this->resourceId,
            'number' => $this->number,
        ]);

        if ($errorCode = $this->writeErrorPayload($payload)) {
            return $errorCode;
        }

        $order = $payload['data'] ?? null;
        if (!$order) {
            $this->stderr("Order not found.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->json) {
            return $this->emitJson($payload);
        }

        $this->stdout(sprintf("Order %s (#%d)\n", (string)$order['number'], (int)$order['id']));
        $this->stdout(sprintf("Status: %s\n", (string)($order['status'] ?? 'unknown')));
        $this->stdout(sprintf("Email: %s\n", (string)($order['email'] ?? '')));
        $this->stdout(sprintf("Total: %0.2f\n", (float)($order['totalPrice'] ?? 0)));
        $this->stdout(sprintf("Created: %s\n", (string)($order['dateCreated'] ?? '')));
        $lineItems = $order['lineItems'] ?? [];
        $this->stdout(sprintf("Line items: %d\n", count($lineItems)));
        foreach ($lineItems as $lineItem) {
            $this->stdout(sprintf(
                "  - %s sku=%s qty=%s total=%0.2f\n",
                (string)($lineItem['description'] ?? ''),
                (string)($lineItem['sku'] ?? ''),
                (string)($lineItem['qty'] ?? '0'),
                (float)($lineItem['total'] ?? 0)
            ));
        }

        return ExitCode::OK;
    }

    public function actionEntryList(): int
    {
        $payload = Plugin::getInstance()->getReadinessService()->getEntriesList([
            'section' => $this->section,
            'type' => $this->type,
            'status' => $this->readStringOption('status') ?? ($this->status !== '' ? $this->status : 'live'),
            'search' => $this->search,
            'limit' => $this->limit,
        ]);

        if ($errorCode = $this->writeErrorPayload($payload)) {
            return $errorCode;
        }

        if ($this->json) {
            return $this->emitJson($payload);
        }

        $data = $payload['data'] ?? [];
        $this->stdout(sprintf("Entries: %d\n", count($data)));
        foreach ($data as $entry) {
            $this->stdout(sprintf(
                "- #%d %s [%s] section=%s type=%s status=%s\n",
                (int)($entry['id'] ?? 0),
                (string)($entry['title'] ?? ''),
                (string)($entry['slug'] ?? ''),
                (string)($entry['section'] ?? ''),
                (string)($entry['type'] ?? ''),
                (string)($entry['status'] ?? 'unknown')
            ));
        }

        return ExitCode::OK;
    }

    public function actionEntryShow(): int
    {
        $payload = Plugin::getInstance()->getReadinessService()->getEntryByIdOrSlug([
            'id' => $this->resourceId,
            'slug' => $this->slug,
            'section' => $this->section,
        ]);

        if ($errorCode = $this->writeErrorPayload($payload)) {
            return $errorCode;
        }

        $entry = $payload['data'] ?? null;
        if (!$entry) {
            $this->stderr("Entry not found.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->json) {
            return $this->emitJson($payload);
        }

        $this->stdout(sprintf("Entry %s (#%d)\n", (string)$entry['title'], (int)$entry['id']));
        $this->stdout(sprintf("Slug: %s\n", (string)($entry['slug'] ?? '')));
        $this->stdout(sprintf("Section: %s\n", (string)($entry['section'] ?? '')));
        $this->stdout(sprintf("Type: %s\n", (string)($entry['type'] ?? '')));
        $this->stdout(sprintf("Status: %s\n", (string)($entry['status'] ?? '')));
        $this->stdout(sprintf("Updated: %s\n", (string)($entry['updatedAt'] ?? '')));
        if (!empty($entry['url'])) {
            $this->stdout(sprintf("URL: %s\n", (string)$entry['url']));
        }

        return ExitCode::OK;
    }

    public function actionSectionList(): int
    {
        $payload = Plugin::getInstance()->getReadinessService()->getSectionsList();
        if ($errorCode = $this->writeErrorPayload($payload)) {
            return $errorCode;
        }

        if ($this->json) {
            return $this->emitJson($payload);
        }

        $sections = $payload['data'] ?? [];
        $this->stdout(sprintf("Sections: %d\n", count($sections)));
        foreach ($sections as $section) {
            $this->stdout(sprintf(
                "- #%d %s (%s) type=%s\n",
                (int)($section['id'] ?? 0),
                (string)($section['handle'] ?? ''),
                (string)($section['name'] ?? ''),
                (string)($section['type'] ?? '')
            ));
        }

        return ExitCode::OK;
    }

    public function actionDiscoveryPrewarm(): int
    {
        $payload = Plugin::getInstance()->getDiscoveryTxtService()->prewarm($this->target);

        if ($this->json) {
            return $this->emitJson($payload);
        }

        $this->stdout(sprintf("Target: %s\n", (string)($payload['target'] ?? 'all')));
        $this->stdout(sprintf("Generated: %s\n", (string)($payload['generatedAt'] ?? '')));
        foreach (($payload['documents'] ?? []) as $name => $document) {
            $this->stdout(sprintf(
                "- %s enabled=%s bytes=%d etag=%s lastModified=%s\n",
                (string)$name,
                !empty($document['enabled']) ? 'true' : 'false',
                (int)($document['bytes'] ?? 0),
                (string)($document['etag'] ?? ''),
                (string)($document['lastModified'] ?? '')
            ));
        }

        return ExitCode::OK;
    }

    public function actionAuthCheck(): int
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

        $details = [
            'environment' => (string)($config['environment'] ?? ''),
            'requireToken' => (bool)($config['requireToken'] ?? true),
            'allowQueryToken' => (bool)($config['allowQueryToken'] ?? false),
            'credentialCount' => count((array)($config['credentials'] ?? [])),
            'managedCredentialCount' => (int)($config['managedCredentialCount'] ?? 0),
            'envCredentialCount' => (int)($config['envCredentialCount'] ?? 0),
            'tokenScopes' => array_values((array)($config['tokenScopes'] ?? [])),
            'rateLimitPerMinute' => (int)($config['rateLimitPerMinute'] ?? 0),
            'rateLimitWindowSeconds' => (int)($config['rateLimitWindowSeconds'] ?? 0),
        ];

        return $this->emitCheckResult('auth-check', $details, $errors, $warnings);
    }

    public function actionDiscoveryCheck(): int
    {
        $service = Plugin::getInstance()->getDiscoveryTxtService();
        // Force a fresh render so checks validate current template output, not stale cache.
        $service->prewarm('all');
        $status = $service->getDiscoveryStatus();
        $settings = Plugin::getInstance()->getSettings();

        $errors = [];
        $warnings = [];
        $documents = (array)($status['documents'] ?? []);

        foreach ($documents as $key => $document) {
            $name = (string)($document['name'] ?? $key);
            $enabled = (bool)($document['enabled'] ?? false);
            $bytes = (int)($document['bytes'] ?? 0);
            $url = trim((string)($document['url'] ?? ''));
            if (!$enabled) {
                $warnings[] = sprintf('%s is disabled.', $name);
                continue;
            }

            if ($bytes <= 0) {
                $errors[] = sprintf('%s is enabled but empty.', $name);
            }
            if ($url === '') {
                $errors[] = sprintf('%s is enabled but URL is missing.', $name);
            }
        }

        if ((bool)($settings->enableLlmsTxt ?? false) && (bool)($settings->llmsIncludeAgentsLinks ?? false)) {
            $llmsDocument = $service->getLlmsTxtDocument();
            $llmsBody = (string)($llmsDocument['body'] ?? '');
            if ($llmsBody === '') {
                $errors[] = 'llms.txt body is missing while llms.txt is enabled.';
            } else {
                foreach ([
                    '/agents/v1/capabilities',
                    '/agents/v1/openapi.json',
                    '/agents/v1/auth/whoami',
                ] as $requiredPath) {
                    if (!str_contains($llmsBody, $requiredPath)) {
                        $errors[] = sprintf('llms.txt is missing expected discovery link: %s', $requiredPath);
                    }
                }
            }
        }

        return $this->emitCheckResult('discovery-check', $status, $errors, $warnings);
    }

    public function actionReadinessCheck(): int
    {
        $service = Plugin::getInstance()->getReadinessService();
        $diagnostics = $service->getReadinessDiagnostics();
        $health = $service->getHealthSummary();

        $errors = [];
        $warnings = [];

        foreach ((array)($health['systemComponents'] ?? []) as $component => $available) {
            if (!(bool)$available) {
                $errors[] = sprintf('System component unavailable: %s', (string)$component);
            }
        }

        if (!((bool)($health['enabledPlugins']['commerce'] ?? false))) {
            $warnings[] = 'Commerce plugin is unavailable; commerce-dependent endpoints will be limited.';
        }

        foreach ((array)($diagnostics['warnings'] ?? []) as $warning) {
            $message = (string)$warning;
            // CLI checks do not run in a web request context, so this warning is expected.
            if (str_contains($message, 'Web request context available')) {
                continue;
            }
            $warnings[] = $message;
        }

        return $this->emitCheckResult('readiness-check', $diagnostics, $errors, $warnings);
    }

    public function actionSmoke(): int
    {
        $readinessService = Plugin::getInstance()->getReadinessService();
        $discoveryService = Plugin::getInstance()->getDiscoveryTxtService();
        $commerceEnabled = Plugin::getInstance()->isCommercePluginEnabled();

        $errors = [];
        $warnings = [];

        $sections = $readinessService->getSectionsList();
        $entries = $readinessService->getEntriesList(['limit' => 1, 'status' => 'live']);
        $discoveryStatus = $discoveryService->getDiscoveryStatus();
        $readiness = $readinessService->getReadinessDiagnostics();

        $sectionErrors = (array)($sections['meta']['errors'] ?? []);
        $entryErrors = (array)($entries['meta']['errors'] ?? []);
        foreach ($sectionErrors as $error) {
            $errors[] = 'sections: ' . (string)$error;
        }
        foreach ($entryErrors as $error) {
            $errors[] = 'entries: ' . (string)$error;
        }

        if ($commerceEnabled) {
            $products = $readinessService->getProductsList(['limit' => 1, 'status' => 'all']);
            $orders = $readinessService->getOrdersList(['limit' => 1, 'status' => 'all', 'lastDays' => 30]);
            foreach ((array)($products['meta']['errors'] ?? []) as $error) {
                $errors[] = 'products: ' . (string)$error;
            }
            foreach ((array)($orders['meta']['errors'] ?? []) as $error) {
                $errors[] = 'orders: ' . (string)$error;
            }
        } else {
            $warnings[] = 'Commerce plugin unavailable; products/orders smoke checks skipped.';
        }

        foreach ((array)($readiness['warnings'] ?? []) as $warning) {
            $message = (string)$warning;
            if (str_contains($message, 'Web request context available')) {
                continue;
            }
            $warnings[] = 'readiness: ' . $message;
        }

        $summary = [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => [
                'sections' => [
                    'count' => (int)($sections['meta']['count'] ?? 0),
                    'errors' => $sectionErrors,
                ],
                'entries' => [
                    'count' => (int)($entries['meta']['count'] ?? 0),
                    'errors' => $entryErrors,
                ],
                'discovery' => $discoveryStatus,
                'readiness' => [
                    'status' => (string)($readiness['status'] ?? 'unknown'),
                    'readinessScore' => (int)($readiness['readinessScore'] ?? 0),
                ],
                'commerceChecksEnabled' => $commerceEnabled,
            ],
        ];

        return $this->emitCheckResult('smoke', $summary, $errors, $warnings);
    }

    private function emitJson(array $payload): int
    {
        $this->stdout(Json::encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return ExitCode::OK;
    }

    private function emitCheckResult(string $check, array $details, array $errors, array $warnings): int
    {
        $errors = array_values(array_unique(array_filter(array_map('strval', $errors), static fn(string $message): bool => trim($message) !== '')));
        $warnings = array_values(array_unique(array_filter(array_map('strval', $warnings), static fn(string $message): bool => trim($message) !== '')));
        sort($errors);
        sort($warnings);

        $ok = count($errors) === 0 && (!$this->strict || count($warnings) === 0);
        $payload = [
            'check' => $check,
            'status' => $ok ? 'ok' : 'failed',
            'strict' => (bool)$this->strict,
            'errors' => $errors,
            'warnings' => $warnings,
            'details' => $details,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($this->json) {
            $this->emitJson($payload);
            return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf("Check: %s\n", $check));
        $this->stdout(sprintf("Status: %s\n", $payload['status']));
        if ($this->strict) {
            $this->stdout("Mode: strict\n");
        }

        if (!empty($errors)) {
            $this->stderr("Errors:\n");
            foreach ($errors as $message) {
                $this->stderr(sprintf("- %s\n", $message));
            }
        }

        if (!empty($warnings)) {
            $this->stdout("Warnings:\n");
            foreach ($warnings as $message) {
                $this->stdout(sprintf("- %s\n", $message));
            }
        }

        if (empty($errors) && empty($warnings)) {
            $this->stdout("No issues detected.\n");
        }

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    private function writeErrorPayload(array $payload): int
    {
        $errors = $payload['meta']['errors'] ?? [];
        if (!$errors) {
            return 0;
        }

        foreach ($errors as $error) {
            $this->stderr((string)$error . "\n");
        }

        if ($this->json) {
            $this->emitJson($payload);
        }

        throw new Exception((string)($errors[0] ?? 'Command validation failed.'));
    }

    private function readFlagOption(string $optionName): bool
    {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if ($arg === '--' . $optionName || $arg === '--' . $optionName . '=1' || $arg === '--' . $optionName . '=true') {
                return true;
            }
        }

        return false;
    }

    private function readIntOption(string $optionName): ?int
    {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            $prefix = '--' . $optionName . '=';
            if (str_starts_with($arg, $prefix)) {
                $value = substr($arg, strlen($prefix));
                if (is_numeric($value)) {
                    return (int)$value;
                }
            }
        }

        return null;
    }

    private function readStringOption(string $optionName): ?string
    {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            $prefix = '--' . $optionName . '=';
            if (str_starts_with($arg, $prefix)) {
                return trim((string)substr($arg, strlen($prefix)));
            }
        }

        return null;
    }
}
