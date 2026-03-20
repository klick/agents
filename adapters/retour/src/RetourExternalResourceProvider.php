<?php

namespace Klick\AgentsRetour;

use Craft;
use craft\db\Query;
use InvalidArgumentException;
use Klick\Agents\external\ExternalResourceDefinition;
use Klick\Agents\external\ExternalResourceParameterDefinition;
use Klick\Agents\external\ExternalResourceProviderInterface;

class RetourExternalResourceProvider implements ExternalResourceProviderInterface
{
    private const REDIRECTS_TABLE = '{{%retour_redirects}}';

    public function getPluginHandle(): string
    {
        return 'retour';
    }

    public function getPluginName(): string
    {
        return 'Retour';
    }

    public function getPluginDescription(): string
    {
        return 'Read redirect rules managed by Retour.';
    }

    public function isAvailable(): bool
    {
        if (!Craft::$app->getPlugins()->isPluginInstalled('retour')) {
            return false;
        }

        return Craft::$app->getDb()->getSchema()->getTableSchema(self::REDIRECTS_TABLE, true) !== null;
    }

    public function getResourceDefinitions(): array
    {
        return [
            new ExternalResourceDefinition(
                handle: 'redirects',
                name: 'Redirects',
                description: 'Read Retour redirect rules for audits, redirect hygiene, and migration review.',
                queryParameters: [
                    new ExternalResourceParameterDefinition('q', 'string', 'Match against source or destination URLs.'),
                    new ExternalResourceParameterDefinition('siteId', 'integer', 'Optional site id filter.', false, null, [], 1),
                    new ExternalResourceParameterDefinition('enabled', 'boolean', 'Optional enabled-state filter.'),
                    new ExternalResourceParameterDefinition('statusCode', 'integer', 'Optional HTTP status code filter.', false, null, [], 300, 399),
                    new ExternalResourceParameterDefinition('limit', 'integer', 'Maximum rows to return. Default 50, max 200.', false, null, [], 1, 200),
                ],
                listItemSchema: [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'sourceUrl' => ['type' => 'string'],
                        'destinationUrl' => ['type' => 'string'],
                        'statusCode' => ['type' => 'integer'],
                        'matchType' => ['type' => 'string'],
                        'siteId' => ['type' => 'integer'],
                        'locale' => ['type' => 'string'],
                        'enabled' => ['type' => 'boolean'],
                        'hits' => ['type' => 'integer'],
                        'lastHitAt' => ['type' => 'string', 'format' => 'date-time'],
                        'dateCreated' => ['type' => 'string', 'format' => 'date-time'],
                        'dateUpdated' => ['type' => 'string', 'format' => 'date-time'],
                    ],
                ],
                detailSchema: [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'sourceUrl' => ['type' => 'string'],
                        'destinationUrl' => ['type' => 'string'],
                        'statusCode' => ['type' => 'integer'],
                        'matchType' => ['type' => 'string'],
                        'siteId' => ['type' => 'integer'],
                        'locale' => ['type' => 'string'],
                        'enabled' => ['type' => 'boolean'],
                        'hits' => ['type' => 'integer'],
                        'lastHitAt' => ['type' => 'string', 'format' => 'date-time'],
                        'dateCreated' => ['type' => 'string', 'format' => 'date-time'],
                        'dateUpdated' => ['type' => 'string', 'format' => 'date-time'],
                        'raw' => ['type' => 'object'],
                    ],
                ],
                itemIdDescription: 'Retour redirect id'
            ),
        ];
    }

    public function fetchResourceList(string $resourceHandle, array $queryParams): array
    {
        if ($resourceHandle !== 'redirects') {
            throw new InvalidArgumentException(sprintf('Unsupported Retour resource `%s`.', $resourceHandle));
        }

        $limit = $this->normalizeLimit($queryParams['limit'] ?? null);
        $query = (new Query())
            ->from(self::REDIRECTS_TABLE)
            ->limit($limit)
            ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC]);

        $schema = Craft::$app->getDb()->getSchema()->getTableSchema(self::REDIRECTS_TABLE, true);
        if ($schema === null) {
            throw new InvalidArgumentException('Retour redirects table is unavailable.');
        }

        $q = trim((string)($queryParams['q'] ?? ''));
        if ($q !== '' && $this->hasColumns($schema, ['redirectSrcUrl', 'redirectDestUrl'])) {
            $query->andWhere(['or', ['like', 'redirectSrcUrl', $q], ['like', 'redirectDestUrl', $q]]);
        }

        $siteId = $queryParams['siteId'] ?? null;
        if ($siteId !== null && $siteId !== '' && isset($schema->columns['siteId'])) {
            if (!is_numeric($siteId) || (int)$siteId <= 0) {
                throw new InvalidArgumentException('siteId must be a positive integer.');
            }
            $query->andWhere(['siteId' => (int)$siteId]);
        }

        $enabled = $queryParams['enabled'] ?? null;
        if ($enabled !== null && $enabled !== '' && isset($schema->columns['enabled'])) {
            $query->andWhere(['enabled' => $this->normalizeBoolean($enabled)]);
        }

        $statusCode = $queryParams['statusCode'] ?? null;
        if ($statusCode !== null && $statusCode !== '' && isset($schema->columns['redirectHttpCode'])) {
            if (!is_numeric($statusCode) || (int)$statusCode < 300 || (int)$statusCode > 399) {
                throw new InvalidArgumentException('statusCode must be an integer between 300 and 399.');
            }
            $query->andWhere(['redirectHttpCode' => (int)$statusCode]);
        }

        $rows = $query->all();
        $data = array_map(fn(array $row): array => $this->normalizeRedirectRow($row, false), $rows);

        return [
            'data' => $data,
            'meta' => [
                'plugin' => 'retour',
                'resource' => 'redirects',
                'count' => count($data),
            ],
            'page' => [
                'limit' => $limit,
                'nextCursor' => null,
            ],
        ];
    }

    public function fetchResourceItem(string $resourceHandle, string $id, array $queryParams): ?array
    {
        unset($queryParams);

        if ($resourceHandle !== 'redirects') {
            throw new InvalidArgumentException(sprintf('Unsupported Retour resource `%s`.', $resourceHandle));
        }

        if (!preg_match('/^\d+$/', trim($id))) {
            throw new InvalidArgumentException('Retour redirect id must be a positive integer.');
        }

        $row = (new Query())
            ->from(self::REDIRECTS_TABLE)
            ->where(['id' => (int)$id])
            ->one();
        if (!is_array($row)) {
            return null;
        }

        return $this->normalizeRedirectRow($row, true);
    }

    private function normalizeLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 50;
        }
        if (!is_numeric($raw)) {
            throw new InvalidArgumentException('limit must be an integer between 1 and 200.');
        }

        $limit = (int)$raw;
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('limit must be an integer between 1 and 200.');
        }

        return $limit;
    }

    private function normalizeBoolean(mixed $raw): bool
    {
        $value = strtolower(trim((string)$raw));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new InvalidArgumentException('enabled must be a boolean value.');
    }

    private function hasColumns(object $schema, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!isset($schema->columns[$column])) {
                return false;
            }
        }

        return true;
    }

    private function normalizeRedirectRow(array $row, bool $includeRaw): array
    {
        $normalized = [
            'id' => isset($row['id']) ? (int)$row['id'] : 0,
            'sourceUrl' => (string)($row['redirectSrcUrl'] ?? ''),
            'destinationUrl' => (string)($row['redirectDestUrl'] ?? ''),
            'statusCode' => isset($row['redirectHttpCode']) ? (int)$row['redirectHttpCode'] : null,
            'matchType' => isset($row['redirectMatchType']) ? (string)$row['redirectMatchType'] : '',
            'siteId' => isset($row['siteId']) ? (int)$row['siteId'] : null,
            'locale' => isset($row['locale']) ? (string)$row['locale'] : '',
            'enabled' => array_key_exists('enabled', $row) ? (bool)$row['enabled'] : true,
            'hits' => isset($row['hitCount']) ? (int)$row['hitCount'] : 0,
            'lastHitAt' => $this->normalizeDate($row['hitLastTime'] ?? null),
            'dateCreated' => $this->normalizeDate($row['dateCreated'] ?? null),
            'dateUpdated' => $this->normalizeDate($row['dateUpdated'] ?? null),
        ];

        if ($includeRaw) {
            $normalized['raw'] = $row;
        }

        return $normalized;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        $stringValue = trim((string)$value);
        if ($stringValue === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($stringValue))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return $stringValue;
        }
    }
}
