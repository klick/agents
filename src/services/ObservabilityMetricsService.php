<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use yii\caching\CacheInterface;
use yii\db\Query;

class ObservabilityMetricsService extends Component
{
    public const COUNTER_AUTH_FAILURES = 'agents:metrics:counter:auth_failures_total';
    public const COUNTER_FORBIDDEN = 'agents:metrics:counter:forbidden_total';
    public const COUNTER_RATE_LIMIT = 'agents:metrics:counter:rate_limit_exceeded_total';
    public const COUNTER_REQUESTS = 'agents:metrics:counter:requests_total';
    public const COUNTER_ERRORS_5XX = 'agents:metrics:counter:errors_5xx_total';

    private const TABLE_WEBHOOK_DLQ = '{{%agents_webhook_dlq}}';
    private const TABLE_QUEUE = '{{%queue}}';

    public function getMetricsSnapshot(): array
    {
        $plugin = \Klick\Agents\Plugin::getInstance();
        if ($plugin === null) {
            return [
                'service' => 'agents',
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'format' => 'json-metric-series',
                'metrics' => [],
                'meta' => [
                    'counterBackend' => 'cache',
                    'counterResetBehavior' => 'cache-clear-or-expiry',
                ],
            ];
        }

        $securityConfig = $plugin->getSecurityPolicyService()->getRuntimeConfig();
        $securityPosture = $plugin->getSecurityPolicyService()->getCpPosture();
        $controlSummary = $plugin->getControlPlaneService()->getSummary();
        $consumerSummary = $plugin->getConsumerLagService()->getLagSummary()['summary'] ?? [];
        $adoptionSnapshot = $plugin->getAdoptionMetricsService()->getSnapshot((array)($securityConfig['tokenScopes'] ?? []));
        $webhookSummary = $this->getWebhookDlqSummary();
        $queueSummary = $this->getQueueSummary();
        $counters = $this->readCounters();

        $metrics = [];
        $this->addMetric($metrics, 'agents_auth_failures_total', 'counter', (int)($counters['auth_failures_total'] ?? 0), 'requests', 'Rejected auth requests due to missing/invalid credentials.');
        $this->addMetric($metrics, 'agents_forbidden_total', 'counter', (int)($counters['forbidden_total'] ?? 0), 'requests', 'Rejected requests due to missing required scope.');
        $this->addMetric($metrics, 'agents_rate_limit_exceeded_total', 'counter', (int)($counters['rate_limit_exceeded_total'] ?? 0), 'requests', 'Requests rejected by rate-limiter.');
        $this->addMetric($metrics, 'agents_requests_total', 'counter', (int)($counters['requests_total'] ?? 0), 'requests', 'Guarded API requests observed by runtime guard.');
        $this->addMetric($metrics, 'agents_errors_5xx_total', 'counter', (int)($counters['errors_5xx_total'] ?? 0), 'responses', 'Responses returned with 5xx status code.');

        $this->addMetric($metrics, 'agents_rate_limit_per_minute', 'gauge', (int)($securityConfig['rateLimitPerMinute'] ?? 0), 'requests_per_minute', 'Configured request limit per minute.');
        $this->addMetric($metrics, 'agents_rate_limit_window_seconds', 'gauge', (int)($securityConfig['rateLimitWindowSeconds'] ?? 0), 'seconds', 'Configured rate-limit window.');

        $this->addMetric($metrics, 'agents_webhook_dlq_failed', 'gauge', (int)($webhookSummary['failed'] ?? 0), 'events', 'Dead-letter webhook events currently failed.');
        $this->addMetric($metrics, 'agents_webhook_dlq_queued', 'gauge', (int)($webhookSummary['queued'] ?? 0), 'events', 'Dead-letter webhook events queued for replay.');
        $this->addMetric($metrics, 'agents_webhook_retries_total', 'counter', (int)($webhookSummary['retryAttempts'] ?? 0), 'attempts', 'Accumulated webhook retry attempts derived from DLQ attempts.');

        $this->addMetric($metrics, 'agents_queue_depth', 'gauge', (int)($queueSummary['totalJobs'] ?? 0), 'jobs', 'Total queue depth from Craft queue table.');
        $this->addMetric($metrics, 'agents_queue_webhook_depth', 'gauge', (int)($queueSummary['webhookJobs'] ?? 0), 'jobs', 'Queued webhook delivery jobs.');

        $this->addMetric($metrics, 'agents_consumer_checkpoints_total', 'gauge', (int)($consumerSummary['count'] ?? 0), 'checkpoints', 'Tracked consumer checkpoints.');
        $this->addMetric($metrics, 'agents_consumer_lag_max_seconds', 'gauge', (int)($consumerSummary['maxLagSeconds'] ?? 0), 'seconds', 'Max consumer lag across integrations/resources.');
        $this->addMetric($metrics, 'agents_consumer_lag_warning_total', 'gauge', (int)($consumerSummary['warning'] ?? 0), 'checkpoints', 'Consumer checkpoints in warning lag bucket.');
        $this->addMetric($metrics, 'agents_consumer_lag_critical_total', 'gauge', (int)($consumerSummary['critical'] ?? 0), 'checkpoints', 'Consumer checkpoints in critical lag bucket.');

        $this->addMetric($metrics, 'agents_control_approvals_pending', 'gauge', (int)($controlSummary['approvalsPending'] ?? 0), 'approvals', 'Pending control approvals.');
        $this->addMetric($metrics, 'agents_control_approvals_expired', 'gauge', (int)($controlSummary['approvalsExpired'] ?? 0), 'approvals', 'Expired control approvals.');
        $this->addMetric($metrics, 'agents_control_executions_blocked', 'gauge', (int)($controlSummary['executionsBlocked'] ?? 0), 'executions', 'Blocked control executions.');
        $this->addMetric($metrics, 'agents_control_executions_succeeded', 'gauge', (int)($controlSummary['executionsSucceeded'] ?? 0), 'executions', 'Succeeded control executions.');
        $this->addMetric($metrics, 'agents_control_audit_events_total', 'gauge', (int)($controlSummary['auditEvents'] ?? 0), 'events', 'Control audit events stored.');

        $authPosture = (array)($securityPosture['authentication'] ?? []);
        $this->addMetric($metrics, 'agents_credentials_configured_total', 'gauge', (int)($authPosture['credentialCount'] ?? 0), 'credentials', 'Configured credentials (env + managed).');
        $this->addMetric($metrics, 'agents_credentials_managed_total', 'gauge', (int)($authPosture['managedCredentialCount'] ?? 0), 'credentials', 'Managed credentials stored in CP.');
        $this->addMetric(
            $metrics,
            'agents_adoption_time_to_first_success_median_seconds',
            'gauge',
            (int)($adoptionSnapshot['timeToFirstSuccess']['medianSeconds'] ?? 0),
            'seconds',
            'Median time to first successful managed credential use.'
        );
        $this->addMetric(
            $metrics,
            'agents_adoption_active_credentials_7d',
            'gauge',
            (int)($adoptionSnapshot['usage']['windowDays7']['activeCredentials'] ?? 0),
            'credentials',
            'Managed credentials active in last 7 days.'
        );

        $reliability = $plugin->getReliabilitySignalService()->evaluateMetricSeries($metrics, [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return [
            'service' => 'agents',
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'format' => 'json-metric-series',
            'metrics' => $metrics,
            'reliability' => $reliability,
            'meta' => [
                'counterBackend' => 'cache',
                'counterResetBehavior' => 'cache-clear-or-expiry',
            ],
        ];
    }

    private function getWebhookDlqSummary(): array
    {
        if (!$this->tableExists(self::TABLE_WEBHOOK_DLQ)) {
            return [
                'failed' => 0,
                'queued' => 0,
                'retryAttempts' => 0,
            ];
        }

        $rows = (new Query())
            ->select(['status', 'attempts'])
            ->from(self::TABLE_WEBHOOK_DLQ)
            ->all();

        $failed = 0;
        $queued = 0;
        $retryAttempts = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $status = strtolower(trim((string)($row['status'] ?? 'failed')));
            if ($status === 'queued') {
                $queued++;
            } else {
                $failed++;
            }

            $attempts = max(0, (int)($row['attempts'] ?? 0));
            if ($attempts > 1) {
                $retryAttempts += ($attempts - 1);
            }
        }

        return [
            'failed' => $failed,
            'queued' => $queued,
            'retryAttempts' => $retryAttempts,
        ];
    }

    private function getQueueSummary(): array
    {
        if (!$this->tableExists(self::TABLE_QUEUE)) {
            return [
                'totalJobs' => 0,
                'webhookJobs' => 0,
            ];
        }

        $totalJobs = (int)((new Query())
            ->from(self::TABLE_QUEUE)
            ->count('*'));

        $webhookJobs = (int)((new Query())
            ->from(self::TABLE_QUEUE)
            ->where(['like', 'job', 'DeliverWebhookJob'])
            ->count('*'));

        return [
            'totalJobs' => $totalJobs,
            'webhookJobs' => $webhookJobs,
        ];
    }

    private function readCounters(): array
    {
        $values = [
            'auth_failures_total' => 0,
            'forbidden_total' => 0,
            'rate_limit_exceeded_total' => 0,
            'requests_total' => 0,
            'errors_5xx_total' => 0,
        ];

        $cache = Craft::$app->getCache();
        if (!$cache instanceof CacheInterface) {
            return $values;
        }

        $counterMap = [
            'auth_failures_total' => self::COUNTER_AUTH_FAILURES,
            'forbidden_total' => self::COUNTER_FORBIDDEN,
            'rate_limit_exceeded_total' => self::COUNTER_RATE_LIMIT,
            'requests_total' => self::COUNTER_REQUESTS,
            'errors_5xx_total' => self::COUNTER_ERRORS_5XX,
        ];

        foreach ($counterMap as $target => $cacheKey) {
            $raw = $cache->get($cacheKey);
            if ($raw === false || $raw === null) {
                continue;
            }
            $values[$target] = max(0, (int)$raw);
        }

        return $values;
    }

    private function addMetric(array &$metrics, string $name, string $type, int|float $value, string $unit, string $description, array $labels = []): void
    {
        $metrics[] = [
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'unit' => $unit,
            'description' => $description,
            'labels' => $labels,
        ];
    }

    private function tableExists(string $table): bool
    {
        try {
            return Craft::$app->getDb()->getTableSchema($table, true) !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
