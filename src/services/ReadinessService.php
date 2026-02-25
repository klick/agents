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
    private const INCREMENTAL_CURSOR_VERSION = 1;
    private const INCREMENTAL_CURSOR_TTL_SECONDS = 604800;
    private const READINESS_READY_THRESHOLD = 50;

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

    public function getReadinessBreakdown(): array
    {
        $isSiteRequest = (bool)Craft::$app->getRequest()->getIsSiteRequest();
        $databaseAvailable = (bool)Craft::$app->getDb();
        $commerceEnabled = (bool)Craft::$app->getPlugins()->getPlugin('commerce');

        return [
            [
                'id' => 'site-request',
                'label' => 'Site request context available',
                'weight' => 20,
                'passed' => $isSiteRequest,
                'score' => $isSiteRequest ? 20 : 0,
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
            'readinessVersion' => '0.1.1',
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

        $queryBuilder = Product::find()
            ->offset($offset)
            ->limit($limit + 1);

        if ($status !== 'all') {
            $queryBuilder->status($status);
        }

        if ($query !== '') {
            $queryBuilder->search($query);
        }

        if ($isIncremental) {
            $queryBuilder->orderBy($this->buildIncrementalSort());
            $this->applyDateUpdatedWindow($queryBuilder, $updatedSince, $snapshotEnd);
        } else {
            $queryBuilder->orderBy($this->buildSort($sort));
        }

        $products = $queryBuilder->all();
        $totalReturned = min(count($products), $limit);
        $nextOffset = $offset + $totalReturned;
        $hasMore = count($products) > $limit;

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
            if ($type === 'products') {
                $error = $this->appendProductChanges($changes, $updatedSince, $snapshotEnd);
                if ($error !== null) {
                    $errors[] = $error;
                }
                continue;
            }

            if ($type === 'orders') {
                $error = $this->appendOrderChanges($changes, $updatedSince, $snapshotEnd);
                if ($error !== null) {
                    $errors[] = $error;
                }
                continue;
            }

            if ($type === 'entries') {
                $error = $this->appendEntryChanges($changes, $updatedSince, $snapshotEnd);
                if ($error !== null) {
                    $errors[] = $error;
                }
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
        $canonical = ['products', 'orders', 'entries'];
        $aliases = [
            'product' => 'products',
            'products' => 'products',
            'order' => 'orders',
            'orders' => 'orders',
            'entry' => 'entries',
            'entries' => 'entries',
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
                    'Invalid `types` value(s): %s. Allowed values: products, orders, entries.',
                    implode(', ', array_values(array_unique($invalid)))
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
                    'dateUpdated' => $this->formatDate($order->dateUpdated),
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

        $clauses = ['<= ' . $this->formatDbDate($snapshotEnd)];
        if ($updatedSince !== null) {
            $clauses[] = '>= ' . $this->formatDbDate($updatedSince);
        }

        if (count($clauses) === 1) {
            $queryBuilder->dateDeleted($clauses[0]);
            return;
        }

        $queryBuilder->dateDeleted(array_merge(['and'], $clauses));
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
