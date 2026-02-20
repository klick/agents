<?php

namespace agentreadiness\console\controllers;

use agentreadiness\Plugin;
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

    private function emitJson(array $payload): int
    {
        $this->stdout(Json::encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return ExitCode::OK;
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
