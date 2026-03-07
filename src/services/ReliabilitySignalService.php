<?php

namespace Klick\Agents\services;

use Klick\Agents\Plugin;
use Klick\Agents\models\Settings;
use craft\base\Component;
use craft\helpers\App;

class ReliabilitySignalService extends Component
{
    private const THRESHOLDS_VERSION = 'reliability-thresholds-v1';

    public function evaluateSnapshot(array $snapshot): array
    {
        $metrics = (array)($snapshot['metrics'] ?? []);
        $generatedAt = (string)($snapshot['generatedAt'] ?? gmdate('Y-m-d\TH:i:s\Z'));

        return $this->evaluateMetricSeries($metrics, ['generatedAt' => $generatedAt]);
    }

    public function evaluateMetricSeries(array $metrics, array $context = []): array
    {
        $metricValues = $this->metricValuesByName($metrics);
        $thresholds = $this->thresholdCatalog();

        $signals = [];
        foreach ($thresholds as $threshold) {
            $metricName = (string)($threshold['metric'] ?? '');
            $value = $metricValues[$metricName] ?? 0;
            $signal = $this->buildSignal($threshold, $value);
            if ($signal !== null) {
                $signals[] = $signal;
            }
        }

        usort($signals, function(array $left, array $right): int {
            $severityCmp = $this->severityRank((string)($right['severity'] ?? 'ok'))
                <=> $this->severityRank((string)($left['severity'] ?? 'ok'));
            if ($severityCmp !== 0) {
                return $severityCmp;
            }

            $overflowLeft = (float)($left['overflowRatio'] ?? 0.0);
            $overflowRight = (float)($right['overflowRatio'] ?? 0.0);
            return $overflowRight <=> $overflowLeft;
        });

        $warnCount = 0;
        $criticalCount = 0;
        foreach ($signals as $signal) {
            $severity = (string)($signal['severity'] ?? 'ok');
            if ($severity === 'critical') {
                $criticalCount++;
            } elseif ($severity === 'warn') {
                $warnCount++;
            }
        }

        $status = 'ok';
        if ($criticalCount > 0) {
            $status = 'critical';
        } elseif ($warnCount > 0) {
            $status = 'warn';
        }

        $topSignals = array_values(array_filter($signals, static function(array $signal): bool {
            return in_array((string)($signal['severity'] ?? 'ok'), ['warn', 'critical'], true);
        }));

        return [
            'status' => $status,
            'thresholdsVersion' => self::THRESHOLDS_VERSION,
            'generatedAt' => (string)($context['generatedAt'] ?? gmdate('Y-m-d\TH:i:s\Z')),
            'summary' => [
                'signalsEvaluated' => count($signals),
                'signalsWarn' => $warnCount,
                'signalsCritical' => $criticalCount,
                'signalsOk' => max(0, count($signals) - $warnCount - $criticalCount),
            ],
            'topSignals' => array_slice($topSignals, 0, 5),
            'signals' => $signals,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function thresholdCatalog(): array
    {
        $consumerLagThresholds = $this->consumerLagThresholds();

        return [
            [
                'id' => 'auth_failures',
                'label' => 'Auth failures',
                'metric' => 'agents_auth_failures_total',
                'unit' => 'requests',
                'warnThreshold' => 5,
                'criticalThreshold' => 20,
                'comparator' => '>',
                'primaryResponse' => 'Inspect /agents/v1/auth/whoami and rotate/revoke affected keys.',
            ],
            [
                'id' => 'scope_denials',
                'label' => 'Scope denials (403)',
                'metric' => 'agents_forbidden_total',
                'unit' => 'requests',
                'warnThreshold' => 5,
                'criticalThreshold' => 20,
                'comparator' => '>',
                'primaryResponse' => 'Review granted scopes and narrow endpoint polling for offending credential.',
            ],
            [
                'id' => 'rate_limit_denials',
                'label' => 'Rate-limit denials',
                'metric' => 'agents_rate_limit_exceeded_total',
                'unit' => 'requests',
                'warnThreshold' => 10,
                'criticalThreshold' => 40,
                'comparator' => '>',
                'primaryResponse' => 'Add client jitter/backoff; tune PLUGIN_AGENTS_RATE_LIMIT_* only for sustained expected load.',
            ],
            [
                'id' => 'server_errors_5xx',
                'label' => 'Server errors (5xx)',
                'metric' => 'agents_errors_5xx_total',
                'unit' => 'responses',
                'warnThreshold' => 1,
                'criticalThreshold' => 5,
                'comparator' => '>=',
                'primaryResponse' => 'Treat as incident if repeating; correlate request IDs with Craft logs and readiness checks.',
            ],
            [
                'id' => 'queue_depth',
                'label' => 'Queue depth',
                'metric' => 'agents_queue_depth',
                'unit' => 'jobs',
                'warnThreshold' => 50,
                'criticalThreshold' => 150,
                'comparator' => '>',
                'primaryResponse' => 'Verify queue workers are healthy and draining jobs.',
            ],
            [
                'id' => 'webhook_dlq_failed',
                'label' => 'Webhook DLQ failed events',
                'metric' => 'agents_webhook_dlq_failed',
                'unit' => 'events',
                'warnThreshold' => 1,
                'criticalThreshold' => 10,
                'comparator' => '>',
                'primaryResponse' => 'Replay DLQ events and verify webhook receiver signature/availability.',
            ],
            [
                'id' => 'consumer_lag_max_seconds',
                'label' => 'Max sync-state lag',
                'metric' => 'agents_consumer_lag_max_seconds',
                'unit' => 'seconds',
                'warnThreshold' => $consumerLagThresholds['warn'],
                'criticalThreshold' => $consumerLagThresholds['critical'],
                'comparator' => '>',
                'primaryResponse' => 'Inspect /agents/v1/sync-state/lag and restore checkpoint writes.',
            ],
        ];
    }

    /**
     * @return array{warn:int,critical:int}
     */
    private function consumerLagThresholds(): array
    {
        $warnDefault = 300;
        $criticalDefault = 900;

        $settings = Plugin::getInstance()->getSettings();
        if (!$settings instanceof Settings) {
            return [
                'warn' => $warnDefault,
                'critical' => $criticalDefault,
            ];
        }

        $warn = $this->parseThresholdValue($settings->reliabilityConsumerLagWarnSeconds, $warnDefault, 0, 604800);
        $critical = $this->parseThresholdValue($settings->reliabilityConsumerLagCriticalSeconds, $criticalDefault, 1, 604800);
        if ($critical <= $warn) {
            $critical = $warn + 1;
        }

        return [
            'warn' => $warn,
            'critical' => $critical,
        ];
    }

    private function parseThresholdValue(mixed $raw, int $default, int $min, int $max): int
    {
        if (is_int($raw) || is_float($raw)) {
            return $this->clampThreshold((int)$raw, $min, $max);
        }

        if (!is_string($raw)) {
            return $default;
        }

        $value = trim($raw);
        if ($value === '') {
            return $default;
        }

        if (is_numeric($value)) {
            return $this->clampThreshold((int)$value, $min, $max);
        }

        $parsed = App::parseEnv($value);
        if (!is_int($parsed) && !is_float($parsed) && !(is_string($parsed) && is_numeric($parsed))) {
            return $default;
        }

        return $this->clampThreshold((int)$parsed, $min, $max);
    }

    private function clampThreshold(int $value, int $min, int $max): int
    {
        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    private function buildSignal(array $threshold, int|float $value): ?array
    {
        $id = trim((string)($threshold['id'] ?? ''));
        $label = trim((string)($threshold['label'] ?? ''));
        $metric = trim((string)($threshold['metric'] ?? ''));
        if ($id === '' || $label === '' || $metric === '') {
            return null;
        }

        $warnThreshold = (float)($threshold['warnThreshold'] ?? 0);
        $criticalThreshold = (float)($threshold['criticalThreshold'] ?? 0);
        $comparator = (string)($threshold['comparator'] ?? '>');
        if (!in_array($comparator, ['>', '>='], true)) {
            $comparator = '>';
        }

        $severity = 'ok';
        if ($this->matchesThreshold($value, $criticalThreshold, $comparator)) {
            $severity = 'critical';
        } elseif ($this->matchesThreshold($value, $warnThreshold, $comparator)) {
            $severity = 'warn';
        }

        $overflowRatio = 0.0;
        if ($severity !== 'ok') {
            $baseline = $severity === 'critical' ? $criticalThreshold : $warnThreshold;
            if ($baseline <= 0) {
                $overflowRatio = (float)$value;
            } else {
                $overflowRatio = max(0.0, ((float)$value - $baseline) / $baseline);
            }
        }

        return [
            'id' => $id,
            'label' => $label,
            'metric' => $metric,
            'unit' => (string)($threshold['unit'] ?? ''),
            'value' => $value,
            'warnThreshold' => $warnThreshold,
            'criticalThreshold' => $criticalThreshold,
            'comparator' => $comparator,
            'severity' => $severity,
            'overflowRatio' => round($overflowRatio, 4),
            'primaryResponse' => (string)($threshold['primaryResponse'] ?? ''),
        ];
    }

    private function matchesThreshold(int|float $value, float $threshold, string $comparator): bool
    {
        if ($comparator === '>=') {
            return (float)$value >= $threshold;
        }

        return (float)$value > $threshold;
    }

    /**
     * @param array<int, array<string, mixed>> $metrics
     * @return array<string, int|float>
     */
    private function metricValuesByName(array $metrics): array
    {
        $values = [];
        foreach ($metrics as $metric) {
            if (!is_array($metric)) {
                continue;
            }

            $name = trim((string)($metric['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $value = $metric['value'] ?? 0;
            if (is_int($value) || is_float($value)) {
                $values[$name] = $value;
                continue;
            }

            if (is_string($value) && is_numeric($value)) {
                $values[$name] = str_contains($value, '.') ? (float)$value : (int)$value;
            }
        }

        return $values;
    }

    private function severityRank(string $severity): int
    {
        return match (strtolower(trim($severity))) {
            'critical' => 3,
            'warn' => 2,
            default => 1,
        };
    }
}
