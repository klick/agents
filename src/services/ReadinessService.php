<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\commerce\elements\Product;
use craft\elements\Entry;
use DateTimeImmutable;
use DateTimeInterface;

class ReadinessService extends Component
{
    public function getHealthSummary(): array
    {
        return [
            'status' => 'ok',
            'service' => 'agents',
            'pluginVersion' => '0.1.1',
            'environment' => App::env('ENVIRONMENT') ?: App::env('CRAFT_ENVIRONMENT'),
            'timezone' => date_default_timezone_get(),
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'enabledPlugins' => [
                'commerce' => (bool)Craft::$app->getPlugins()->getPlugin('commerce'),
            ],
            'systemComponents' => [
                'db' => (bool)Craft::$app->getDb(),
                'cache' => (bool)Craft::$app->getCache(),
                'users' => (bool)Craft::$app->getUsers(),
            ],
        ];
    }

    public function getReadinessSummary(): array
    {
        $health = $this->getHealthSummary();
        $health['readinessScore'] = $this->calculateReadinessScore();

        return $health;
    }

    public function getDashboardModel(): array
    {
        return [
            'readinessVersion' => '0.1.1',
            'buildDate' => gmdate('Y-m-d\TH:i:s\Z'),
            'status' => $this->calculateReadinessScore() >= 50 ? 'ready' : 'limited',
            'summary' => $this->getReadinessSummary(),
        ];
    }

    public function getProductsSnapshot(array $params): array
    {
        $status = $this->normalizeStatus((string)($params['status'] ?? 'live'));
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $cursor = (string)($params['cursor'] ?? '');
        $query = trim((string)($params['q'] ?? ''));
        $offset = $this->parseCursor($cursor);
        $sort = $this->normalizeSort((string)($params['sort'] ?? 'updatedAt'));

        if (!class_exists(Product::class)) {
            return [
                'data' => [],
                'page' => [
                    'nextCursor' => null,
                    'limit' => $limit,
                    'count' => 0,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        $queryBuilder = Product::find()
            ->orderBy($this->buildSort($sort))
            ->offset($offset)
            ->limit($limit + 1);

        if ($status !== 'all') {
            $queryBuilder->status($status);
        }

        if ($query !== '') {
            $queryBuilder->search($query);
        }

        $products = $queryBuilder->all();
        $totalReturned = min(count($products), $limit);
        $nextOffset = $offset + $totalReturned;

        $data = [];
        foreach (array_slice($products, 0, $limit) as $product) {
            $productType = $product->type;
            $url = UrlHelper::siteUrl('products/' . $product->uri);

            $data[] = [
                'id' => (int)$product->id,
                'title' => $product->title,
                'slug' => $product->slug,
                'uri' => $product->uri,
                'type' => $productType?->handle ?? null,
                'status' => $product->getStatus() ?? null,
                'updatedAt' => $product->dateUpdated?->format('Y-m-d\\TH:i:s\\Z'),
                'url' => $url,
            ];
        }

        return [
            'data' => $data,
            'page' => [
                'nextCursor' => count($products) > $limit ? $this->buildCursor($nextOffset) : null,
                'limit' => $limit,
                'count' => $totalReturned,
            ],
        ];
    }

    public function getProductsList(array $params): array
    {
        $status = $this->normalizeStatus((string)($params['status'] ?? 'live'));
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $sort = $this->normalizeSort((string)($params['sort'] ?? 'updatedAt'));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $lowStock = (bool)($params['lowStock'] ?? false);
        $lowStockThreshold = $this->normalizeLowStockThreshold((int)($params['lowStockThreshold'] ?? 10));

        if (!class_exists(Product::class)) {
            return [
                'data' => [],
                'meta' => [
                    'count' => 0,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        $queryBuilder = Product::find()->orderBy($this->buildSort($sort));
        if ($status === 'all') {
            $queryBuilder->status(null);
        } else {
            $queryBuilder->status($status);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        $data = [];
        if ($lowStock) {
            $queryBuilder->limit(null);
            foreach ($queryBuilder->each() as $product) {
                if (!$product instanceof Product) {
                    continue;
                }
                $stock = $this->extractProductStock($product);
                $isLowStock = !$stock['hasUnlimitedStock'] && $stock['totalStock'] <= $lowStockThreshold;
                if (!$isLowStock) {
                    continue;
                }

                $data[] = $this->mapProductForCli($product, $stock['totalStock'], $stock['hasUnlimitedStock']);
                if (count($data) >= $limit) {
                    break;
                }
            }
        } else {
            $queryBuilder->limit($limit);
            foreach ($queryBuilder->all() as $product) {
                if (!$product instanceof Product) {
                    continue;
                }
                $stock = $this->extractProductStock($product);
                $data[] = $this->mapProductForCli($product, $stock['totalStock'], $stock['hasUnlimitedStock']);
            }
        }

        return [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => [
                    'status' => $status,
                    'search' => $search !== '' ? $search : null,
                    'sort' => $sort,
                    'lowStock' => $lowStock,
                    'lowStockThreshold' => $lowStockThreshold,
                    'limit' => $limit,
                ],
                'errors' => [],
            ],
        ];
    }

    public function getOrdersList(array $params): array
    {
        $status = $this->normalizeOrderStatus((string)($params['status'] ?? 'all'));
        $lastDays = $this->normalizeLastDays((int)($params['lastDays'] ?? 30));
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $includeSensitive = (bool)($params['includeSensitive'] ?? true);
        $redactEmail = (bool)($params['redactEmail'] ?? false);

        if (!class_exists(Order::class)) {
            return [
                'data' => [],
                'meta' => [
                    'count' => 0,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        $queryBuilder = Order::find()
            ->isCompleted(true)
            ->orderBy(['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC])
            ->limit($limit);

        if ($status !== 'all') {
            $queryBuilder->orderStatus($status);
        }

        if ($lastDays > 0) {
            $cutoff = (new DateTimeImmutable(sprintf('-%d days', $lastDays)))->format('Y-m-d H:i:s');
            $queryBuilder->dateCreated('>= ' . $cutoff);
        }

        $data = [];
        foreach ($queryBuilder->all() as $order) {
            if (!$order instanceof Order) {
                continue;
            }
            $data[] = $this->mapOrder($order, false, $includeSensitive, $redactEmail);
        }

        return [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => [
                    'status' => $status,
                    'lastDays' => $lastDays,
                    'limit' => $limit,
                    'includeSensitive' => $includeSensitive,
                    'redactEmail' => $redactEmail,
                ],
                'errors' => [],
            ],
        ];
    }

    public function getOrderByIdOrNumber(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $number = trim((string)($params['number'] ?? ''));
        $includeSensitive = (bool)($params['includeSensitive'] ?? true);
        $redactEmail = (bool)($params['redactEmail'] ?? false);
        $hasId = $id > 0;
        $hasNumber = $number !== '';

        if (($hasId ? 1 : 0) + ($hasNumber ? 1 : 0) !== 1) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Provide exactly one identifier: --id or --number.'],
                ],
            ];
        }

        if (!class_exists(Order::class)) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        $queryBuilder = Order::find()->isCompleted(true)->status(null)->limit(1);
        if ($hasId) {
            $queryBuilder->id($id);
        } else {
            $queryBuilder->number($number);
        }

        $order = $queryBuilder->one();
        if (!$order instanceof Order) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapOrder($order, true, $includeSensitive, $redactEmail),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getEntriesList(array $params): array
    {
        $status = $this->normalizeStatus((string)($params['status'] ?? 'live'));
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? ''));
        $section = trim((string)($params['section'] ?? ''));
        $type = trim((string)($params['type'] ?? ''));

        $queryBuilder = Entry::find()
            ->orderBy(['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC])
            ->limit($limit);

        if ($status === 'all') {
            $queryBuilder->status(null);
        } else {
            $queryBuilder->status($status);
        }
        if ($section !== '') {
            $queryBuilder->section($section);
        }
        if ($type !== '') {
            $queryBuilder->type($type);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        $data = [];
        foreach ($queryBuilder->all() as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }
            $data[] = $this->mapEntry($entry, false);
        }

        return [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => [
                    'status' => $status,
                    'search' => $search !== '' ? $search : null,
                    'section' => $section !== '' ? $section : null,
                    'type' => $type !== '' ? $type : null,
                    'limit' => $limit,
                ],
                'errors' => [],
            ],
        ];
    }

    public function getEntryByIdOrSlug(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $slug = trim((string)($params['slug'] ?? ''));
        $section = trim((string)($params['section'] ?? ''));
        $includeAllStatuses = (bool)($params['includeAllStatuses'] ?? false);
        $hasId = $id > 0;
        $hasSlug = $slug !== '';

        if (($hasId ? 1 : 0) + ($hasSlug ? 1 : 0) !== 1) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Provide exactly one identifier: --id or --slug.'],
                ],
            ];
        }

        $queryBuilder = Entry::find()->limit(1);
        if ($includeAllStatuses) {
            $queryBuilder->status(null);
        } else {
            $queryBuilder->status('live');
        }
        if ($hasId) {
            $queryBuilder->id($id);
        } else {
            $queryBuilder->slug($slug);
            if ($section !== '') {
                $queryBuilder->section($section);
            }
        }

        $entry = $queryBuilder->one();
        if (!$entry instanceof Entry) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapEntry($entry, true),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getSectionsList(): array
    {
        $sections = Craft::$app->getEntries()->getAllSections();
        $data = [];

        foreach ($sections as $section) {
            $data[] = [
                'id' => (int)$section->id,
                'name' => (string)$section->name,
                'handle' => (string)$section->handle,
                'type' => (string)$section->type,
            ];
        }

        usort($data, static fn(array $a, array $b): int => strcmp($a['handle'], $b['handle']));

        return [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'errors' => [],
            ],
        ];
    }

    private function calculateReadinessScore(): int
    {
        $score = 0;
        $score += (bool)Craft::$app->getRequest()->getIsSiteRequest() ? 20 : 0;
        $score += (bool)Craft::$app->getDb() ? 40 : 0;
        $score += (bool)Craft::$app->getPlugins()->getPlugin('commerce') ? 40 : 0;

        return $score;
    }

    private function normalizeLimit(int $limit): int
    {
        if ($limit < 1) {
            return 1;
        }

        return min($limit, 200);
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'live', 'pending', 'disabled', 'expired' => $status,
            'all', 'any' => 'all',
            default => 'live',
        };
    }

    private function normalizeSort(string $sort): string
    {
        $sort = strtolower(trim($sort));
        return match ($sort) {
            'title' => 'title',
            'createdat' => 'createdAt',
            default => 'updatedAt',
        };
    }

    private function normalizeOrderStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '' || $status === 'all' || $status === 'any') {
            return 'all';
        }

        $normalized = preg_replace('/[^a-z0-9_-]/', '', $status) ?: '';
        return $normalized !== '' ? $normalized : 'all';
    }

    private function normalizeLowStockThreshold(int $threshold): int
    {
        if ($threshold < 0) {
            return 0;
        }

        return min($threshold, 1000000);
    }

    private function normalizeLastDays(int $lastDays): int
    {
        if ($lastDays < 0) {
            return 0;
        }

        return min($lastDays, 3650);
    }

    private function buildSort(string $sort): array
    {
        return match ($sort) {
            'title' => ['elements_sites.title' => SORT_ASC],
            'createdAt' => ['elements.dateCreated' => SORT_ASC, 'elements.id' => SORT_ASC],
            default => ['elements.dateUpdated' => SORT_ASC, 'elements.id' => SORT_ASC],
        };
    }

    private function parseCursor(string $cursor): int
    {
        if ($cursor === '') {
            return 0;
        }

        $decoded = base64_decode($cursor, true);
        if (!$decoded) {
            return 0;
        }

        $payload = json_decode($decoded, true);
        $offset = (int)($payload['offset'] ?? 0);
        return max(0, $offset);
    }

    private function buildCursor(int $offset): string
    {
        return base64_encode(json_encode(['offset' => $offset]));
    }

    private function mapProductForCli(Product $product, int $totalStock, bool $hasUnlimitedStock): array
    {
        $typeHandle = $product->type?->handle ?? null;
        $url = $product->getUrl();
        if ($url === null && $product->uri) {
            $url = UrlHelper::siteUrl($product->uri);
        }

        return [
            'id' => (int)$product->id,
            'title' => (string)$product->title,
            'slug' => (string)$product->slug,
            'uri' => (string)$product->uri,
            'type' => $typeHandle,
            'status' => $product->getStatus() ?? null,
            'updatedAt' => $this->formatDate($product->dateUpdated),
            'url' => $url,
            'hasUnlimitedStock' => $hasUnlimitedStock,
            'totalStock' => $hasUnlimitedStock ? null : $totalStock,
        ];
    }

    private function extractProductStock(Product $product): array
    {
        $totalStock = 0;
        $hasUnlimitedStock = false;

        foreach ($product->getVariants() as $variant) {
            $variantHasUnlimitedStock = (bool)($variant->hasUnlimitedStock ?? false);
            if ($variantHasUnlimitedStock) {
                $hasUnlimitedStock = true;
                continue;
            }

            if ($variant->stock !== null) {
                $totalStock += (int)$variant->stock;
            }
        }

        return [
            'totalStock' => $totalStock,
            'hasUnlimitedStock' => $hasUnlimitedStock,
        ];
    }

    private function mapOrder(Order $order, bool $detailed, bool $includeSensitive, bool $redactEmail): array
    {
        $orderStatus = $order->getOrderStatus();
        $email = $order->email ? (string)$order->email : null;
        if ($redactEmail) {
            $email = null;
        }

        $data = [
            'id' => (int)$order->id,
            'number' => (string)($order->number ?? ''),
            'reference' => (string)($order->reference ?? ''),
            'email' => $email,
            'emailRedacted' => $redactEmail,
            'status' => $orderStatus?->handle,
            'statusName' => $orderStatus?->name,
            'isCompleted' => (bool)$order->isCompleted,
            'isPaid' => (bool)$order->isPaid,
            'itemSubtotal' => $order->itemSubtotal === null ? null : (float)$order->itemSubtotal,
            'itemTotal' => $order->itemTotal === null ? null : (float)$order->itemTotal,
            'totalTax' => $order->totalTax === null ? null : (float)$order->totalTax,
            'totalShippingCost' => $order->totalShippingCost === null ? null : (float)$order->totalShippingCost,
            'totalPrice' => $order->totalPrice === null ? null : (float)$order->totalPrice,
            'dateCreated' => $this->formatDate($order->dateCreated),
            'dateUpdated' => $this->formatDate($order->dateUpdated),
        ];

        if (!$detailed) {
            return $data;
        }

        $data['lineItems'] = [];
        foreach ($order->getLineItems() as $lineItem) {
            $lineItemData = [
                'id' => (int)$lineItem->id,
                'description' => (string)$lineItem->description,
                'sku' => (string)$lineItem->sku,
                'qty' => (float)$lineItem->qty,
            ];

            if ($includeSensitive) {
                $lineItemData['salePrice'] = $lineItem->salePrice === null ? null : (float)$lineItem->salePrice;
                $lineItemData['subtotal'] = $lineItem->subtotal === null ? null : (float)$lineItem->subtotal;
                $lineItemData['total'] = $lineItem->total === null ? null : (float)$lineItem->total;
            }

            $data['lineItems'][] = $lineItemData;
        }

        return $data;
    }

    private function mapEntry(Entry $entry, bool $detailed): array
    {
        $section = $entry->getSection();
        $type = $entry->getType();

        $data = [
            'id' => (int)$entry->id,
            'title' => (string)$entry->title,
            'slug' => (string)$entry->slug,
            'uri' => (string)$entry->uri,
            'status' => $entry->getStatus() ?? null,
            'section' => $section?->handle,
            'type' => $type?->handle,
            'updatedAt' => $this->formatDate($entry->dateUpdated),
            'url' => $entry->getUrl(),
        ];

        if (!$detailed) {
            return $data;
        }

        $data['postDate'] = $this->formatDate($entry->postDate);
        $data['expiryDate'] = $this->formatDate($entry->expiryDate);
        $data['dateCreated'] = $this->formatDate($entry->dateCreated);
        $data['dateUpdated'] = $this->formatDate($entry->dateUpdated);
        $data['authorId'] = $entry->authorId ? (int)$entry->authorId : null;
        $data['enabled'] = (bool)$entry->enabled;

        return $data;
    }

    private function formatDate(?DateTimeInterface $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
