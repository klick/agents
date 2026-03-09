<?php

namespace Klick\Agents\services;

use craft\base\Component;
use Klick\Agents\Plugin;

class IncidentFeedService extends Component
{
    private const CATEGORY_BY_SIGNAL_ID = [
        'auth_failures' => 'auth',
        'scope_denials' => 'authorization',
        'rate_limit_denials' => 'rate_limit',
        'server_errors_5xx' => 'server',
        'queue_depth' => 'queue',
        'webhook_dlq_failed' => 'webhook',
        'consumer_lag_max_seconds' => 'sync',
    ];

    public function getSnapshot(string $severity = 'all', int $limit = 50): array
    {
        $normalizedSeverity = strtolower(trim($severity));
        if (!in_array($normalizedSeverity, ['all', 'warn', 'critical'], true)) {
            $normalizedSeverity = 'all';
        }

        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 200) {
            $limit = 200;
        }

        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return [
                'service' => 'agents',
                'type' => 'incidents.snapshot',
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'status' => 'ok',
                'data' => [],
                'meta' => [
                    'count' => 0,
                    'limit' => $limit,
                    'filters' => ['severity' => $normalizedSeverity],
                    'source' => 'agents.runtime',
                    'redaction' => 'strict',
                ],
            ];
        }

        $metricsSnapshot = $plugin->getObservabilityMetricsService()->getMetricsSnapshot();
        $generatedAt = (string)($metricsSnapshot['generatedAt'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $reliability = (array)($metricsSnapshot['reliability'] ?? []);
        if (empty($reliability)) {
            $reliability = $plugin->getReliabilitySignalService()->evaluateSnapshot($metricsSnapshot);
        }

        $incidents = [];
        foreach ((array)($reliability['signals'] ?? []) as $signal) {
            if (!is_array($signal)) {
                continue;
            }

            $signalSeverity = strtolower(trim((string)($signal['severity'] ?? 'ok')));
            if (!in_array($signalSeverity, ['warn', 'critical'], true)) {
                continue;
            }

            if ($normalizedSeverity !== 'all' && $signalSeverity !== $normalizedSeverity) {
                continue;
            }

            $signalId = strtolower(trim((string)($signal['id'] ?? '')));
            $metricName = (string)($signal['metric'] ?? '');
            $incidents[] = [
                'incidentId' => $this->buildIncidentId($signalId, $signalSeverity, $metricName),
                'severity' => $signalSeverity,
                'category' => $this->resolveCategory($signalId),
                'signalId' => $signalId,
                'title' => (string)($signal['label'] ?? 'Runtime signal'),
                'metricName' => $metricName,
                'currentValue' => $this->toNumber($signal['value'] ?? 0),
                'warnThreshold' => $this->toNumber($signal['warnThreshold'] ?? 0),
                'criticalThreshold' => $this->toNumber($signal['criticalThreshold'] ?? 0),
                'unit' => (string)($signal['unit'] ?? ''),
                'comparator' => (string)($signal['comparator'] ?? ''),
                'recommendedAction' => (string)($signal['primaryResponse'] ?? ''),
                'source' => 'agents.runtime',
                'firstSeenAt' => $generatedAt,
                'lastSeenAt' => $generatedAt,
                'redaction' => 'strict',
            ];
        }

        usort($incidents, static function(array $left, array $right): int {
            $leftSeverity = (string)($left['severity'] ?? 'warn');
            $rightSeverity = (string)($right['severity'] ?? 'warn');

            $severityOrder = [
                'critical' => 2,
                'warn' => 1,
            ];
            $severityCmp = ($severityOrder[$rightSeverity] ?? 0) <=> ($severityOrder[$leftSeverity] ?? 0);
            if ($severityCmp !== 0) {
                return $severityCmp;
            }

            return strcmp((string)($left['incidentId'] ?? ''), (string)($right['incidentId'] ?? ''));
        });

        $data = array_slice($incidents, 0, $limit);
        $counts = [
            'critical' => 0,
            'warn' => 0,
        ];
        foreach ($data as $incident) {
            $incidentSeverity = strtolower((string)($incident['severity'] ?? 'warn'));
            if (isset($counts[$incidentSeverity])) {
                $counts[$incidentSeverity]++;
            }
        }

        return [
            'service' => 'agents',
            'type' => 'incidents.snapshot',
            'generatedAt' => $generatedAt,
            'status' => (string)($reliability['status'] ?? 'ok'),
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'limit' => $limit,
                'filters' => ['severity' => $normalizedSeverity],
                'severityCounts' => $counts,
                'source' => 'agents.runtime',
                'redaction' => 'strict',
            ],
        ];
    }

    private function resolveCategory(string $signalId): string
    {
        $normalized = strtolower(trim($signalId));
        if ($normalized === '') {
            return 'runtime';
        }

        return self::CATEGORY_BY_SIGNAL_ID[$normalized] ?? 'runtime';
    }

    private function buildIncidentId(string $signalId, string $severity, string $metricName): string
    {
        $fingerprint = strtolower(trim($signalId . '|' . $severity . '|' . $metricName));
        return 'inc_' . substr(sha1($fingerprint), 0, 16);
    }

    private function toNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }

        return 0;
    }
}
