<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Donation;
use craft\commerce\elements\Order;
use craft\commerce\elements\Subscription;
use craft\commerce\elements\Transfer;
use craft\commerce\elements\Variant;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\commerce\elements\Product;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\ContentBlock;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use DateTimeImmutable;
use DateTimeInterface;
use Klick\Agents\Plugin;

class ReadinessService extends Component
{
    private const INCREMENTAL_CURSOR_VERSION = 1;
    private const INCREMENTAL_CURSOR_TTL_SECONDS = 604800;
    private const READINESS_READY_THRESHOLD = 50;

    public function getHealthSummary(): array
    {
        $plugin = Plugin::getInstance();
        $securityConfig = [];
        if ($plugin !== null) {
            try {
                $securityConfig = $plugin->getSecurityPolicyService()->getRuntimeConfig();
            } catch (\Throwable $e) {
                Craft::warning('Unable to load runtime security config for health summary: ' . $e->getMessage(), __METHOD__);
            }
        }

        $environment = (string)($securityConfig['environment'] ?? (App::env('ENVIRONMENT') ?: App::env('CRAFT_ENVIRONMENT') ?: ''));

        return [
            'status' => 'ok',
            'service' => 'agents',
            'pluginVersion' => $this->resolvePluginVersion(),
            'environment' => $environment,
            'environmentProfile' => (string)($securityConfig['environmentProfile'] ?? ''),
            'environmentProfileSource' => (string)($securityConfig['environmentProfileSource'] ?? ''),
            'profileDefaultsApplied' => (bool)($securityConfig['profileDefaultsApplied'] ?? false),
            'effectivePolicyVersion' => (string)($securityConfig['effectivePolicyVersion'] ?? ''),
            'timezone' => date_default_timezone_get(),
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'enabledPlugins' => [
                'commerce' => $plugin?->isCommercePluginEnabled() ?? false,
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

    public function getReadinessBreakdown(): array
    {
        $request = Craft::$app->getRequest();
        $hasWebRequestContext = (bool)($request->getIsSiteRequest() || $request->getIsCpRequest());
        $databaseAvailable = (bool)Craft::$app->getDb();
        $commerceEnabled = Plugin::getInstance()?->isCommercePluginEnabled() ?? false;

        return [
            [
                'id' => 'web-request',
                'label' => 'Web request context available',
                'weight' => 20,
                'passed' => $hasWebRequestContext,
                'score' => $hasWebRequestContext ? 20 : 0,
            ],
            [
                'id' => 'database',
                'label' => 'Database connection available',
                'weight' => 40,
                'passed' => $databaseAvailable,
                'score' => $databaseAvailable ? 40 : 0,
            ],
            [
                'id' => 'commerce',
                'label' => 'Commerce plugin available',
                'weight' => 40,
                'passed' => $commerceEnabled,
                'score' => $commerceEnabled ? 40 : 0,
                'optional' => true,
            ],
        ];
    }

    public function getReadinessDiagnostics(): array
    {
        $health = $this->getHealthSummary();
        $breakdown = $this->getReadinessBreakdown();
        $score = 0;
        foreach ($breakdown as $criterion) {
            $score += (int)($criterion['score'] ?? 0);
        }

        $status = $score >= self::READINESS_READY_THRESHOLD ? 'ready' : 'limited';
        $warnings = [];
        foreach ($breakdown as $criterion) {
            if ((bool)($criterion['passed'] ?? false)) {
                continue;
            }
            if ((bool)($criterion['optional'] ?? false)) {
                continue;
            }
            $warnings[] = sprintf('%s check is failing.', (string)($criterion['label'] ?? 'Readiness'));
        }

        return [
            'status' => $status,
            'readinessScore' => $score,
            'thresholdReady' => self::READINESS_READY_THRESHOLD,
            'breakdown' => $breakdown,
            'componentChecks' => [
                'systemComponents' => (array)($health['systemComponents'] ?? []),
                'enabledPlugins' => (array)($health['enabledPlugins'] ?? []),
            ],
            'warnings' => $warnings,
            'summary' => array_merge($health, [
                'readinessScore' => $score,
            ]),
        ];
    }

    public function getDashboardModel(): array
    {
        $score = $this->calculateReadinessScore();
        return [
            'readinessVersion' => $this->resolvePluginVersion(),
            'buildDate' => gmdate('Y-m-d\TH:i:s\Z'),
            'status' => $score >= self::READINESS_READY_THRESHOLD ? 'ready' : 'limited',
            'summary' => $this->getReadinessSummary(),
        ];
    }

    public function getProductsSnapshot(array $params): array
    {
        $status = $this->normalizeStatus((string)($params['status'] ?? 'live'));
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $cursor = trim((string)($params['cursor'] ?? ''));
        $query = trim((string)($params['q'] ?? ''));
        $sort = $this->normalizeSort((string)($params['sort'] ?? 'updatedAt'));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $lowStock = (bool)($params['lowStock'] ?? false);
        $lowStockThreshold = $this->normalizeLowStockThreshold((int)($params['lowStockThreshold'] ?? 10));

        $decodedCursor = $this->decodeCursorPayload($cursor);
        $hasIncrementalCursor = is_array($decodedCursor) && (($decodedCursor['mode'] ?? '') === 'incremental');
        $isIncremental = ($updatedSinceRaw !== '' || $hasIncrementalCursor);
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'products');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'page' => [
                            'nextCursor' => null,
                            'limit' => $limit,
                            'count' => 0,
                            'hasMore' => false,
                            'syncMode' => 'incremental',
                            'updatedSince' => $updatedSinceRaw !== '' ? $updatedSinceRaw : null,
                            'snapshotEnd' => null,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'page' => [
                            'nextCursor' => null,
                            'limit' => $limit,
                            'count' => 0,
                            'hasMore' => false,
                            'syncMode' => 'incremental',
                            'updatedSince' => $updatedSinceRaw !== '' ? $updatedSinceRaw : null,
                            'snapshotEnd' => null,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }
        } else {
            $offset = $this->parseCursor($cursor);
        }

        if ($isIncremental && $lowStock) {
            return [
                'data' => [],
                'page' => [
                    'nextCursor' => null,
                    'limit' => $limit,
                    'count' => 0,
                    'hasMore' => false,
                    'syncMode' => 'incremental',
                    'updatedSince' => $isIncremental ? $this->formatDate($updatedSince) : null,
                    'snapshotEnd' => $isIncremental ? $this->formatDate($snapshotEnd) : null,
                    'errors' => ['`lowStock` is not supported when using incremental sync (`cursor`/`updatedSince`).'],
                ],
            ];
        }

        if (!class_exists(Product::class)) {
            return [
                'data' => [],
                'page' => [
                    'nextCursor' => null,
                    'limit' => $limit,
                    'count' => 0,
                    'hasMore' => false,
                    'syncMode' => $isIncremental ? 'incremental' : 'full',
                    'updatedSince' => $isIncremental ? $this->formatDate($updatedSince) : null,
                    'snapshotEnd' => $isIncremental ? $this->formatDate($snapshotEnd) : null,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        $queryBuilder = Product::find();

        if ($status !== 'all') {
            $queryBuilder->status($status);
        }

        if ($query !== '') {
            $queryBuilder->search($query);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $queryBuilder->orderBy($this->buildIncrementalSort());
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } elseif ($lowStock) {
            $queryBuilder->orderBy($this->buildSort($sort));
            $queryBuilder->limit(null);
        } else {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $queryBuilder->orderBy($this->buildSort($sort));
        }

        $data = [];
        $totalReturned = 0;
        $nextOffset = $offset;
        $hasMore = false;

        if ($lowStock) {
            $lowStockMatched = 0;
            foreach ($queryBuilder->each() as $product) {
                if (!$product instanceof Product) {
                    continue;
                }

                $stock = $this->extractProductStock($product);
                $isLowStock = !$stock['hasUnlimitedStock'] && $stock['totalStock'] <= $lowStockThreshold;
                if (!$isLowStock) {
                    continue;
                }

                if ($lowStockMatched < $offset) {
                    $lowStockMatched++;
                    continue;
                }

                $data[] = $this->mapProductSnapshot($product, $stock['totalStock'], $stock['hasUnlimitedStock']);
                if (count($data) > $limit) {
                    break;
                }
            }

            $totalReturned = min(count($data), $limit);
            $hasMore = count($data) > $limit;
            $nextOffset = $offset + $totalReturned;
            if ($hasMore) {
                $data = array_slice($data, 0, $limit);
            }
        } else {
            $products = $queryBuilder->all();
            $totalReturned = min(count($products), $limit);
            $nextOffset = $offset + $totalReturned;
            $hasMore = count($products) > $limit;

            foreach (array_slice($products, 0, $limit) as $product) {
                if (!$product instanceof Product) {
                    continue;
                }

                $stock = $this->extractProductStock($product);
                $data[] = $this->mapProductSnapshot($product, $stock['totalStock'], $stock['hasUnlimitedStock']);
            }
        }

        return [
            'data' => $data,
            'page' => [
                'nextCursor' => $hasMore
                    ? ($isIncremental
                        ? $this->buildIncrementalCursor($nextOffset, 'products', $updatedSince, $snapshotEnd)
                        : $this->buildCursor($nextOffset))
                    : null,
                'limit' => $limit,
                'count' => $totalReturned,
                'hasMore' => $hasMore,
                'syncMode' => $isIncremental ? 'incremental' : 'full',
                'updatedSince' => $isIncremental ? $this->formatDate($updatedSince) : null,
                'snapshotEnd' => $isIncremental ? $this->formatDate($snapshotEnd) : null,
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

    public function getVariantsList(array $params): array
    {
        $status = $this->normalizeStatus((string)($params['status'] ?? 'live'));
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $sku = trim((string)($params['sku'] ?? ''));
        $productId = (int)($params['productId'] ?? 0);
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;
        $filters = [
            'status' => $status,
            'search' => $search !== '' ? $search : null,
            'sku' => $sku !== '' ? $sku : null,
            'productId' => $productId > 0 ? $productId : null,
            'limit' => $limit,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if (!class_exists(Variant::class)) {
            return [
                'data' => [],
                'meta' => [
                    'count' => 0,
                    'filters' => $filters,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'variants');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = Variant::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);

        if ($status === 'all') {
            $queryBuilder->status(null);
        } else {
            $queryBuilder->status($status);
        }
        if ($productId > 0 && method_exists($queryBuilder, 'productId')) {
            $queryBuilder->productId($productId);
        }
        if ($sku !== '' && method_exists($queryBuilder, 'sku')) {
            $queryBuilder->sku($sku);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $variants = $queryBuilder->all();
        $returnedVariants = $isIncremental ? array_slice($variants, 0, $limit) : $variants;
        $hasMore = $isIncremental && count($variants) > $limit;
        $nextOffset = $offset + count($returnedVariants);

        $data = [];
        foreach ($returnedVariants as $variant) {
            if (!$variant instanceof Variant) {
                continue;
            }
            $data[] = $this->mapVariant($variant, false);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'variants', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getVariantByIdOrSku(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $sku = trim((string)($params['sku'] ?? ''));
        $productId = (int)($params['productId'] ?? 0);
        $hasId = $id > 0;
        $hasSku = $sku !== '';

        if (($hasId ? 1 : 0) + ($hasSku ? 1 : 0) !== 1) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Provide exactly one identifier: --id or --sku.'],
                ],
            ];
        }

        if (!class_exists(Variant::class)) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        $queryBuilder = Variant::find()->status(null)->limit(1);
        if ($hasId) {
            $queryBuilder->id($id);
        } elseif (method_exists($queryBuilder, 'sku')) {
            $queryBuilder->sku($sku);
        } else {
            $queryBuilder->search($sku);
        }
        if ($productId > 0 && method_exists($queryBuilder, 'productId')) {
            $queryBuilder->productId($productId);
        }

        $variant = $queryBuilder->one();
        if (!$variant instanceof Variant) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapVariant($variant, true),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getSubscriptionsList(array $params): array
    {
        $status = strtolower(trim((string)($params['status'] ?? 'active')));
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $reference = trim((string)($params['reference'] ?? ''));
        $userId = (int)($params['userId'] ?? 0);
        $planId = (int)($params['planId'] ?? 0);
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;
        $filters = [
            'status' => $status !== '' ? $status : 'all',
            'search' => $search !== '' ? $search : null,
            'reference' => $reference !== '' ? $reference : null,
            'userId' => $userId > 0 ? $userId : null,
            'planId' => $planId > 0 ? $planId : null,
            'limit' => $limit,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if (!class_exists(Subscription::class)) {
            return [
                'data' => [],
                'meta' => [
                    'count' => 0,
                    'filters' => $filters,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'subscriptions');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = Subscription::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);

        if ($status === '' || $status === 'all' || $status === 'any') {
            if (method_exists($queryBuilder, 'anyStatus')) {
                $queryBuilder->anyStatus();
            } else {
                $queryBuilder->status(null);
            }
        } elseif (method_exists($queryBuilder, 'status')) {
            $queryBuilder->status($status);
        }
        if ($userId > 0 && method_exists($queryBuilder, 'userId')) {
            $queryBuilder->userId($userId);
        }
        if ($planId > 0 && method_exists($queryBuilder, 'planId')) {
            $queryBuilder->planId($planId);
        }
        if ($reference !== '' && method_exists($queryBuilder, 'reference')) {
            $queryBuilder->reference($reference);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $subscriptions = $queryBuilder->all();
        $returnedSubscriptions = $isIncremental ? array_slice($subscriptions, 0, $limit) : $subscriptions;
        $hasMore = $isIncremental && count($subscriptions) > $limit;
        $nextOffset = $offset + count($returnedSubscriptions);

        $data = [];
        foreach ($returnedSubscriptions as $subscription) {
            if (!$subscription instanceof Subscription) {
                continue;
            }
            $data[] = $this->mapSubscription($subscription, false);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'subscriptions', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getSubscriptionByIdOrReference(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $reference = trim((string)($params['reference'] ?? ''));
        $hasId = $id > 0;
        $hasReference = $reference !== '';

        if (($hasId ? 1 : 0) + ($hasReference ? 1 : 0) !== 1) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Provide exactly one identifier: --id or --reference.'],
                ],
            ];
        }

        if (!class_exists(Subscription::class)) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        $queryBuilder = Subscription::find()->limit(1);
        if (method_exists($queryBuilder, 'anyStatus')) {
            $queryBuilder->anyStatus();
        } else {
            $queryBuilder->status(null);
        }
        if ($hasId) {
            $queryBuilder->id($id);
        } elseif (method_exists($queryBuilder, 'reference')) {
            $queryBuilder->reference($reference);
        } else {
            $queryBuilder->search($reference);
        }

        $subscription = $queryBuilder->one();
        if (!$subscription instanceof Subscription) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapSubscription($subscription, true),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getTransfersList(array $params): array
    {
        $status = strtolower(trim((string)($params['status'] ?? 'all')));
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $originLocationId = (int)($params['originLocationId'] ?? 0);
        $destinationLocationId = (int)($params['destinationLocationId'] ?? 0);
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;
        $filters = [
            'status' => $status !== '' ? $status : 'all',
            'search' => $search !== '' ? $search : null,
            'originLocationId' => $originLocationId > 0 ? $originLocationId : null,
            'destinationLocationId' => $destinationLocationId > 0 ? $destinationLocationId : null,
            'limit' => $limit,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if (!class_exists(Transfer::class)) {
            return [
                'data' => [],
                'meta' => [
                    'count' => 0,
                    'filters' => $filters,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'transfers');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = Transfer::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);

        if ($status !== '' && $status !== 'all' && $status !== 'any' && method_exists($queryBuilder, 'transferStatus')) {
            $queryBuilder->transferStatus($status);
        }
        if ($originLocationId > 0 && method_exists($queryBuilder, 'originLocation')) {
            $queryBuilder->originLocation($originLocationId);
        }
        if ($destinationLocationId > 0 && method_exists($queryBuilder, 'destinationLocation')) {
            $queryBuilder->destinationLocation($destinationLocationId);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $transfers = $queryBuilder->all();
        $returnedTransfers = $isIncremental ? array_slice($transfers, 0, $limit) : $transfers;
        $hasMore = $isIncremental && count($transfers) > $limit;
        $nextOffset = $offset + count($returnedTransfers);

        $data = [];
        foreach ($returnedTransfers as $transfer) {
            if (!$transfer instanceof Transfer) {
                continue;
            }
            $data[] = $this->mapTransfer($transfer, false);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'transfers', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getTransferById(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Provide `--id` with a positive integer value.'],
                ],
            ];
        }

        if (!class_exists(Transfer::class)) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        $queryBuilder = Transfer::find()->status(null)->limit(1);
        $queryBuilder->id($id);

        $transfer = $queryBuilder->one();
        if (!$transfer instanceof Transfer) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapTransfer($transfer, true),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getDonationsList(array $params): array
    {
        $status = $this->normalizeStatus((string)($params['status'] ?? 'live'));
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $sku = trim((string)($params['sku'] ?? ''));
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;
        $filters = [
            'status' => $status,
            'search' => $search !== '' ? $search : null,
            'sku' => $sku !== '' ? $sku : null,
            'limit' => $limit,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if (!class_exists(Donation::class)) {
            return [
                'data' => [],
                'meta' => [
                    'count' => 0,
                    'filters' => $filters,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'donations');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = Donation::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);

        if ($status === 'all') {
            $queryBuilder->status(null);
        } else {
            $queryBuilder->status($status);
        }
        if ($sku !== '' && method_exists($queryBuilder, 'sku')) {
            $queryBuilder->sku($sku);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $donations = $queryBuilder->all();
        $returnedDonations = $isIncremental ? array_slice($donations, 0, $limit) : $donations;
        $hasMore = $isIncremental && count($donations) > $limit;
        $nextOffset = $offset + count($returnedDonations);

        $data = [];
        foreach ($returnedDonations as $donation) {
            if (!$donation instanceof Donation) {
                continue;
            }
            $data[] = $this->mapDonation($donation, false);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'donations', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getDonationByIdOrSku(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $sku = trim((string)($params['sku'] ?? ''));
        $hasId = $id > 0;
        $hasSku = $sku !== '';

        if (($hasId ? 1 : 0) + ($hasSku ? 1 : 0) !== 1) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Provide exactly one identifier: --id or --sku.'],
                ],
            ];
        }

        if (!class_exists(Donation::class)) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        $queryBuilder = Donation::find()->status(null)->limit(1);
        if ($hasId) {
            $queryBuilder->id($id);
        } elseif (method_exists($queryBuilder, 'sku')) {
            $queryBuilder->sku($sku);
        } else {
            $queryBuilder->search($sku);
        }

        $donation = $queryBuilder->one();
        if (!$donation instanceof Donation) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapDonation($donation, true),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getOrdersList(array $params): array
    {
        $status = $this->normalizeOrderStatus((string)($params['status'] ?? 'all'));
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $includeSensitive = (bool)($params['includeSensitive'] ?? true);
        $redactEmail = (bool)($params['redactEmail'] ?? false);
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $lastDays = $this->normalizeLastDays((int)($params['lastDays'] ?? ($isIncremental ? 0 : 30)));
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;
        $filters = [
            'status' => $status,
            'lastDays' => $lastDays,
            'limit' => $limit,
            'includeSensitive' => $includeSensitive,
            'redactEmail' => $redactEmail,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if (!class_exists(Order::class)) {
            return [
                'data' => [],
                'meta' => [
                    'count' => 0,
                    'filters' => $filters,
                    'errors' => ['Commerce plugin is unavailable.'],
                ],
            ];
        }

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'orders');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = Order::find()->isCompleted(true);

        if ($status !== 'all') {
            $queryBuilder->orderStatus($status);
        }

        if ($lastDays > 0) {
            $cutoff = (new DateTimeImmutable(sprintf('-%d days', $lastDays)))->format('Y-m-d H:i:s');
            $queryBuilder->dateCreated('>= ' . $cutoff);
        }

        if ($isIncremental) {
            $queryBuilder
                ->orderBy($this->buildIncrementalSort())
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder
                ->orderBy(['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC])
                ->limit($limit);
        }

        $orders = $queryBuilder->all();
        $returnedOrders = $isIncremental ? array_slice($orders, 0, $limit) : $orders;
        $hasMore = $isIncremental && count($orders) > $limit;
        $nextOffset = $offset + count($returnedOrders);

        $data = [];
        foreach ($returnedOrders as $order) {
            if (!$order instanceof Order) {
                continue;
            }
            $data[] = $this->mapOrder($order, false, $includeSensitive, $redactEmail);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'orders', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
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
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;

        $filters = [
            'status' => $status,
            'search' => $search !== '' ? $search : null,
            'section' => $section !== '' ? $section : null,
            'type' => $type !== '' ? $type : null,
            'limit' => $limit,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'entries');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = Entry::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);

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

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $entries = $queryBuilder->all();
        $returnedEntries = $isIncremental ? array_slice($entries, 0, $limit) : $entries;
        $hasMore = $isIncremental && count($entries) > $limit;
        $nextOffset = $offset + count($returnedEntries);

        $data = [];
        foreach ($returnedEntries as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }
            $data[] = $this->mapEntry($entry, false);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'entries', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getChangesFeed(array $params): array
    {
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $typesState = $this->normalizeChangeTypes($params['types'] ?? '');
        if ($typesState['error'] !== null) {
            return [
                'data' => [],
                'meta' => [
                    'count' => 0,
                    'errors' => [$typesState['error']],
                ],
            ];
        }

        $types = $typesState['types'];
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;

        if ($cursor !== '') {
            $cursorState = $this->parseIncrementalCursor($cursor, 'changes');
            if (!empty($cursorState['errors'])) {
                return [
                    'data' => [],
                    'meta' => [
                        'count' => 0,
                        'errors' => $cursorState['errors'],
                    ],
                ];
            }

            $offset = (int)$cursorState['offset'];
            $updatedSince = $cursorState['updatedSince'];
            $snapshotEnd = $cursorState['snapshotEnd'];

            $cursorPayload = $this->decodeCursorPayload($cursor) ?? [];
            $cursorTypesState = $this->normalizeChangeTypes($cursorPayload['types'] ?? []);
            if ($cursorTypesState['error'] !== null) {
                return [
                    'data' => [],
                    'meta' => [
                        'count' => 0,
                        'errors' => [$cursorTypesState['error']],
                    ],
                ];
            }

            // Cursor controls continuation state and takes precedence over query params.
            $types = $cursorTypesState['types'];
        } else {
            $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
            if ($updatedSinceState['error'] !== null) {
                return [
                    'data' => [],
                    'meta' => [
                        'count' => 0,
                        'errors' => [$updatedSinceState['error']],
                    ],
                ];
            }

            $updatedSince = $updatedSinceState['value'];
            $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        $changes = [];
        $errors = [];

        foreach ($types as $type) {
            $error = match ($type) {
                'products' => $this->appendProductChanges($changes, $updatedSince, $snapshotEnd),
                'variants' => $this->appendVariantChanges($changes, $updatedSince, $snapshotEnd),
                'subscriptions' => $this->appendSubscriptionChanges($changes, $updatedSince, $snapshotEnd),
                'transfers' => $this->appendTransferChanges($changes, $updatedSince, $snapshotEnd),
                'donations' => $this->appendDonationChanges($changes, $updatedSince, $snapshotEnd),
                'orders' => $this->appendOrderChanges($changes, $updatedSince, $snapshotEnd),
                'entries' => $this->appendEntryChanges($changes, $updatedSince, $snapshotEnd),
                'assets' => $this->appendAssetChanges($changes, $updatedSince, $snapshotEnd),
                'categories' => $this->appendCategoryChanges($changes, $updatedSince, $snapshotEnd),
                'tags' => $this->appendTagChanges($changes, $updatedSince, $snapshotEnd),
                'globalsets' => $this->appendGlobalSetChanges($changes, $updatedSince, $snapshotEnd),
                'addresses' => $this->appendAddressChanges($changes, $updatedSince, $snapshotEnd),
                'contentblocks' => $this->appendContentBlockChanges($changes, $updatedSince, $snapshotEnd),
                'users' => $this->appendUserChanges($changes, $updatedSince, $snapshotEnd),
                default => null,
            };
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        if (!empty($errors)) {
            return [
                'data' => [],
                'meta' => [
                    'count' => 0,
                    'errors' => array_values(array_unique($errors)),
                ],
            ];
        }

        usort($changes, fn(array $a, array $b): int => $this->compareChangeItems($a, $b));

        $window = array_slice($changes, $offset, $limit + 1);
        $data = array_slice($window, 0, $limit);
        $hasMore = count($window) > $limit;
        $nextOffset = $offset + count($data);

        return [
            'data' => $data,
            'page' => [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'changes', $updatedSince, $snapshotEnd, ['types' => $types]) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
                'types' => $types,
            ],
            'meta' => [
                'count' => count($data),
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

    public function getAssetsList(array $params): array
    {
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $volume = trim((string)($params['volume'] ?? ''));
        $kind = trim((string)($params['kind'] ?? ''));
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;

        $filters = [
            'search' => $search !== '' ? $search : null,
            'volume' => $volume !== '' ? $volume : null,
            'kind' => $kind !== '' ? $kind : null,
            'limit' => $limit,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'assets');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = Asset::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);

        if ($volume !== '' && method_exists($queryBuilder, 'volume')) {
            $queryBuilder->volume($volume);
        }
        if ($kind !== '' && method_exists($queryBuilder, 'kind')) {
            $queryBuilder->kind($kind);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $assets = $queryBuilder->all();
        $returnedAssets = $isIncremental ? array_slice($assets, 0, $limit) : $assets;
        $hasMore = $isIncremental && count($assets) > $limit;
        $nextOffset = $offset + count($returnedAssets);

        $data = [];
        foreach ($returnedAssets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $data[] = $this->mapAsset($asset, false);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'assets', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getAssetByIdOrFilename(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $filename = trim((string)($params['filename'] ?? ''));
        $volume = trim((string)($params['volume'] ?? ''));
        $hasId = $id > 0;
        $hasFilename = $filename !== '';

        if (($hasId ? 1 : 0) + ($hasFilename ? 1 : 0) !== 1) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Provide exactly one identifier: --id or --filename.'],
                ],
            ];
        }

        $queryBuilder = Asset::find()->status(null)->limit(1);
        if ($hasId) {
            $queryBuilder->id($id);
        } else {
            $queryBuilder->filename($filename);
        }

        if ($volume !== '' && method_exists($queryBuilder, 'volume')) {
            $queryBuilder->volume($volume);
        }

        $asset = $queryBuilder->one();
        if (!$asset instanceof Asset) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapAsset($asset, true),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getCategoriesList(array $params): array
    {
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $group = trim((string)($params['group'] ?? ''));
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;

        $filters = [
            'search' => $search !== '' ? $search : null,
            'group' => $group !== '' ? $group : null,
            'limit' => $limit,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'categories');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = Category::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);
        if ($group !== '' && method_exists($queryBuilder, 'group')) {
            $queryBuilder->group($group);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $categories = $queryBuilder->all();
        $returnedCategories = $isIncremental ? array_slice($categories, 0, $limit) : $categories;
        $hasMore = $isIncremental && count($categories) > $limit;
        $nextOffset = $offset + count($returnedCategories);

        $data = [];
        foreach ($returnedCategories as $category) {
            if (!$category instanceof Category) {
                continue;
            }
            $data[] = $this->mapCategory($category, false);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'categories', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getCategoryByIdOrSlug(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $slug = trim((string)($params['slug'] ?? ''));
        $group = trim((string)($params['group'] ?? ''));
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

        $queryBuilder = Category::find()->status(null)->limit(1);
        if ($hasId) {
            $queryBuilder->id($id);
        } else {
            $queryBuilder->slug($slug);
        }
        if ($group !== '' && method_exists($queryBuilder, 'group')) {
            $queryBuilder->group($group);
        }

        $category = $queryBuilder->one();
        if (!$category instanceof Category) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapCategory($category, true),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getTagsList(array $params): array
    {
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $group = trim((string)($params['group'] ?? ''));
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;

        $filters = [
            'search' => $search !== '' ? $search : null,
            'group' => $group !== '' ? $group : null,
            'limit' => $limit,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'tags');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = Tag::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);
        if ($group !== '' && method_exists($queryBuilder, 'group')) {
            $queryBuilder->group($group);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $tags = $queryBuilder->all();
        $returnedTags = $isIncremental ? array_slice($tags, 0, $limit) : $tags;
        $hasMore = $isIncremental && count($tags) > $limit;
        $nextOffset = $offset + count($returnedTags);

        $data = [];
        foreach ($returnedTags as $tag) {
            if (!$tag instanceof Tag) {
                continue;
            }
            $data[] = $this->mapTag($tag, false);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'tags', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getTagByIdOrSlug(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $slug = trim((string)($params['slug'] ?? ''));
        $group = trim((string)($params['group'] ?? ''));
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

        $queryBuilder = Tag::find()->status(null)->limit(1);
        if ($hasId) {
            $queryBuilder->id($id);
        } else {
            $queryBuilder->slug($slug);
        }
        if ($group !== '' && method_exists($queryBuilder, 'group')) {
            $queryBuilder->group($group);
        }

        $tag = $queryBuilder->one();
        if (!$tag instanceof Tag) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapTag($tag, true),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getGlobalSetsList(array $params): array
    {
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;

        $filters = [
            'search' => $search !== '' ? $search : null,
            'limit' => $limit,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'globalsets');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = GlobalSet::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $globalSets = $queryBuilder->all();
        $returnedGlobalSets = $isIncremental ? array_slice($globalSets, 0, $limit) : $globalSets;
        $hasMore = $isIncremental && count($globalSets) > $limit;
        $nextOffset = $offset + count($returnedGlobalSets);

        $data = [];
        foreach ($returnedGlobalSets as $globalSet) {
            if (!$globalSet instanceof GlobalSet) {
                continue;
            }
            $data[] = $this->mapGlobalSet($globalSet, false);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'globalsets', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getGlobalSetByIdOrHandle(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $handle = trim((string)($params['handle'] ?? ''));
        $hasId = $id > 0;
        $hasHandle = $handle !== '';

        if (($hasId ? 1 : 0) + ($hasHandle ? 1 : 0) !== 1) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Provide exactly one identifier: --id or --handle.'],
                ],
            ];
        }

        $queryBuilder = GlobalSet::find()->status(null)->limit(1);
        if ($hasId) {
            $queryBuilder->id($id);
        } else {
            $queryBuilder->handle($handle);
        }

        $globalSet = $queryBuilder->one();
        if (!$globalSet instanceof GlobalSet) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapGlobalSet($globalSet, true),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getAddressesList(array $params): array
    {
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $ownerId = (int)($params['ownerId'] ?? 0);
        $countryCode = strtoupper(trim((string)($params['countryCode'] ?? '')));
        $postalCode = trim((string)($params['postalCode'] ?? ''));
        $includeSensitive = (bool)($params['includeSensitive'] ?? false);
        $redactSensitive = (bool)($params['redactSensitive'] ?? false);
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;

        $filters = [
            'search' => $search !== '' ? $search : null,
            'ownerId' => $ownerId > 0 ? $ownerId : null,
            'countryCode' => $countryCode !== '' ? $countryCode : null,
            'postalCode' => $postalCode !== '' ? $postalCode : null,
            'limit' => $limit,
            'includeSensitive' => $includeSensitive,
            'redactSensitive' => $redactSensitive,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'addresses');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = Address::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);
        if ($ownerId > 0 && method_exists($queryBuilder, 'ownerId')) {
            $queryBuilder->ownerId($ownerId);
        }
        if ($countryCode !== '' && method_exists($queryBuilder, 'countryCode')) {
            $queryBuilder->countryCode($countryCode);
        }
        if ($postalCode !== '' && method_exists($queryBuilder, 'postalCode')) {
            $queryBuilder->postalCode($postalCode);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $addresses = $queryBuilder->all();
        $returnedAddresses = $isIncremental ? array_slice($addresses, 0, $limit) : $addresses;
        $hasMore = $isIncremental && count($addresses) > $limit;
        $nextOffset = $offset + count($returnedAddresses);

        $data = [];
        foreach ($returnedAddresses as $address) {
            if (!$address instanceof Address) {
                continue;
            }
            $data[] = $this->mapAddress($address, false, $includeSensitive, $redactSensitive);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'addresses', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getAddressByIdOrUid(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $uid = trim((string)($params['uid'] ?? ''));
        $ownerId = (int)($params['ownerId'] ?? 0);
        $includeSensitive = (bool)($params['includeSensitive'] ?? false);
        $redactSensitive = (bool)($params['redactSensitive'] ?? false);
        $hasId = $id > 0;
        $hasUid = $uid !== '';

        if (($hasId ? 1 : 0) + ($hasUid ? 1 : 0) !== 1) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Provide exactly one identifier: --id or --uid.'],
                ],
            ];
        }

        $queryBuilder = Address::find()->status(null)->limit(1);
        if ($hasId) {
            $queryBuilder->id($id);
        } else {
            $queryBuilder->uid($uid);
        }
        if ($ownerId > 0 && method_exists($queryBuilder, 'ownerId')) {
            $queryBuilder->ownerId($ownerId);
        }

        $address = $queryBuilder->one();
        if (!$address instanceof Address) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapAddress($address, true, $includeSensitive, $redactSensitive),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getContentBlocksList(array $params): array
    {
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $ownerId = (int)($params['ownerId'] ?? 0);
        $fieldId = (int)($params['fieldId'] ?? 0);
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;

        $filters = [
            'search' => $search !== '' ? $search : null,
            'ownerId' => $ownerId > 0 ? $ownerId : null,
            'fieldId' => $fieldId > 0 ? $fieldId : null,
            'limit' => $limit,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'contentblocks');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = ContentBlock::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);
        if ($ownerId > 0 && method_exists($queryBuilder, 'ownerId')) {
            $queryBuilder->ownerId($ownerId);
        }
        if ($fieldId > 0 && method_exists($queryBuilder, 'fieldId')) {
            $queryBuilder->fieldId($fieldId);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $blocks = $queryBuilder->all();
        $returnedBlocks = $isIncremental ? array_slice($blocks, 0, $limit) : $blocks;
        $hasMore = $isIncremental && count($blocks) > $limit;
        $nextOffset = $offset + count($returnedBlocks);

        $data = [];
        foreach ($returnedBlocks as $block) {
            if (!$block instanceof ContentBlock) {
                continue;
            }
            $data[] = $this->mapContentBlock($block, false);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'contentblocks', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getContentBlockByIdOrUid(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $uid = trim((string)($params['uid'] ?? ''));
        $ownerId = (int)($params['ownerId'] ?? 0);
        $fieldId = (int)($params['fieldId'] ?? 0);
        $hasId = $id > 0;
        $hasUid = $uid !== '';

        if (($hasId ? 1 : 0) + ($hasUid ? 1 : 0) !== 1) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Provide exactly one identifier: --id or --uid.'],
                ],
            ];
        }

        $queryBuilder = ContentBlock::find()->status(null)->limit(1);
        if ($hasId) {
            $queryBuilder->id($id);
        } else {
            $queryBuilder->uid($uid);
        }
        if ($ownerId > 0 && method_exists($queryBuilder, 'ownerId')) {
            $queryBuilder->ownerId($ownerId);
        }
        if ($fieldId > 0 && method_exists($queryBuilder, 'fieldId')) {
            $queryBuilder->fieldId($fieldId);
        }

        $block = $queryBuilder->one();
        if (!$block instanceof ContentBlock) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapContentBlock($block, true),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    public function getUsersList(array $params): array
    {
        $status = $this->normalizeUserStatus((string)($params['status'] ?? 'active'));
        $limit = $this->normalizeLimit((int)($params['limit'] ?? 50));
        $search = trim((string)($params['search'] ?? $params['q'] ?? ''));
        $group = trim((string)($params['group'] ?? ''));
        $includeSensitive = (bool)($params['includeSensitive'] ?? false);
        $redactEmail = (bool)($params['redactEmail'] ?? false);
        $cursor = trim((string)($params['cursor'] ?? ''));
        $updatedSinceRaw = trim((string)($params['updatedSince'] ?? ''));
        $isIncremental = ($cursor !== '' || $updatedSinceRaw !== '');
        $offset = 0;
        $updatedSince = null;
        $snapshotEnd = null;

        $filters = [
            'status' => $status,
            'search' => $search !== '' ? $search : null,
            'group' => $group !== '' ? $group : null,
            'limit' => $limit,
            'includeSensitive' => $includeSensitive,
            'redactEmail' => $redactEmail,
            'syncMode' => $isIncremental ? 'incremental' : 'full',
            'updatedSince' => $isIncremental ? ($updatedSinceRaw !== '' ? $updatedSinceRaw : null) : null,
        ];

        if ($isIncremental) {
            if ($cursor !== '') {
                $cursorState = $this->parseIncrementalCursor($cursor, 'users');
                if (!empty($cursorState['errors'])) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => $cursorState['errors'],
                        ],
                    ];
                }

                $offset = (int)$cursorState['offset'];
                $updatedSince = $cursorState['updatedSince'];
                $snapshotEnd = $cursorState['snapshotEnd'];
            } else {
                $updatedSinceState = $this->parseUpdatedSince($updatedSinceRaw);
                if ($updatedSinceState['error'] !== null) {
                    return [
                        'data' => [],
                        'meta' => [
                            'count' => 0,
                            'filters' => $filters,
                            'errors' => [$updatedSinceState['error']],
                        ],
                    ];
                }

                $updatedSince = $updatedSinceState['value'];
                $snapshotEnd = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $filters['updatedSince'] = $this->formatDate($updatedSince);
        }

        $queryBuilder = User::find()
            ->orderBy($isIncremental ? $this->buildIncrementalSort() : ['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]);

        if ($status === 'all') {
            $queryBuilder->status(null);
        } else {
            $queryBuilder->status($status);
        }
        if ($group !== '' && method_exists($queryBuilder, 'group')) {
            $queryBuilder->group($group);
        }
        if ($search !== '') {
            $queryBuilder->search($search);
        }

        if ($isIncremental) {
            $queryBuilder
                ->offset($offset)
                ->limit($limit + 1);
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->limit($limit);
        }

        $users = $queryBuilder->all();
        $returnedUsers = $isIncremental ? array_slice($users, 0, $limit) : $users;
        $hasMore = $isIncremental && count($users) > $limit;
        $nextOffset = $offset + count($returnedUsers);

        $data = [];
        foreach ($returnedUsers as $user) {
            if (!$user instanceof User) {
                continue;
            }
            $data[] = $this->mapUser($user, false, $includeSensitive, $redactEmail);
        }

        $payload = [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'filters' => $filters,
                'errors' => [],
            ],
        ];

        if ($isIncremental) {
            $payload['page'] = [
                'nextCursor' => $hasMore ? $this->buildIncrementalCursor($nextOffset, 'users', $updatedSince, $snapshotEnd) : null,
                'limit' => $limit,
                'count' => count($data),
                'hasMore' => $hasMore,
                'syncMode' => 'incremental',
                'updatedSince' => $this->formatDate($updatedSince),
                'snapshotEnd' => $this->formatDate($snapshotEnd),
            ];
        }

        return $payload;
    }

    public function getUserByIdOrUsername(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $username = trim((string)($params['username'] ?? ''));
        $includeSensitive = (bool)($params['includeSensitive'] ?? false);
        $redactEmail = (bool)($params['redactEmail'] ?? false);
        $hasId = $id > 0;
        $hasUsername = $username !== '';

        if (($hasId ? 1 : 0) + ($hasUsername ? 1 : 0) !== 1) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => ['Provide exactly one identifier: --id or --username.'],
                ],
            ];
        }

        $queryBuilder = User::find()->status(null)->limit(1);
        if ($hasId) {
            $queryBuilder->id($id);
        } else {
            $queryBuilder->username($username);
        }

        $user = $queryBuilder->one();
        if (!$user instanceof User) {
            return [
                'data' => null,
                'meta' => [
                    'count' => 0,
                    'errors' => [],
                ],
            ];
        }

        return [
            'data' => $this->mapUser($user, true, $includeSensitive, $redactEmail),
            'meta' => [
                'count' => 1,
                'errors' => [],
            ],
        ];
    }

    private function calculateReadinessScore(): int
    {
        $score = 0;
        foreach ($this->getReadinessBreakdown() as $criterion) {
            $score += (int)($criterion['score'] ?? 0);
        }
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

    private function normalizeUserStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            '', 'active' => 'active',
            'inactive' => 'inactive',
            'pending' => 'pending',
            'suspended' => 'suspended',
            'locked' => 'locked',
            'credentialed' => 'credentialed',
            'all', 'any' => 'all',
            default => 'active',
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

    private function buildIncrementalSort(): array
    {
        return ['elements.dateUpdated' => SORT_ASC, 'elements.id' => SORT_ASC];
    }

    private function normalizeChangeTypes(mixed $rawTypes): array
    {
        $canonical = [
            'products',
            'variants',
            'subscriptions',
            'transfers',
            'donations',
            'orders',
            'entries',
            'assets',
            'categories',
            'tags',
            'globalsets',
            'addresses',
            'contentblocks',
            'users',
        ];
        $aliases = [
            'product' => 'products',
            'products' => 'products',
            'variant' => 'variants',
            'variants' => 'variants',
            'subscription' => 'subscriptions',
            'subscriptions' => 'subscriptions',
            'transfer' => 'transfers',
            'transfers' => 'transfers',
            'donation' => 'donations',
            'donations' => 'donations',
            'order' => 'orders',
            'orders' => 'orders',
            'entry' => 'entries',
            'entries' => 'entries',
            'asset' => 'assets',
            'assets' => 'assets',
            'category' => 'categories',
            'categories' => 'categories',
            'tag' => 'tags',
            'tags' => 'tags',
            'globalset' => 'globalsets',
            'globalsets' => 'globalsets',
            'global-set' => 'globalsets',
            'global-sets' => 'globalsets',
            'address' => 'addresses',
            'addresses' => 'addresses',
            'contentblock' => 'contentblocks',
            'contentblocks' => 'contentblocks',
            'content-block' => 'contentblocks',
            'content-blocks' => 'contentblocks',
            'user' => 'users',
            'users' => 'users',
        ];

        $tokens = [];
        if (is_array($rawTypes)) {
            foreach ($rawTypes as $value) {
                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }
                $tokens[] = (string)$value;
            }
        } else {
            $raw = trim((string)$rawTypes);
            if ($raw !== '') {
                $tokens = preg_split('/[\s,]+/', $raw) ?: [];
            }
        }

        if (empty($tokens)) {
            return ['types' => $canonical, 'error' => null];
        }

        $resolved = [];
        $invalid = [];
        foreach ($tokens as $token) {
            $normalized = strtolower(trim($token));
            if ($normalized === '') {
                continue;
            }

            $mapped = $aliases[$normalized] ?? null;
            if ($mapped === null) {
                $invalid[] = $token;
                continue;
            }

            $resolved[$mapped] = true;
        }

        if (!empty($invalid)) {
            return [
                'types' => [],
                'error' => sprintf(
                    'Invalid `types` value(s): %s. Allowed values: %s.',
                    implode(', ', array_values(array_unique($invalid))),
                    implode(', ', $canonical)
                ),
            ];
        }

        $types = [];
        foreach ($canonical as $type) {
            if (isset($resolved[$type])) {
                $types[] = $type;
            }
        }

        if (empty($types)) {
            $types = $canonical;
        }

        return ['types' => $types, 'error' => null];
    }

    private function appendProductChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        if (!class_exists(Product::class)) {
            return 'Commerce plugin is unavailable for product changes.';
        }

        $updatedQuery = Product::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $typeHandle = $product->type?->handle ?? null;
            $url = $product->getUrl();
            if ($url === null && $product->uri) {
                $url = UrlHelper::siteUrl($product->uri);
            }

            $this->appendChangeItem(
                $changes,
                'product',
                (string)$product->id,
                $this->resolveChangeAction($product->dateCreated, $product->dateUpdated),
                $product->dateUpdated,
                [
                    'id' => (int)$product->id,
                    'title' => (string)$product->title,
                    'slug' => (string)$product->slug,
                    'uri' => (string)$product->uri,
                    'type' => $typeHandle,
                    'status' => $product->getStatus() ?? null,
                    'updatedAt' => $this->formatDate($product->dateUpdated),
                    'url' => $url,
                ]
            );
        }

        $deletedQuery = Product::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'product',
                (string)$product->id,
                'deleted',
                $product->dateDeleted ?? $product->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendOrderChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        if (!class_exists(Order::class)) {
            return 'Commerce plugin is unavailable for order changes.';
        }

        $updatedQuery = Order::find()
            ->isCompleted(true)
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $order) {
            if (!$order instanceof Order) {
                continue;
            }

            $orderStatus = $order->getOrderStatus();

            $this->appendChangeItem(
                $changes,
                'order',
                (string)$order->id,
                $this->resolveChangeAction($order->dateCreated, $order->dateUpdated),
                $order->dateUpdated,
                [
                    'id' => (int)$order->id,
                    'number' => (string)($order->number ?? ''),
                    'reference' => (string)($order->reference ?? ''),
                    'status' => $orderStatus?->handle,
                    'statusName' => $orderStatus?->name,
                    'isCompleted' => (bool)$order->isCompleted,
                    'isPaid' => (bool)$order->isPaid,
                    'totalPrice' => $order->totalPrice === null ? null : (float)$order->totalPrice,
                    'updatedAt' => $this->formatDate($order->dateUpdated),
                ]
            );
        }

        $deletedQuery = Order::find()
            ->isCompleted(true)
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $order) {
            if (!$order instanceof Order) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'order',
                (string)$order->id,
                'deleted',
                $order->dateDeleted ?? $order->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendEntryChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        $updatedQuery = Entry::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'entry',
                (string)$entry->id,
                $this->resolveChangeAction($entry->dateCreated, $entry->dateUpdated),
                $entry->dateUpdated,
                $this->mapEntry($entry, false)
            );
        }

        $deletedQuery = Entry::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'entry',
                (string)$entry->id,
                'deleted',
                $entry->dateDeleted ?? $entry->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendVariantChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        if (!class_exists(Variant::class)) {
            return 'Commerce plugin is unavailable for variant changes.';
        }

        $updatedQuery = Variant::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $variant) {
            if (!$variant instanceof Variant) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'variant',
                (string)$variant->id,
                $this->resolveChangeAction($variant->dateCreated, $variant->dateUpdated),
                $variant->dateUpdated,
                $this->mapVariant($variant, false)
            );
        }

        $deletedQuery = Variant::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $variant) {
            if (!$variant instanceof Variant) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'variant',
                (string)$variant->id,
                'deleted',
                $variant->dateDeleted ?? $variant->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendSubscriptionChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        if (!class_exists(Subscription::class)) {
            return 'Commerce plugin is unavailable for subscription changes.';
        }

        $updatedQuery = Subscription::find()
            ->orderBy($this->buildIncrementalSort());
        if (method_exists($updatedQuery, 'anyStatus')) {
            $updatedQuery->anyStatus();
        } else {
            $updatedQuery->status(null);
        }
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $subscription) {
            if (!$subscription instanceof Subscription) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'subscription',
                (string)$subscription->id,
                $this->resolveChangeAction($subscription->dateCreated, $subscription->dateUpdated),
                $subscription->dateUpdated,
                $this->mapSubscription($subscription, false)
            );
        }

        $deletedQuery = Subscription::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        if (method_exists($deletedQuery, 'anyStatus')) {
            $deletedQuery->anyStatus();
        } else {
            $deletedQuery->status(null);
        }
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $subscription) {
            if (!$subscription instanceof Subscription) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'subscription',
                (string)$subscription->id,
                'deleted',
                $subscription->dateDeleted ?? $subscription->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendTransferChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        if (!class_exists(Transfer::class)) {
            return 'Commerce plugin is unavailable for transfer changes.';
        }

        $updatedQuery = Transfer::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $transfer) {
            if (!$transfer instanceof Transfer) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'transfer',
                (string)$transfer->id,
                $this->resolveChangeAction($transfer->dateCreated, $transfer->dateUpdated),
                $transfer->dateUpdated,
                $this->mapTransfer($transfer, false)
            );
        }

        $deletedQuery = Transfer::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $transfer) {
            if (!$transfer instanceof Transfer) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'transfer',
                (string)$transfer->id,
                'deleted',
                $transfer->dateDeleted ?? $transfer->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendDonationChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        if (!class_exists(Donation::class)) {
            return 'Commerce plugin is unavailable for donation changes.';
        }

        $updatedQuery = Donation::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $donation) {
            if (!$donation instanceof Donation) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'donation',
                (string)$donation->id,
                $this->resolveChangeAction($donation->dateCreated, $donation->dateUpdated),
                $donation->dateUpdated,
                $this->mapDonation($donation, false)
            );
        }

        $deletedQuery = Donation::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $donation) {
            if (!$donation instanceof Donation) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'donation',
                (string)$donation->id,
                'deleted',
                $donation->dateDeleted ?? $donation->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendAssetChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        $updatedQuery = Asset::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'asset',
                (string)$asset->id,
                $this->resolveChangeAction($asset->dateCreated, $asset->dateUpdated),
                $asset->dateUpdated,
                $this->mapAsset($asset, false)
            );
        }

        $deletedQuery = Asset::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'asset',
                (string)$asset->id,
                'deleted',
                $asset->dateDeleted ?? $asset->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendCategoryChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        $updatedQuery = Category::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $category) {
            if (!$category instanceof Category) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'category',
                (string)$category->id,
                $this->resolveChangeAction($category->dateCreated, $category->dateUpdated),
                $category->dateUpdated,
                $this->mapCategory($category, false)
            );
        }

        $deletedQuery = Category::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $category) {
            if (!$category instanceof Category) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'category',
                (string)$category->id,
                'deleted',
                $category->dateDeleted ?? $category->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendTagChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        $updatedQuery = Tag::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $tag) {
            if (!$tag instanceof Tag) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'tag',
                (string)$tag->id,
                $this->resolveChangeAction($tag->dateCreated, $tag->dateUpdated),
                $tag->dateUpdated,
                $this->mapTag($tag, false)
            );
        }

        $deletedQuery = Tag::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $tag) {
            if (!$tag instanceof Tag) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'tag',
                (string)$tag->id,
                'deleted',
                $tag->dateDeleted ?? $tag->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendGlobalSetChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        $updatedQuery = GlobalSet::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $globalSet) {
            if (!$globalSet instanceof GlobalSet) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'globalset',
                (string)$globalSet->id,
                $this->resolveChangeAction($globalSet->dateCreated, $globalSet->dateUpdated),
                $globalSet->dateUpdated,
                $this->mapGlobalSet($globalSet, false)
            );
        }

        $deletedQuery = GlobalSet::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $globalSet) {
            if (!$globalSet instanceof GlobalSet) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'globalset',
                (string)$globalSet->id,
                'deleted',
                $globalSet->dateDeleted ?? $globalSet->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendAddressChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        $updatedQuery = Address::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $address) {
            if (!$address instanceof Address) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'address',
                (string)$address->id,
                $this->resolveChangeAction($address->dateCreated, $address->dateUpdated),
                $address->dateUpdated,
                $this->mapAddress($address, false, false, true)
            );
        }

        $deletedQuery = Address::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $address) {
            if (!$address instanceof Address) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'address',
                (string)$address->id,
                'deleted',
                $address->dateDeleted ?? $address->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendContentBlockChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        $updatedQuery = ContentBlock::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $block) {
            if (!$block instanceof ContentBlock) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'contentblock',
                (string)$block->id,
                $this->resolveChangeAction($block->dateCreated, $block->dateUpdated),
                $block->dateUpdated,
                $this->mapContentBlock($block, false)
            );
        }

        $deletedQuery = ContentBlock::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $block) {
            if (!$block instanceof ContentBlock) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'contentblock',
                (string)$block->id,
                'deleted',
                $block->dateDeleted ?? $block->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendUserChanges(array &$changes, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): ?string
    {
        $updatedQuery = User::find()
            ->status(null)
            ->orderBy($this->buildIncrementalSort());
        $this->applyDateUpdatedWindow($updatedQuery, $updatedSince, $snapshotEnd);

        foreach ($updatedQuery->all() as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'user',
                (string)$user->id,
                $this->resolveChangeAction($user->dateCreated, $user->dateUpdated),
                $user->dateUpdated,
                $this->mapUser($user, false, false, true)
            );
        }

        $deletedQuery = User::find()
            ->trashed()
            ->orderBy(['elements.dateDeleted' => SORT_ASC, 'elements.id' => SORT_ASC]);
        $this->applyDateDeletedWindow($deletedQuery, $updatedSince, $snapshotEnd);

        foreach ($deletedQuery->all() as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $this->appendChangeItem(
                $changes,
                'user',
                (string)$user->id,
                'deleted',
                $user->dateDeleted ?? $user->dateUpdated,
                null
            );
        }

        return null;
    }

    private function appendChangeItem(
        array &$changes,
        string $resourceType,
        string $resourceId,
        string $action,
        ?DateTimeInterface $updatedAt,
        ?array $snapshot
    ): void {
        $timestamp = $this->formatDate($updatedAt);
        if ($timestamp === null) {
            return;
        }

        $changes[] = [
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'action' => $action,
            'updatedAt' => $timestamp,
            'snapshot' => $snapshot,
        ];
    }

    private function resolveChangeAction(?DateTimeInterface $createdAt, ?DateTimeInterface $updatedAt): string
    {
        if ($createdAt === null || $updatedAt === null) {
            return 'updated';
        }

        return $createdAt->getTimestamp() === $updatedAt->getTimestamp() ? 'created' : 'updated';
    }

    private function compareChangeItems(array $a, array $b): int
    {
        $updatedAtComparison = strcmp((string)($a['updatedAt'] ?? ''), (string)($b['updatedAt'] ?? ''));
        if ($updatedAtComparison !== 0) {
            return $updatedAtComparison;
        }

        $typeComparison = strcmp((string)($a['resourceType'] ?? ''), (string)($b['resourceType'] ?? ''));
        if ($typeComparison !== 0) {
            return $typeComparison;
        }

        $resourceIdA = (string)($a['resourceId'] ?? '');
        $resourceIdB = (string)($b['resourceId'] ?? '');
        if (ctype_digit($resourceIdA) && ctype_digit($resourceIdB)) {
            $idComparison = (int)$resourceIdA <=> (int)$resourceIdB;
        } else {
            $idComparison = strcmp($resourceIdA, $resourceIdB);
        }
        if ($idComparison !== 0) {
            return $idComparison;
        }

        return strcmp((string)($a['action'] ?? ''), (string)($b['action'] ?? ''));
    }

    private function parseUpdatedSince(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['value' => null, 'error' => null];
        }

        $parsed = $this->parseRfc3339Timestamp($value);
        if ($parsed === null) {
            return ['value' => null, 'error' => 'Invalid `updatedSince`; expected RFC3339 timestamp.'];
        }

        return ['value' => $parsed, 'error' => null];
    }

    private function parseIncrementalCursor(string $cursor, string $resource): array
    {
        $payload = $this->decodeCursorPayload($cursor);
        if ($payload === null) {
            return [
                'offset' => 0,
                'updatedSince' => null,
                'snapshotEnd' => null,
                'errors' => ['Invalid cursor; expected opaque incremental checkpoint token.'],
            ];
        }

        if (($payload['mode'] ?? '') !== 'incremental' || ($payload['resource'] ?? '') !== $resource) {
            return [
                'offset' => 0,
                'updatedSince' => null,
                'snapshotEnd' => null,
                'errors' => ['Cursor does not match this endpoint or sync mode.'],
            ];
        }

        $version = (int)($payload['version'] ?? self::INCREMENTAL_CURSOR_VERSION);
        if ($version !== self::INCREMENTAL_CURSOR_VERSION) {
            return [
                'offset' => 0,
                'updatedSince' => null,
                'snapshotEnd' => null,
                'errors' => ['Unsupported cursor version; restart sync with `updatedSince`.'],
            ];
        }

        $offset = max(0, (int)($payload['offset'] ?? 0));
        $updatedSince = null;
        $updatedSinceRaw = trim((string)($payload['updatedSince'] ?? ''));
        if ($updatedSinceRaw !== '') {
            $updatedSince = $this->parseRfc3339Timestamp($updatedSinceRaw);
            if ($updatedSince === null) {
                return [
                    'offset' => 0,
                    'updatedSince' => null,
                    'snapshotEnd' => null,
                    'errors' => ['Cursor contains invalid `updatedSince` timestamp; restart sync.'],
                ];
            }
        }

        $snapshotEndRaw = trim((string)($payload['snapshotEnd'] ?? ''));
        $snapshotEnd = $this->parseRfc3339Timestamp($snapshotEndRaw);
        if ($snapshotEnd === null) {
            return [
                'offset' => 0,
                'updatedSince' => null,
                'snapshotEnd' => null,
                'errors' => ['Cursor contains invalid `snapshotEnd`; restart sync.'],
            ];
        }

        $issuedAtRaw = trim((string)($payload['issuedAt'] ?? ''));
        $issuedAt = $this->parseRfc3339Timestamp($issuedAtRaw);
        if ($issuedAt === null) {
            return [
                'offset' => 0,
                'updatedSince' => null,
                'snapshotEnd' => null,
                'errors' => ['Cursor is missing `issuedAt`; restart sync.'],
            ];
        }

        $expiresAt = $issuedAt->modify('+' . self::INCREMENTAL_CURSOR_TTL_SECONDS . ' seconds');
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($expiresAt < $now) {
            return [
                'offset' => 0,
                'updatedSince' => null,
                'snapshotEnd' => null,
                'errors' => ['Cursor expired; restart sync from a recent `updatedSince` checkpoint.'],
            ];
        }

        return [
            'offset' => $offset,
            'updatedSince' => $updatedSince,
            'snapshotEnd' => $snapshotEnd,
            'errors' => [],
        ];
    }

    private function buildIncrementalCursor(
        int $offset,
        string $resource,
        ?DateTimeImmutable $updatedSince,
        ?DateTimeImmutable $snapshotEnd,
        array $extra = []
    ): string
    {
        $snapshot = $snapshotEnd ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $payload = [
            'version' => self::INCREMENTAL_CURSOR_VERSION,
            'mode' => 'incremental',
            'resource' => $resource,
            'offset' => max(0, $offset),
            'updatedSince' => $this->formatDate($updatedSince),
            'snapshotEnd' => $this->formatDate($snapshot),
            'issuedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        foreach ($extra as $key => $value) {
            $payload[$key] = $value;
        }

        return base64_encode(json_encode($payload));
    }

    private function applyDateUpdatedWindow(mixed $queryBuilder, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): void
    {
        if ($snapshotEnd === null) {
            return;
        }

        $clauses = ['<= ' . $this->formatDbDate($snapshotEnd)];
        if ($updatedSince !== null) {
            $clauses[] = '>= ' . $this->formatDbDate($updatedSince);
        }

        if (count($clauses) === 1) {
            $queryBuilder->dateUpdated($clauses[0]);
            return;
        }

        $queryBuilder->dateUpdated(array_merge(['and'], $clauses));
    }

    private function applyDateDeletedWindow(mixed $queryBuilder, ?DateTimeImmutable $updatedSince, ?DateTimeImmutable $snapshotEnd): void
    {
        if ($snapshotEnd === null) {
            return;
        }

        if (!method_exists($queryBuilder, 'andWhere')) {
            return;
        }

        $conditions = [
            'and',
            ['not', ['elements.dateDeleted' => null]],
            ['<=', 'elements.dateDeleted', $this->formatDbDate($snapshotEnd)],
        ];
        if ($updatedSince !== null) {
            $conditions[] = ['>=', 'elements.dateDeleted', $this->formatDbDate($updatedSince)];
        }

        $queryBuilder->andWhere($conditions);
    }

    private function formatDbDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function parseRfc3339Timestamp(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $value)) {
            return null;
        }

        try {
            $timestamp = new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }

        return $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }

    private function decodeCursorPayload(string $cursor): ?array
    {
        if ($cursor === '') {
            return null;
        }

        $decoded = base64_decode($cursor, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return null;
        }

        return $payload;
    }

    private function parseCursor(string $cursor): int
    {
        $payload = $this->decodeCursorPayload($cursor);
        if (!is_array($payload)) {
            return 0;
        }

        $offset = (int)($payload['offset'] ?? 0);
        return max(0, $offset);
    }

    private function buildCursor(int $offset): string
    {
        return base64_encode(json_encode(['offset' => $offset]));
    }

    private function mapProductSnapshot(Product $product, int $totalStock, bool $hasUnlimitedStock): array
    {
        $productType = $product->type;
        $url = UrlHelper::siteUrl('products/' . $product->uri);

        return [
            'id' => (int)$product->id,
            'title' => (string)$product->title,
            'slug' => (string)$product->slug,
            'uri' => (string)$product->uri,
            'type' => $productType?->handle ?? null,
            'status' => $product->getStatus() ?? null,
            'updatedAt' => $this->formatDate($product->dateUpdated),
            'url' => $url,
            'hasUnlimitedStock' => $hasUnlimitedStock,
            'totalStock' => $hasUnlimitedStock ? null : $totalStock,
        ];
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

    private function mapVariant(Variant $variant, bool $detailed): array
    {
        $status = method_exists($variant, 'getStatus') ? $variant->getStatus() : null;
        $url = method_exists($variant, 'getUrl') ? $variant->getUrl() : null;
        $product = method_exists($variant, 'getProduct') ? $variant->getProduct() : null;

        $data = [
            'id' => (int)$variant->id,
            'productId' => $variant->productId === null ? null : (int)$variant->productId,
            'sku' => (string)($variant->sku ?? ''),
            'title' => (string)($variant->title ?? ''),
            'status' => $status,
            'isDefault' => (bool)($variant->isDefault ?? false),
            'enabled' => (bool)($variant->enabled ?? false),
            'dateCreated' => $this->formatDate($variant->dateCreated ?? null),
            'updatedAt' => $this->formatDate($variant->dateUpdated ?? null),
            'url' => $url,
            'stock' => $variant->stock === null ? null : (int)$variant->stock,
            'hasUnlimitedStock' => (bool)($variant->hasUnlimitedStock ?? false),
            'isAvailable' => method_exists($variant, 'getIsAvailable') ? (bool)$variant->getIsAvailable() : null,
        ];

        if ($product !== null) {
            $data['product'] = [
                'id' => (int)($product->id ?? 0),
                'title' => (string)($product->title ?? ''),
                'slug' => (string)($product->slug ?? ''),
                'type' => $product->type?->handle ?? null,
            ];
        }

        if (!$detailed) {
            return $data;
        }

        $data['price'] = $variant->price === null ? null : (float)$variant->price;
        $data['minQty'] = $variant->minQty === null ? null : (int)$variant->minQty;
        $data['maxQty'] = $variant->maxQty === null ? null : (int)$variant->maxQty;
        $data['isPromotable'] = method_exists($variant, 'getIsPromotable') ? (bool)$variant->getIsPromotable() : null;
        $data['weight'] = $variant->weight === null ? null : (float)$variant->weight;
        $data['length'] = $variant->length === null ? null : (float)$variant->length;
        $data['width'] = $variant->width === null ? null : (float)$variant->width;
        $data['height'] = $variant->height === null ? null : (float)$variant->height;

        return $data;
    }

    private function mapSubscription(Subscription $subscription, bool $detailed): array
    {
        $status = method_exists($subscription, 'getStatus') ? $subscription->getStatus() : null;
        $data = [
            'id' => (int)$subscription->id,
            'reference' => (string)($subscription->reference ?? ''),
            'status' => $status,
            'userId' => $subscription->userId === null ? null : (int)$subscription->userId,
            'planId' => $subscription->planId === null ? null : (int)$subscription->planId,
            'gatewayId' => $subscription->gatewayId === null ? null : (int)$subscription->gatewayId,
            'orderId' => $subscription->orderId === null ? null : (int)$subscription->orderId,
            'dateCreated' => $this->formatDate($subscription->dateCreated ?? null),
            'updatedAt' => $this->formatDate($subscription->dateUpdated ?? null),
        ];

        if (!$detailed) {
            return $data;
        }

        $nextPaymentDate = $subscription->nextPaymentDate ?? null;
        $data['nextPaymentDate'] = $nextPaymentDate instanceof DateTimeInterface
            ? $this->formatDate($nextPaymentDate)
            : (is_string($nextPaymentDate) && $nextPaymentDate !== '' ? $nextPaymentDate : null);
        $data['isCanceled'] = (bool)($subscription->isCanceled ?? false);
        $data['isSuspended'] = (bool)($subscription->isSuspended ?? false);
        $data['isExpired'] = (bool)($subscription->isExpired ?? false);
        $data['onTrial'] = (bool)($subscription->isOnTrial ?? false);

        $plan = method_exists($subscription, 'getPlan') ? $subscription->getPlan() : null;
        if ($plan !== null) {
            $data['plan'] = [
                'id' => (int)($plan->id ?? 0),
                'name' => (string)($plan->name ?? ''),
                'handle' => (string)($plan->handle ?? ''),
            ];
        }

        $subscriber = method_exists($subscription, 'getSubscriber') ? $subscription->getSubscriber() : null;
        if ($subscriber !== null) {
            $data['subscriber'] = [
                'id' => (int)($subscriber->id ?? 0),
                'username' => (string)($subscriber->username ?? ''),
            ];
        }

        return $data;
    }

    private function mapTransfer(Transfer $transfer, bool $detailed): array
    {
        $transferStatus = $transfer->transferStatus ?? null;
        if (is_object($transferStatus) && isset($transferStatus->value)) {
            $transferStatus = $transferStatus->value;
        } elseif (!is_string($transferStatus) && !is_numeric($transferStatus)) {
            $transferStatus = null;
        }

        $data = [
            'id' => (int)$transfer->id,
            'label' => (string)$transfer,
            'status' => $transferStatus === null ? null : (string)$transferStatus,
            'originLocationId' => $transfer->originLocationId === null ? null : (int)$transfer->originLocationId,
            'destinationLocationId' => $transfer->destinationLocationId === null ? null : (int)$transfer->destinationLocationId,
            'dateCreated' => $this->formatDate($transfer->dateCreated ?? null),
            'updatedAt' => $this->formatDate($transfer->dateUpdated ?? null),
        ];

        if (!$detailed) {
            return $data;
        }

        $originLocation = method_exists($transfer, 'getOriginLocation') ? $transfer->getOriginLocation() : null;
        if ($originLocation !== null) {
            $data['originLocation'] = [
                'id' => (int)($originLocation->id ?? 0),
                'name' => method_exists($originLocation, 'getUiLabel')
                    ? (string)$originLocation->getUiLabel()
                    : (string)($originLocation->name ?? ''),
            ];
        }

        $destinationLocation = method_exists($transfer, 'getDestinationLocation') ? $transfer->getDestinationLocation() : null;
        if ($destinationLocation !== null) {
            $data['destinationLocation'] = [
                'id' => (int)($destinationLocation->id ?? 0),
                'name' => method_exists($destinationLocation, 'getUiLabel')
                    ? (string)$destinationLocation->getUiLabel()
                    : (string)($destinationLocation->name ?? ''),
            ];
        }

        if (method_exists($transfer, 'getDetails')) {
            $details = $transfer->getDetails();
            if (is_array($details)) {
                $data['detailsCount'] = count($details);
            }
        }

        return $data;
    }

    private function mapDonation(Donation $donation, bool $detailed): array
    {
        $status = method_exists($donation, 'getStatus') ? $donation->getStatus() : null;
        $url = method_exists($donation, 'getUrl') ? $donation->getUrl() : null;

        $data = [
            'id' => (int)$donation->id,
            'sku' => (string)($donation->sku ?? ''),
            'title' => (string)($donation->title ?? ''),
            'status' => $status,
            'enabled' => (bool)($donation->enabled ?? false),
            'availableForPurchase' => (bool)($donation->availableForPurchase ?? false),
            'dateCreated' => $this->formatDate($donation->dateCreated ?? null),
            'updatedAt' => $this->formatDate($donation->dateUpdated ?? null),
            'url' => $url,
        ];

        if (!$detailed) {
            return $data;
        }

        $data['price'] = $donation->price === null ? null : (float)$donation->price;
        $data['salePrice'] = $donation->salePrice === null ? null : (float)$donation->salePrice;
        $data['promotable'] = (bool)($donation->promotable ?? false);
        $data['freeShipping'] = (bool)($donation->freeShipping ?? false);
        $data['taxCategoryId'] = $donation->taxCategoryId === null ? null : (int)$donation->taxCategoryId;
        $data['shippingCategoryId'] = $donation->shippingCategoryId === null ? null : (int)$donation->shippingCategoryId;

        return $data;
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
            'updatedAt' => $this->formatDate($order->dateUpdated),
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

    private function mapAsset(Asset $asset, bool $detailed): array
    {
        $volumeHandle = null;
        try {
            $volumeHandle = $asset->getVolume()->handle ?? null;
        } catch (\Throwable) {
            $volumeHandle = null;
        }

        $data = [
            'id' => (int)$asset->id,
            'title' => (string)$asset->title,
            'filename' => $asset->getFilename(),
            'kind' => $asset->kind ? (string)$asset->kind : null,
            'mimeType' => $asset->getMimeType(),
            'size' => $asset->size === null ? null : (int)$asset->size,
            'volume' => $volumeHandle,
            'folderId' => $asset->folderId === null ? null : (int)$asset->folderId,
            'uploaderId' => $asset->uploaderId === null ? null : (int)$asset->uploaderId,
            'status' => $asset->getStatus() ?? null,
            'updatedAt' => $this->formatDate($asset->dateUpdated),
            'url' => $asset->getUrl(),
        ];

        if (!$detailed) {
            return $data;
        }

        $data['alt'] = $asset->alt ? (string)$asset->alt : null;
        $data['extension'] = $asset->getExtension();
        $data['dateModified'] = $this->formatDate($asset->dateModified);
        $data['dateCreated'] = $this->formatDate($asset->dateCreated);
        $data['dateUpdated'] = $this->formatDate($asset->dateUpdated);
        $data['height'] = $asset->getHeight();
        $data['width'] = $asset->getWidth();

        return $data;
    }

    private function mapCategory(Category $category, bool $detailed): array
    {
        $groupHandle = null;
        try {
            $groupHandle = $category->getGroup()->handle ?? null;
        } catch (\Throwable) {
            $groupHandle = null;
        }

        $data = [
            'id' => (int)$category->id,
            'title' => (string)$category->title,
            'slug' => (string)$category->slug,
            'uri' => (string)$category->uri,
            'group' => $groupHandle,
            'status' => $category->getStatus() ?? null,
            'updatedAt' => $this->formatDate($category->dateUpdated),
            'url' => $category->getUrl(),
        ];

        if (!$detailed) {
            return $data;
        }

        $data['dateCreated'] = $this->formatDate($category->dateCreated);
        $data['dateUpdated'] = $this->formatDate($category->dateUpdated);
        $data['enabled'] = (bool)$category->enabled;
        $data['groupId'] = $category->groupId === null ? null : (int)$category->groupId;

        return $data;
    }

    private function mapTag(Tag $tag, bool $detailed): array
    {
        $groupHandle = null;
        try {
            $groupHandle = $tag->getGroup()->handle ?? null;
        } catch (\Throwable) {
            $groupHandle = null;
        }

        $data = [
            'id' => (int)$tag->id,
            'title' => (string)$tag->title,
            'slug' => (string)$tag->slug,
            'uri' => (string)$tag->uri,
            'group' => $groupHandle,
            'status' => $tag->getStatus() ?? null,
            'updatedAt' => $this->formatDate($tag->dateUpdated),
            'url' => $tag->getUrl(),
        ];

        if (!$detailed) {
            return $data;
        }

        $data['dateCreated'] = $this->formatDate($tag->dateCreated);
        $data['dateUpdated'] = $this->formatDate($tag->dateUpdated);
        $data['enabled'] = (bool)$tag->enabled;
        $data['groupId'] = $tag->groupId === null ? null : (int)$tag->groupId;

        return $data;
    }

    private function mapGlobalSet(GlobalSet $globalSet, bool $detailed): array
    {
        $data = [
            'id' => (int)$globalSet->id,
            'name' => (string)($globalSet->name ?? ''),
            'handle' => (string)($globalSet->handle ?? ''),
            'status' => $globalSet->getStatus() ?? null,
            'updatedAt' => $this->formatDate($globalSet->dateUpdated),
        ];

        if (!$detailed) {
            return $data;
        }

        $data['dateCreated'] = $this->formatDate($globalSet->dateCreated);
        $data['dateUpdated'] = $this->formatDate($globalSet->dateUpdated);
        $data['sortOrder'] = $globalSet->sortOrder === null ? null : (int)$globalSet->sortOrder;
        $data['enabled'] = (bool)$globalSet->enabled;

        return $data;
    }

    private function mapAddress(Address $address, bool $detailed, bool $includeSensitive, bool $redactSensitive): array
    {
        $sensitiveRedacted = !$includeSensitive && $redactSensitive;

        $data = [
            'id' => (int)$address->id,
            'title' => (string)$address->title,
            'countryCode' => $address->getCountryCode(),
            'status' => $address->getStatus() ?? null,
            'ownerId' => $address->getOwnerId(),
            'updatedAt' => $this->formatDate($address->dateUpdated),
            'sensitiveRedacted' => $sensitiveRedacted,
        ];

        if ($sensitiveRedacted) {
            $data['fullName'] = null;
            $data['organization'] = null;
            $data['postalCode'] = null;
            $data['locality'] = null;
            $data['administrativeArea'] = null;
        } else {
            $data['fullName'] = $address->fullName ? (string)$address->fullName : null;
            $data['organization'] = $address->organization ? (string)$address->organization : null;
            $data['postalCode'] = $address->getPostalCode();
            $data['locality'] = $address->locality ? (string)$address->locality : null;
            $data['administrativeArea'] = $address->administrativeArea ? (string)$address->administrativeArea : null;
        }

        if (!$detailed) {
            return $data;
        }

        $data['dateCreated'] = $this->formatDate($address->dateCreated);
        $data['dateUpdated'] = $this->formatDate($address->dateUpdated);
        $data['fieldId'] = $address->fieldId === null ? null : (int)$address->fieldId;
        $data['primaryOwnerId'] = $address->getPrimaryOwnerId();
        $data['sortOrder'] = $address->sortOrder === null ? null : (int)$address->sortOrder;

        if ($sensitiveRedacted) {
            $data['firstName'] = null;
            $data['lastName'] = null;
            $data['organizationTaxId'] = null;
            $data['addressLine1'] = null;
            $data['addressLine2'] = null;
            $data['addressLine3'] = null;
            $data['dependentLocality'] = null;
            $data['sortingCode'] = null;
            $data['latitude'] = null;
            $data['longitude'] = null;
        } else {
            $data['firstName'] = $address->firstName ? (string)$address->firstName : null;
            $data['lastName'] = $address->lastName ? (string)$address->lastName : null;
            $data['organizationTaxId'] = $address->organizationTaxId ? (string)$address->organizationTaxId : null;
            $data['addressLine1'] = $address->addressLine1 ? (string)$address->addressLine1 : null;
            $data['addressLine2'] = $address->addressLine2 ? (string)$address->addressLine2 : null;
            $data['addressLine3'] = $address->addressLine3 ? (string)$address->addressLine3 : null;
            $data['dependentLocality'] = $address->dependentLocality ? (string)$address->dependentLocality : null;
            $data['sortingCode'] = $address->sortingCode ? (string)$address->sortingCode : null;
            $data['latitude'] = $address->latitude ? (string)$address->latitude : null;
            $data['longitude'] = $address->longitude ? (string)$address->longitude : null;
        }

        return $data;
    }

    private function mapContentBlock(ContentBlock $block, bool $detailed): array
    {
        $fieldHandle = null;
        try {
            $fieldHandle = $block->getField()?->handle ?? null;
        } catch (\Throwable) {
            $fieldHandle = null;
        }

        $data = [
            'id' => (int)$block->id,
            'title' => (string)$block->title,
            'fieldId' => $block->fieldId === null ? null : (int)$block->fieldId,
            'field' => $fieldHandle,
            'ownerId' => $block->getOwnerId(),
            'primaryOwnerId' => $block->getPrimaryOwnerId(),
            'sortOrder' => $block->sortOrder === null ? null : (int)$block->sortOrder,
            'status' => $block->getStatus() ?? null,
            'updatedAt' => $this->formatDate($block->dateUpdated),
        ];

        if (!$detailed) {
            return $data;
        }

        $data['dateCreated'] = $this->formatDate($block->dateCreated);
        $data['dateUpdated'] = $this->formatDate($block->dateUpdated);
        $data['enabled'] = (bool)$block->enabled;

        return $data;
    }

    private function mapUser(User $user, bool $detailed, bool $includeSensitive, bool $redactEmail): array
    {
        $email = $user->email ? (string)$user->email : null;
        $emailRedacted = false;
        if (!$includeSensitive && $redactEmail) {
            $email = null;
            $emailRedacted = true;
        }

        $data = [
            'id' => (int)$user->id,
            'username' => $user->username ? (string)$user->username : null,
            'status' => $user->getStatus(),
            'admin' => (bool)$user->admin,
            'active' => (bool)$user->active,
            'email' => $email,
            'emailRedacted' => $emailRedacted,
            'updatedAt' => $this->formatDate($user->dateUpdated),
        ];

        if (!$detailed) {
            return $data;
        }

        $groupHandles = [];
        foreach ($user->getGroups() as $group) {
            $handle = trim((string)($group->handle ?? ''));
            if ($handle === '') {
                continue;
            }
            $groupHandles[] = $handle;
        }
        $groupHandles = array_values(array_unique($groupHandles));
        sort($groupHandles);

        $data['dateCreated'] = $this->formatDate($user->dateCreated);
        $data['dateUpdated'] = $this->formatDate($user->dateUpdated);
        $data['lastLoginDate'] = $this->formatDate($user->lastLoginDate);
        $data['pending'] = (bool)$user->pending;
        $data['suspended'] = (bool)$user->suspended;
        $data['locked'] = (bool)$user->locked;
        $data['credentialed'] = (bool)$user->getIsCredentialed();
        $data['groupHandles'] = $groupHandles;
        if ($includeSensitive) {
            $data['firstName'] = $user->firstName ? (string)$user->firstName : null;
            $data['lastName'] = $user->lastName ? (string)$user->lastName : null;
            $data['friendlyName'] = $user->getFriendlyName();
            $data['unverifiedEmail'] = $user->unverifiedEmail ? (string)$user->unverifiedEmail : null;
        } else {
            $data['firstName'] = null;
            $data['lastName'] = null;
            $data['friendlyName'] = null;
            $data['unverifiedEmail'] = null;
        }

        return $data;
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

        return '0.9.2';
    }

    private function formatDate(?DateTimeInterface $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
