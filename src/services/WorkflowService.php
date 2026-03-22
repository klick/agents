<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use yii\db\Query;
use Klick\Agents\Plugin;

class WorkflowService extends Component
{
    private const TABLE_WORKFLOWS = '{{%agents_workflows}}';
    private const TABLE_WORKFLOW_RUNS = '{{%agents_workflow_runs}}';
    private const TABLE_CREDENTIALS = '{{%agents_credentials}}';
    private const TEMPLATE_CONTENT_QUALITY_REVIEW = 'content-quality-review';
    private const TEMPLATE_LEGAL_CONSENT_REVIEW = 'legal-consent-review';
    private const TEMPLATE_CHANGE_MONITOR = 'change-monitor';
    private const TEMPLATE_LAUNCH_READINESS_REVIEW = 'launch-readiness-review';
    private const TEMPLATE_CATALOG_QUALITY_REVIEW = 'catalog-quality-review';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_PAUSED = 'paused';
    private const STATUS_DRAFT = 'draft';
    private const STATUS_ERROR = 'error';
    private const RUNTIME_BASELINE_SCOPES = [
        'auth:read',
        'health:read',
        'readiness:read',
        'capabilities:read',
        'openapi:read',
    ];

    public function getWorkflowTemplates(): array
    {
        $templates = [
            [
                'handle' => self::TEMPLATE_CONTENT_QUALITY_REVIEW,
                'displayName' => 'Content Quality Review',
                'description' => 'Review entries, assets, and taxonomy on a recurring schedule and surface content-quality issues for operators.',
                'requiredScopes' => [
                    'workflows:read',
                    'workflows:report',
                    'entries:read',
                    'entries:read_all_statuses',
                    'assets:read',
                    'sections:read',
                    'categories:read',
                    'tags:read',
                ],
                'recommendedOptionalScopes' => [
                    'auth:read',
                    'capabilities:read',
                    'openapi:read',
                ],
                'mode' => 'read-only',
                'focusLabel' => 'Entries, assets, taxonomy',
                'defaultCadence' => 'weekly',
                'defaultWeekday' => 2,
                'defaultTimeOfDay' => '08:00',
                'defaultTimezone' => 'Europe/Berlin',
                'supportsTargetSet' => false,
                'docsUrl' => 'https://marcusscheller.com/docs/agents/workflows',
            ],
            [
                'handle' => self::TEMPLATE_LEGAL_CONSENT_REVIEW,
                'displayName' => 'Legal & Consent Review',
                'description' => 'Review legal texts, consent-related content, and disclosure surfaces without changing site content.',
                'requiredScopes' => [
                    'workflows:read',
                    'workflows:report',
                    'entries:read',
                    'global-sets:read',
                    'assets:read',
                    'sections:read',
                ],
                'recommendedOptionalScopes' => [
                    'capabilities:read',
                    'openapi:read',
                ],
                'mode' => 'read-only',
                'focusLabel' => 'Legal texts, consent surfaces',
                'defaultCadence' => 'weekly',
                'defaultWeekday' => 3,
                'defaultTimeOfDay' => '09:00',
                'defaultTimezone' => 'Europe/Berlin',
                'supportsTargetSet' => false,
                'docsUrl' => 'https://marcusscheller.com/docs/agents/workflows',
            ],
            [
                'handle' => self::TEMPLATE_CHANGE_MONITOR,
                'displayName' => 'Change Monitor',
                'description' => 'Track content and asset changes on a recurring schedule and summarize what changed for operators.',
                'requiredScopes' => [
                    'workflows:read',
                    'workflows:report',
                    'changes:read',
                    'entries:read',
                    'assets:read',
                    'sections:read',
                ],
                'recommendedOptionalScopes' => [
                    'capabilities:read',
                    'openapi:read',
                ],
                'mode' => 'read-only',
                'focusLabel' => 'Change feed, entries, assets',
                'defaultCadence' => 'weekly',
                'defaultWeekday' => 1,
                'defaultTimeOfDay' => '08:30',
                'defaultTimezone' => 'Europe/Berlin',
                'supportsTargetSet' => false,
                'docsUrl' => 'https://marcusscheller.com/docs/agents/workflows',
            ],
            [
                'handle' => self::TEMPLATE_LAUNCH_READINESS_REVIEW,
                'displayName' => 'Launch Readiness Review',
                'description' => 'Run a recurring readiness review across content, assets, and structure before launches or major updates.',
                'requiredScopes' => [
                    'workflows:read',
                    'workflows:report',
                    'entries:read',
                    'entries:read_all_statuses',
                    'assets:read',
                    'sections:read',
                    'categories:read',
                    'tags:read',
                ],
                'recommendedOptionalScopes' => [
                    'capabilities:read',
                    'openapi:read',
                ],
                'mode' => 'read-only',
                'focusLabel' => 'Launch-facing content and assets',
                'defaultCadence' => 'weekly',
                'defaultWeekday' => 4,
                'defaultTimeOfDay' => '09:00',
                'defaultTimezone' => 'Europe/Berlin',
                'supportsTargetSet' => false,
                'docsUrl' => 'https://marcusscheller.com/docs/agents/workflows',
            ],
        ];

        if (Plugin::getInstance()->isCommercePluginEnabled()) {
            $templates[] = [
                'handle' => self::TEMPLATE_CATALOG_QUALITY_REVIEW,
                'displayName' => 'Catalog Quality Review',
                'description' => 'Review catalog data, merchandising content, and commerce-facing metadata on a recurring schedule.',
                'requiredScopes' => [
                    'workflows:read',
                    'workflows:report',
                    'products:read',
                    'variants:read',
                    'orders:read',
                    'entries:read',
                    'sections:read',
                ],
                'recommendedOptionalScopes' => [
                    'capabilities:read',
                    'openapi:read',
                ],
                'mode' => 'read-only',
                'focusLabel' => 'Products, variants, supporting content',
                'defaultCadence' => 'weekly',
                'defaultWeekday' => 2,
                'defaultTimeOfDay' => '08:30',
                'defaultTimezone' => 'Europe/Berlin',
                'supportsTargetSet' => false,
                'docsUrl' => 'https://marcusscheller.com/docs/agents/workflows',
            ];
        }

        return $templates;
    }

    public function getWorkflowTemplateByHandle(string $handle): ?array
    {
        $normalizedHandle = strtolower(trim($handle));
        foreach ($this->getWorkflowTemplates() as $template) {
            if ($normalizedHandle === strtolower((string)($template['handle'] ?? ''))) {
                return $template;
            }
        }

        return null;
    }

    public function getWorkflows(): array
    {
        if (!$this->workflowsTableExists()) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE_WORKFLOWS)
            ->orderBy(['name' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $this->hydrateWorkflows($rows);
    }

    public function getWorkflowById(int $workflowId): ?array
    {
        if ($workflowId <= 0 || !$this->workflowsTableExists()) {
            return null;
        }

        $row = (new Query())
            ->from(self::TABLE_WORKFLOWS)
            ->where(['id' => $workflowId])
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return $this->hydrateWorkflows([$row])[0] ?? null;
    }

    public function getWorkflowRuns(int $workflowId, int $limit = 10): array
    {
        if ($workflowId <= 0 || !$this->workflowRunsTableExists()) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE_WORKFLOW_RUNS)
            ->where(['workflowId' => $workflowId])
            ->orderBy(['id' => SORT_DESC])
            ->limit(max(1, $limit))
            ->all();

        return array_map(fn(array $row): array => $this->hydrateRun($row), $rows);
    }

    public function getRuntimeWorkflowsForCredential(int $credentialId, bool $dueOnly = true, int $limit = 20): array
    {
        if ($credentialId <= 0 || !$this->workflowsTableExists()) {
            return [];
        }

        $workflows = [];
        foreach ($this->getWorkflows() as $workflow) {
            if ((int)($workflow['accountId'] ?? 0) !== $credentialId) {
                continue;
            }

            if (strtolower(trim((string)($workflow['status'] ?? ''))) !== self::STATUS_ACTIVE) {
                continue;
            }

            $runtimePayload = $this->buildRuntimeWorkflowPayload($workflow);
            if ($dueOnly && !(bool)($runtimePayload['schedule']['isDue'] ?? false)) {
                continue;
            }

            $workflows[] = $runtimePayload;
            if (count($workflows) >= max(1, $limit)) {
                break;
            }
        }

        return $workflows;
    }

    public function getRuntimeWorkflowById(int $workflowId, int $credentialId): ?array
    {
        $workflow = $this->getWorkflowById($workflowId);
        if (!is_array($workflow)) {
            return null;
        }

        if ((int)($workflow['accountId'] ?? 0) !== $credentialId) {
            return null;
        }

        if (strtolower(trim((string)($workflow['status'] ?? ''))) !== self::STATUS_ACTIVE) {
            return null;
        }

        return $this->buildRuntimeWorkflowPayload($workflow);
    }

    public function reportWorkflowRun(int $credentialId, array $payload): array
    {
        if ($credentialId <= 0 || !$this->workflowRunsTableExists()) {
            throw new RuntimeException('Workflow run storage is unavailable. Run plugin migrations.');
        }

        $normalized = $this->normalizeWorkflowRunPayload($payload);
        $workflow = $this->getWorkflowById((int)$normalized['workflowId']);
        if (!is_array($workflow)) {
            throw new InvalidArgumentException('Workflow not found.');
        }

        if ((int)($workflow['accountId'] ?? 0) !== $credentialId) {
            throw new InvalidArgumentException('This managed account is not bound to the requested workflow.');
        }

        if (strtolower(trim((string)($workflow['status'] ?? ''))) !== self::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only active workflows can accept run reports.');
        }

        $db = Craft::$app->getDb();
        $now = gmdate('Y-m-d H:i:s');
        $existingRun = null;
        $runId = (int)($normalized['runId'] ?? 0);
        if ($runId > 0) {
            $existingRun = (new Query())
                ->from(self::TABLE_WORKFLOW_RUNS)
                ->where([
                    'id' => $runId,
                    'workflowId' => (int)$normalized['workflowId'],
                ])
                ->one();
            if (!is_array($existingRun)) {
                throw new InvalidArgumentException('Workflow run not found.');
            }
        }

        $status = (string)$normalized['status'];
        $workerId = $normalized['workerId'] !== ''
            ? $normalized['workerId']
            : (string)($workflow['account']['handle'] ?? $workflow['accountDisplayName'] ?? ('workflow-' . $credentialId));
        $heartbeatAt = $normalized['heartbeatAt'] ?? $now;
        $startedAt = $normalized['startedAt']
            ?? ($status === 'started' ? $heartbeatAt : null)
            ?? ($existingRun['startedAt'] ?? null);
        $completedAt = $normalized['completedAt']
            ?? ($this->runStatusIsTerminal($status) ? $heartbeatAt : null)
            ?? ($existingRun['completedAt'] ?? null);
        $scheduledFor = $normalized['scheduledFor']
            ?? ($existingRun['scheduledFor'] ?? null)
            ?? $this->resolveLatestScheduledOccurrenceUtc($workflow)
            ?? $heartbeatAt;
        $claimedAt = $normalized['claimedAt']
            ?? ($status === 'started' ? $heartbeatAt : null)
            ?? ($existingRun['claimedAt'] ?? null);

        $runRow = [
            'workflowId' => (int)$normalized['workflowId'],
            'status' => $status,
            'scheduledFor' => $scheduledFor,
            'claimedAt' => $claimedAt,
            'startedAt' => $startedAt,
            'completedAt' => $completedAt,
            'workerId' => $workerId,
            'summary' => $normalized['summary'] !== '' ? $normalized['summary'] : null,
            'logExcerpt' => $normalized['logExcerpt'] !== '' ? $normalized['logExcerpt'] : null,
            'approvalIdsJson' => Json::encode($normalized['approvalIds']),
            'outcomeRefsJson' => Json::encode($normalized['outcomeRefs']),
            'metadataJson' => Json::encode($normalized['metadata']),
            'dateUpdated' => $now,
        ];

        if (is_array($existingRun)) {
            $db->createCommand()->update(self::TABLE_WORKFLOW_RUNS, $runRow, ['id' => $runId])->execute();
        } else {
            $runRow['dateCreated'] = $now;
            $runRow['uid'] = StringHelper::UUID();
            $db->createCommand()->insert(self::TABLE_WORKFLOW_RUNS, $runRow)->execute();
            $runId = (int)$db->getLastInsertID();
        }

        $workflowUpdate = [
            'lastWorkerId' => $workerId,
            'lastHeartbeatAt' => $heartbeatAt,
            'dateUpdated' => $now,
        ];
        if ($status === 'started') {
            $workflowUpdate['lastClaimedAt'] = $claimedAt ?? $heartbeatAt;
        }
        $db->createCommand()->update(self::TABLE_WORKFLOWS, $workflowUpdate, ['id' => (int)$normalized['workflowId']])->execute();

        $reportedRun = (new Query())
            ->from(self::TABLE_WORKFLOW_RUNS)
            ->where(['id' => $runId])
            ->one();

        if (!is_array($reportedRun)) {
            throw new RuntimeException('Unable to load workflow run after report.');
        }

        return $this->hydrateRun($reportedRun);
    }

    public function createWorkflow(array $payload): array
    {
        if (!$this->workflowsTableExists()) {
            throw new RuntimeException('Workflow storage table is unavailable. Run plugin migrations.');
        }

        $normalized = $this->normalizeWorkflowPayload($payload);
        $now = gmdate('Y-m-d H:i:s');

        Craft::$app->getDb()->createCommand()->insert(self::TABLE_WORKFLOWS, [
            'templateHandle' => $normalized['templateHandle'],
            'name' => $normalized['name'],
            'description' => $normalized['description'] !== '' ? $normalized['description'] : null,
            'status' => $normalized['status'],
            'cadence' => $normalized['cadence'],
            'weekday' => $normalized['weekday'],
            'timeOfDay' => $normalized['timeOfDay'],
            'timezone' => $normalized['timezone'],
            'accountId' => $normalized['accountId'],
            'targetSetId' => $normalized['targetSetId'],
            'ownerUserId' => $normalized['ownerUserId'],
            'configJson' => Json::encode($normalized['config']),
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return $this->getWorkflowById((int)Craft::$app->getDb()->getLastInsertID())
            ?? throw new RuntimeException('Unable to load newly created workflow.');
    }

    public function updateWorkflow(int $workflowId, array $payload): ?array
    {
        if ($workflowId <= 0 || !$this->workflowsTableExists()) {
            return null;
        }

        $existing = (new Query())
            ->from(self::TABLE_WORKFLOWS)
            ->where(['id' => $workflowId])
            ->one();
        if (!is_array($existing)) {
            return null;
        }

        $normalized = $this->normalizeWorkflowPayload($payload, $existing);

        Craft::$app->getDb()->createCommand()->update(self::TABLE_WORKFLOWS, [
            'templateHandle' => $normalized['templateHandle'],
            'name' => $normalized['name'],
            'description' => $normalized['description'] !== '' ? $normalized['description'] : null,
            'status' => $normalized['status'],
            'cadence' => $normalized['cadence'],
            'weekday' => $normalized['weekday'],
            'timeOfDay' => $normalized['timeOfDay'],
            'timezone' => $normalized['timezone'],
            'accountId' => $normalized['accountId'],
            'targetSetId' => $normalized['targetSetId'],
            'ownerUserId' => $normalized['ownerUserId'],
            'configJson' => Json::encode($normalized['config']),
            'dateUpdated' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $workflowId])->execute();

        return $this->getWorkflowById($workflowId);
    }

    public function pauseWorkflow(int $workflowId): bool
    {
        return $this->setWorkflowStatus($workflowId, self::STATUS_PAUSED);
    }

    public function resumeWorkflow(int $workflowId): bool
    {
        return $this->setWorkflowStatus($workflowId, self::STATUS_ACTIVE);
    }

    public function duplicateWorkflow(int $workflowId): ?array
    {
        $workflow = $this->getWorkflowById($workflowId);
        if ($workflow === null) {
            return null;
        }

        return $this->createWorkflow([
            'templateHandle' => (string)($workflow['templateHandle'] ?? ''),
            'name' => trim((string)($workflow['name'] ?? 'Workflow')) . ' Copy',
            'description' => (string)($workflow['description'] ?? ''),
            'status' => self::STATUS_DRAFT,
            'cadence' => (string)($workflow['cadence'] ?? 'weekly'),
            'weekday' => (int)($workflow['weekday'] ?? 4),
            'timeOfDay' => (string)($workflow['timeOfDay'] ?? '08:00'),
            'timezone' => (string)($workflow['timezone'] ?? 'Europe/Berlin'),
            'accountId' => (int)($workflow['accountId'] ?? 0),
            'targetSetId' => $workflow['targetSetId'] ?? null,
            'ownerUserId' => (int)($workflow['ownerUserId'] ?? 0),
            'entryIds' => (array)($workflow['config']['entryIds'] ?? []),
            'productIds' => (array)($workflow['config']['productIds'] ?? []),
            'sectionHandles' => (array)($workflow['config']['sectionHandles'] ?? []),
            'siteIds' => (array)($workflow['config']['siteIds'] ?? []),
            'promptContext' => (string)($workflow['config']['promptContext'] ?? ''),
            'operatorNotes' => (string)($workflow['config']['operatorNotes'] ?? ''),
        ]);
    }

    public function deleteWorkflow(int $workflowId): bool
    {
        if ($workflowId <= 0 || !$this->workflowsTableExists()) {
            return false;
        }

        return Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE_WORKFLOWS, ['id' => $workflowId])
            ->execute() > 0;
    }

    public function buildBootstrapBundleFiles(array $workflow): array
    {
        $template = (array)($workflow['template'] ?? []);
        $config = (array)($workflow['config'] ?? []);
        $workflowName = trim((string)($workflow['name'] ?? 'Managed Workflow'));
        $workflowSlug = StringHelper::toKebabCase($workflowName) ?: 'managed-workflow';
        $cronMinute = '15';
        $cronHour = '8';
        $cronWeekday = '4';
        $timeOfDay = trim((string)($workflow['timeOfDay'] ?? ''));
        if (preg_match('/^(?<hour>\d{2}):(?<minute>\d{2})$/', $timeOfDay, $matches) === 1) {
            $cronHour = (string)(int)($matches['hour'] ?? 8);
            $cronMinute = (string)(int)($matches['minute'] ?? 15);
        }
        $weekday = (int)($workflow['weekday'] ?? 4);
        if ($weekday >= 1 && $weekday <= 7) {
            $cronWeekday = (string)($weekday === 7 ? 0 : $weekday);
        }
        $bundleFiles = [];

        $bundleFiles['README.md'] = $this->buildBundleReadme($workflow, $template, $workflowSlug);
        $bundleFiles['.env.example'] = $this->buildBundleEnvExample($workflow);
        $bundleFiles['config.example.json'] = Json::encode([
            'workflowId' => (int)($workflow['id'] ?? 0),
            'workflowName' => $workflowName,
            'templateHandle' => (string)($workflow['templateHandle'] ?? ''),
            'schedule' => [
                'cadence' => (string)($workflow['cadence'] ?? ''),
                'weekday' => (int)($workflow['weekday'] ?? 0),
                'timeOfDay' => (string)($workflow['timeOfDay'] ?? ''),
            ],
            'config' => [
                'entryIds' => array_values(array_map('intval', (array)($config['entryIds'] ?? []))),
                'productIds' => array_values(array_map('intval', (array)($config['productIds'] ?? []))),
                'sectionHandles' => array_values(array_map('strval', (array)($config['sectionHandles'] ?? []))),
                'siteIds' => array_values(array_map('intval', (array)($config['siteIds'] ?? []))),
                'promptContext' => (string)($config['promptContext'] ?? ''),
                'operatorNotes' => (string)($config['operatorNotes'] ?? ''),
                'outputRoot' => '/absolute/path/to/agents/workflows/' . $workflowSlug,
                'statePath' => '/absolute/path/to/agents/workflows/' . $workflowSlug . '/state/state.json',
                'reportPath' => '/absolute/path/to/agents/workflows/' . $workflowSlug . '/reports/latest.md',
                'rawArchiveDir' => '/absolute/path/to/agents/workflows/' . $workflowSlug . '/raw',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $bundleFiles['worker.mjs'] = $this->buildBundleWorkerScript($workflow, $template);
        $bundleFiles['run-worker.sh'] = $this->buildBundleRunScript($workflowSlug);
        $bundleFiles['output-contract.md'] = $this->buildOutputContract($workflow, $workflowSlug);

        $bundleFiles['cron.example'] = sprintf(
            "# Example cron entry for %s\n%s %s * * %s /bin/bash /absolute/path/to/%s/run-worker.sh >> /absolute/path/to/%s/worker.log 2>&1\n",
            $workflowName,
            $cronMinute,
            $cronHour,
            $cronWeekday,
            $workflowSlug,
            $workflowSlug
        );

        return $bundleFiles;
    }

    public function getWorkflowStatusSummary(): array
    {
        $summary = [
            'total' => 0,
            'active' => 0,
            'paused' => 0,
            'attention' => 0,
            'broadAccounts' => 0,
        ];

        foreach ($this->getWorkflows() as $workflow) {
            $summary['total']++;
            $status = strtolower(trim((string)($workflow['status'] ?? '')));
            if ($status === self::STATUS_ACTIVE) {
                $summary['active']++;
            } elseif ($status === self::STATUS_PAUSED) {
                $summary['paused']++;
            }

            if ((bool)($workflow['needsAttention'] ?? false)) {
                $summary['attention']++;
            }
            if ((bool)($workflow['accountScopeCoverage']['isBroader'] ?? false)) {
                $summary['broadAccounts']++;
            }
        }

        return $summary;
    }

    public function getAccountScopeCoverageByCredentialId(): array
    {
        $coverageByCredentialId = [];
        foreach ($this->getWorkflows() as $workflow) {
            if (!is_array($workflow)) {
                continue;
            }

            $credentialId = (int)($workflow['accountId'] ?? 0);
            if ($credentialId <= 0) {
                continue;
            }

            $workflowCoverage = (array)($workflow['accountScopeCoverage'] ?? []);
            if (!isset($coverageByCredentialId[$credentialId])) {
                $coverageByCredentialId[$credentialId] = [
                    'credentialId' => $credentialId,
                    'workflowCount' => 0,
                    'workflowNames' => [],
                    'extraScopes' => [],
                    'isBroader' => false,
                    'summary' => 'Aligned with attached workflows',
                    'meta' => '',
                ];
            }

            $coverageByCredentialId[$credentialId]['workflowCount']++;
            $workflowName = trim((string)($workflow['name'] ?? ''));
            if ($workflowName !== '' && !in_array($workflowName, $coverageByCredentialId[$credentialId]['workflowNames'], true)) {
                $coverageByCredentialId[$credentialId]['workflowNames'][] = $workflowName;
            }

            foreach ((array)($workflowCoverage['extraScopes'] ?? []) as $extraScope) {
                $normalizedExtraScope = trim((string)$extraScope);
                if ($normalizedExtraScope === '' || in_array($normalizedExtraScope, $coverageByCredentialId[$credentialId]['extraScopes'], true)) {
                    continue;
                }
                $coverageByCredentialId[$credentialId]['extraScopes'][] = $normalizedExtraScope;
            }

            if ((bool)($workflowCoverage['isBroader'] ?? false)) {
                $coverageByCredentialId[$credentialId]['isBroader'] = true;
            }
        }

        foreach ($coverageByCredentialId as $credentialId => $coverage) {
            $extraScopes = array_values(array_map('strval', (array)($coverage['extraScopes'] ?? [])));
            if (!empty($extraScopes)) {
                $coverageByCredentialId[$credentialId]['summary'] = 'Broader than attached workflows';
                $coverageByCredentialId[$credentialId]['meta'] = $this->describeScopeList($extraScopes, 'extra scope');
            } else {
                $coverageByCredentialId[$credentialId]['summary'] = 'Aligned with attached workflows';
                $coverageByCredentialId[$credentialId]['meta'] = ((int)($coverage['workflowCount'] ?? 0) > 0)
                    ? ((int)$coverage['workflowCount'] . ' workflow' . ((int)$coverage['workflowCount'] === 1 ? '' : 's'))
                    : '';
            }
        }

        return $coverageByCredentialId;
    }

    private function hydrateWorkflows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $workflowIds = [];
        $accountIds = [];
        $targetSetIds = [];
        $ownerIds = [];
        foreach ($rows as $row) {
            $workflowIds[] = (int)($row['id'] ?? 0);
            $accountIds[] = (int)($row['accountId'] ?? 0);
            $targetSetId = (int)($row['targetSetId'] ?? 0);
            if ($targetSetId > 0) {
                $targetSetIds[] = $targetSetId;
            }
            $ownerIds[] = (int)($row['ownerUserId'] ?? 0);
        }

        $credentialsById = $this->getManagedCredentialsById($accountIds);
        $targetSetsById = $this->getTargetSetsById($targetSetIds);
        $ownersById = $this->getUsersById($ownerIds);
        $latestRunsByWorkflowId = $this->getLatestRunsByWorkflowId($workflowIds);

        $workflows = [];
        foreach ($rows as $row) {
            $workflowId = (int)($row['id'] ?? 0);
            $templateHandle = strtolower(trim((string)($row['templateHandle'] ?? '')));
            $template = $this->getWorkflowTemplateByHandle($templateHandle);
            $config = $this->decodeJsonObject((string)($row['configJson'] ?? ''));
            $accountId = (int)($row['accountId'] ?? 0);
            $targetSetId = (int)($row['targetSetId'] ?? 0);
            $ownerUserId = (int)($row['ownerUserId'] ?? 0);
            $latestRun = $latestRunsByWorkflowId[$workflowId] ?? null;
            $status = strtolower(trim((string)($row['status'] ?? self::STATUS_DRAFT)));
            $hasAccount = isset($credentialsById[$accountId]);
            $templateRequiresTargetSet = $this->templateRequiresTargetSet($template);
            $hasTargetSet = !$templateRequiresTargetSet || isset($targetSetsById[$targetSetId]);
            $latestRunStatus = strtolower(trim((string)($latestRun['status'] ?? '')));
            $accountScopeCoverage = $this->evaluateWorkflowAccountScopeCoverage($credentialsById[$accountId] ?? null, $template);
            $needsAttention = $status === self::STATUS_ERROR
                || !$hasAccount
                || !$hasTargetSet
                || in_array($latestRunStatus, ['blocked', 'failed', 'error'], true)
                || (bool)($accountScopeCoverage['isBroader'] ?? false);
            $attentionState = $this->buildWorkflowAttentionState(
                !$hasAccount,
                !$hasTargetSet,
                $status,
                $latestRunStatus,
                $latestRun,
                $accountScopeCoverage
            );

            $workflows[] = [
                'id' => $workflowId,
                'templateHandle' => $templateHandle,
                'template' => $template,
                'name' => (string)($row['name'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'status' => $status,
                'statusTone' => $this->statusToneForWorkflow($status, $needsAttention),
                'cadence' => (string)($row['cadence'] ?? 'weekly'),
                'weekday' => (int)($row['weekday'] ?? 0),
                'timeOfDay' => (string)($row['timeOfDay'] ?? ''),
                'timezone' => (string)($row['timezone'] ?? ''),
                'scheduleLabel' => $this->buildScheduleLabel($row),
                'nextDueLabel' => $this->buildNextDueLabel($row, $status),
                'accountId' => $accountId,
                'account' => $credentialsById[$accountId] ?? null,
                'accountDisplayName' => (string)(($credentialsById[$accountId]['displayName'] ?? $credentialsById[$accountId]['handle'] ?? 'Missing account')),
                'targetSetId' => $targetSetId > 0 ? $targetSetId : null,
                'targetSet' => ($templateRequiresTargetSet && $targetSetId > 0) ? ($targetSetsById[$targetSetId] ?? null) : null,
                'targetSetName' => $templateRequiresTargetSet
                    ? (string)(($targetSetsById[$targetSetId]['name'] ?? $targetSetsById[$targetSetId]['handle'] ?? 'Missing target set'))
                    : 'n/a',
                'ownerUserId' => $ownerUserId > 0 ? $ownerUserId : null,
                'ownerUser' => $ownerUserId > 0 ? ($ownersById[$ownerUserId] ?? null) : null,
                'ownerLabel' => $ownerUserId > 0 ? (string)($ownersById[$ownerUserId]['label'] ?? 'Unknown owner') : 'Unassigned',
                'config' => $config,
                'configSummary' => $this->buildConfigSummary($config),
                'readBoundary' => $this->buildReadBoundaryPayload($config),
                'accountScopeCoverage' => $accountScopeCoverage,
                'latestRun' => $latestRun,
                'needsAttention' => $needsAttention,
                'attentionLabels' => $attentionState['labels'],
                'attentionSummary' => $attentionState['summary'],
                'attentionMeta' => $attentionState['meta'],
                'lastWorkerId' => (string)($row['lastWorkerId'] ?? ''),
                'lastClaimedAt' => $this->normalizeDateTimeValue($row['lastClaimedAt'] ?? null),
                'lastHeartbeatAt' => $this->normalizeDateTimeValue($row['lastHeartbeatAt'] ?? null),
                'dateCreated' => $this->normalizeDateTimeValue($row['dateCreated'] ?? null),
                'dateUpdated' => $this->normalizeDateTimeValue($row['dateUpdated'] ?? null),
            ];
        }

        return $workflows;
    }

    private function buildWorkflowAttentionState(
        bool $missingAccount,
        bool $missingTargetSet,
        string $status,
        string $latestRunStatus,
        ?array $latestRun,
        array $accountScopeCoverage = []
    ): array {
        $labels = [];
        $detail = '';

        if ($missingAccount) {
            $labels[] = 'Missing account';
            $detail = 'Assign a matching managed account.';
        }

        if ($missingTargetSet) {
            $labels[] = 'Missing target set';
            if ($detail === '') {
                $detail = 'Select a required target set.';
            }
        }

        if ($status === self::STATUS_ERROR) {
            $labels[] = 'Workflow error';
        }

        if ((bool)($accountScopeCoverage['isBroader'] ?? false)) {
            $labels[] = 'Account broader than workflow';
            if ($detail === '') {
                $detail = $this->describeScopeList((array)($accountScopeCoverage['extraScopes'] ?? []), 'extra scope');
            }
        }

        if ($latestRunStatus === 'blocked') {
            $labels[] = 'Run blocked';
        } elseif (in_array($latestRunStatus, ['failed', 'error'], true)) {
            $labels[] = 'Run failed';
        }

        $labels = array_values(array_unique(array_filter($labels)));
        $summary = $labels[0] ?? 'Needs review';

        if ($detail === '') {
            $detail = $this->extractWorkflowAttentionDetail($latestRun);
        }

        if ($detail === '' && count($labels) > 1) {
            $detail = $labels[1];
        }

        return [
            'labels' => $labels,
            'summary' => $summary,
            'meta' => $detail,
        ];
    }

    private function extractWorkflowAttentionDetail(?array $latestRun): string
    {
        if (!is_array($latestRun)) {
            return '';
        }

        $candidates = [
            trim((string)($latestRun['summary'] ?? '')),
            trim((string)($latestRun['logExcerpt'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $candidate = preg_replace('/\s+/', ' ', $candidate) ?? $candidate;
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            if (mb_strlen($candidate) > 96) {
                return mb_substr($candidate, 0, 93) . '...';
            }

            return $candidate;
        }

        return '';
    }

    private function getManagedCredentialsById(array $credentialIds): array
    {
        $normalizedIds = $this->normalizeIntegerIds($credentialIds);
        if (empty($normalizedIds)) {
            return [];
        }

        $defaultScopes = (array)(Plugin::getInstance()->getSecurityPolicyService()->getCpPosture()['authentication']['tokenScopes'] ?? []);
        $credentialsById = [];
        foreach (Plugin::getInstance()->getCredentialService()->getManagedCredentials($defaultScopes) as $credential) {
            if (!is_array($credential)) {
                continue;
            }
            $credentialId = (int)($credential['id'] ?? 0);
            if ($credentialId > 0 && in_array($credentialId, $normalizedIds, true)) {
                $credentialsById[$credentialId] = $credential;
            }
        }

        return $credentialsById;
    }

    private function getTargetSetsById(array $targetSetIds): array
    {
        $normalizedIds = $this->normalizeIntegerIds($targetSetIds);
        if (empty($normalizedIds)) {
            return [];
        }

        $byId = [];
        foreach (Plugin::getInstance()->getTargetSetService()->getTargetSets() as $targetSet) {
            if (!is_array($targetSet)) {
                continue;
            }
            $targetSetId = (int)($targetSet['id'] ?? 0);
            if ($targetSetId > 0 && in_array($targetSetId, $normalizedIds, true)) {
                $byId[$targetSetId] = $targetSet;
            }
        }

        return $byId;
    }

    private function getUsersById(array $userIds): array
    {
        $normalizedIds = $this->normalizeIntegerIds($userIds);
        if (empty($normalizedIds)) {
            return [];
        }

        $users = User::find()->id($normalizedIds)->status(null)->all();
        $map = [];
        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }
            $map[(int)$user->id] = [
                'id' => (int)$user->id,
                'label' => trim((string)($user->fullName ?: $user->friendlyName ?: $user->username ?: $user->email)),
            ];
        }

        return $map;
    }

    private function getLatestRunsByWorkflowId(array $workflowIds): array
    {
        $normalizedIds = $this->normalizeIntegerIds($workflowIds);
        if (empty($normalizedIds) || !$this->workflowRunsTableExists()) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE_WORKFLOW_RUNS)
            ->where(['workflowId' => $normalizedIds])
            ->orderBy(['workflowId' => SORT_ASC, 'id' => SORT_DESC])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $workflowId = (int)($row['workflowId'] ?? 0);
            if ($workflowId <= 0 || isset($map[$workflowId])) {
                continue;
            }
            $map[$workflowId] = $this->hydrateRun($row);
        }

        return $map;
    }

    private function hydrateRun(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'workflowId' => (int)($row['workflowId'] ?? 0),
            'status' => strtolower(trim((string)($row['status'] ?? 'queued'))),
            'summary' => (string)($row['summary'] ?? ''),
            'logExcerpt' => (string)($row['logExcerpt'] ?? ''),
            'workerId' => (string)($row['workerId'] ?? ''),
            'scheduledFor' => $this->normalizeDateTimeValue($row['scheduledFor'] ?? null),
            'claimedAt' => $this->normalizeDateTimeValue($row['claimedAt'] ?? null),
            'startedAt' => $this->normalizeDateTimeValue($row['startedAt'] ?? null),
            'completedAt' => $this->normalizeDateTimeValue($row['completedAt'] ?? null),
            'approvalIds' => array_values(array_map('intval', $this->decodeJsonArray((string)($row['approvalIdsJson'] ?? '[]')))),
            'outcomeRefs' => array_values(array_map('strval', $this->decodeJsonArray((string)($row['outcomeRefsJson'] ?? '[]')))),
            'metadata' => $this->decodeJsonObject((string)($row['metadataJson'] ?? '{}')),
        ];
    }

    private function normalizeWorkflowPayload(array $payload, ?array $existing = null): array
    {
        $templateHandle = strtolower(trim((string)($payload['templateHandle'] ?? $existing['templateHandle'] ?? '')));
        $template = $this->getWorkflowTemplateByHandle($templateHandle);
        if ($template === null) {
            throw new InvalidArgumentException('Unknown workflow template.');
        }

        $name = trim((string)($payload['name'] ?? $existing['name'] ?? ''));
        if ($name === '') {
            $name = (string)$template['displayName'];
        }
        if ($name === '') {
            throw new InvalidArgumentException('Workflow name is required.');
        }

        $description = trim((string)($payload['description'] ?? $existing['description'] ?? ''));
        $status = strtolower(trim((string)($payload['status'] ?? $existing['status'] ?? self::STATUS_ACTIVE)));
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_DRAFT, self::STATUS_ERROR], true)) {
            throw new InvalidArgumentException('Unsupported workflow status.');
        }

        $cadence = strtolower(trim((string)($payload['cadence'] ?? $existing['cadence'] ?? (string)($template['defaultCadence'] ?? 'weekly'))));
        if (!in_array($cadence, ['weekly'], true)) {
            throw new InvalidArgumentException('Only weekly workflows are supported in this version.');
        }

        $weekday = (int)($payload['weekday'] ?? $existing['weekday'] ?? (int)($template['defaultWeekday'] ?? 4));
        if ($weekday < 1 || $weekday > 7) {
            throw new InvalidArgumentException('Weekday must be between 1 (Monday) and 7 (Sunday).');
        }

        $timeOfDay = trim((string)($payload['timeOfDay'] ?? $existing['timeOfDay'] ?? (string)($template['defaultTimeOfDay'] ?? '08:00')));
        if (!preg_match('/^\d{2}:\d{2}$/', $timeOfDay)) {
            throw new InvalidArgumentException('Time of day must use HH:MM.');
        }

        $timezone = $this->resolveWorkflowTimezone(
            $payload['timezone'] ?? null,
            $existing['timezone'] ?? null,
            $template
        );

        $accountId = (int)($payload['accountId'] ?? $existing['accountId'] ?? 0);
        $credential = $this->validateCredentialForTemplate($accountId, $template);

        $targetSetId = null;
        if ($this->templateRequiresTargetSet($template)) {
            $targetSetId = (int)($payload['targetSetId'] ?? $existing['targetSetId'] ?? 0);
            $this->validateTargetSetForCredential($targetSetId, $accountId);
        }

        $ownerUserId = (int)($payload['ownerUserId'] ?? $existing['ownerUserId'] ?? 0);
        $ownerUserId = $ownerUserId > 0 ? $ownerUserId : null;

        $sectionHandles = $this->validateSectionHandles((array)($payload['sectionHandles'] ?? $existing['config']['sectionHandles'] ?? []));
        $entryIds = $this->validateEntryIds((array)($payload['entryIds'] ?? $existing['config']['entryIds'] ?? []));
        $productIds = $this->validateProductIds((array)($payload['productIds'] ?? $existing['config']['productIds'] ?? []));
        $siteIds = $this->validateSiteIds((array)($payload['siteIds'] ?? $existing['config']['siteIds'] ?? []));
        $promptContext = trim((string)($payload['promptContext'] ?? $existing['config']['promptContext'] ?? ''));
        $operatorNotes = trim((string)($payload['operatorNotes'] ?? $existing['config']['operatorNotes'] ?? ''));

        return [
            'templateHandle' => $templateHandle,
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'cadence' => $cadence,
            'weekday' => $weekday,
            'timeOfDay' => $timeOfDay,
            'timezone' => $timezone,
            'accountId' => (int)($credential['id'] ?? $accountId),
            'targetSetId' => $targetSetId,
            'ownerUserId' => $ownerUserId,
            'config' => [
                'entryIds' => $entryIds,
                'productIds' => $productIds,
                'sectionHandles' => $sectionHandles,
                'siteIds' => $siteIds,
                'promptContext' => $promptContext,
                'operatorNotes' => $operatorNotes,
            ],
        ];
    }

    private function validateCredentialForTemplate(int $credentialId, array $template): array
    {
        if ($credentialId <= 0) {
            throw new InvalidArgumentException('Choose a managed account for this workflow.');
        }

        $credential = $this->getManagedCredentialsById([$credentialId])[$credentialId] ?? null;
        if (!is_array($credential)) {
            throw new InvalidArgumentException('Managed account not found.');
        }

        $scopes = array_map(static fn($scope): string => strtolower(trim((string)$scope)), (array)($credential['scopes'] ?? []));
        foreach ((array)($template['requiredScopes'] ?? []) as $requiredScope) {
            $normalizedRequiredScope = strtolower(trim((string)$requiredScope));
            $satisfied = in_array($normalizedRequiredScope, $scopes, true);
            if (!$satisfied && $normalizedRequiredScope === 'entries:write:draft') {
                $satisfied = in_array('entries:write', $scopes, true);
            }

            if (!$satisfied) {
                throw new InvalidArgumentException(sprintf(
                    'Managed account `%s` is missing required scope `%s`.',
                    (string)($credential['displayName'] ?? $credential['handle'] ?? $credentialId),
                    (string)$requiredScope
                ));
            }
        }

        return $credential;
    }

    private function validateTargetSetForCredential(int $targetSetId, int $credentialId): void
    {
        if ($targetSetId <= 0) {
            throw new InvalidArgumentException('Choose a target set for this workflow.');
        }

        $assignedTargetSets = Plugin::getInstance()->getTargetSetService()->getTargetSetsForCredentialId($credentialId);
        foreach ($assignedTargetSets as $targetSet) {
            if ((int)($targetSet['id'] ?? 0) === $targetSetId) {
                return;
            }
        }

        throw new InvalidArgumentException('The selected target set is not assigned to the chosen managed account.');
    }

    private function validateSectionHandles(array $sectionHandles): array
    {
        $normalized = [];
        foreach ($sectionHandles as $sectionHandle) {
            $value = strtolower(trim((string)$sectionHandle));
            if ($value !== '' && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        if (empty($normalized)) {
            return [];
        }

        $knownHandles = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $handle = strtolower(trim((string)($section->handle ?? '')));
            if ($handle !== '') {
                $knownHandles[$handle] = true;
            }
        }

        $missing = array_values(array_filter($normalized, static fn(string $handle): bool => !isset($knownHandles[$handle])));
        if (!empty($missing)) {
            throw new InvalidArgumentException('Unknown sections: ' . implode(', ', $missing) . '.');
        }

        sort($normalized);
        return $normalized;
    }

    private function validateSiteIds(array $siteIds): array
    {
        $normalized = $this->normalizeIntegerIds($siteIds);
        if (empty($normalized)) {
            return [];
        }

        $knownIds = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteId = (int)($site->id ?? 0);
            if ($siteId > 0) {
                $knownIds[$siteId] = true;
            }
        }

        $missing = array_values(array_filter($normalized, static fn(int $siteId): bool => !isset($knownIds[$siteId])));
        if (!empty($missing)) {
            throw new InvalidArgumentException('Unknown site IDs: ' . implode(', ', $missing) . '.');
        }

        sort($normalized);
        return $normalized;
    }

    private function validateEntryIds(array $entryIds): array
    {
        $normalized = $this->normalizeIntegerIds($entryIds);
        if (empty($normalized)) {
            return [];
        }

        $foundIds = Entry::find()
            ->id($normalized)
            ->status(null)
            ->site('*')
            ->limit(null)
            ->ids();
        $foundIds = $this->normalizeIntegerIds($foundIds);
        $missing = array_values(array_diff($normalized, $foundIds));
        if (!empty($missing)) {
            throw new InvalidArgumentException('Unknown entry IDs: ' . implode(', ', $missing) . '.');
        }

        sort($normalized);
        return $normalized;
    }

    private function validateProductIds(array $productIds): array
    {
        $normalized = $this->normalizeIntegerIds($productIds);
        if (empty($normalized)) {
            return [];
        }

        if (!Plugin::getInstance()->isCommercePluginEnabled()) {
            throw new InvalidArgumentException('Product boundaries require Craft Commerce.');
        }

        $productClass = '\\craft\\commerce\\elements\\Product';
        if (!class_exists($productClass)) {
            throw new InvalidArgumentException('Craft Commerce product class is unavailable.');
        }

        $foundIds = $productClass::find()
            ->id($normalized)
            ->status(null)
            ->site('*')
            ->limit(null)
            ->ids();
        $foundIds = $this->normalizeIntegerIds($foundIds);
        $missing = array_values(array_diff($normalized, $foundIds));
        if (!empty($missing)) {
            throw new InvalidArgumentException('Unknown product IDs: ' . implode(', ', $missing) . '.');
        }

        sort($normalized);
        return $normalized;
    }

    private function buildRuntimeWorkflowPayload(array $workflow): array
    {
        $schedule = $this->buildRuntimeSchedulePayload($workflow);
        $config = (array)($workflow['config'] ?? []);
        $latestRun = is_array($workflow['latestRun'] ?? null) ? (array)$workflow['latestRun'] : null;

        return [
            'id' => (int)($workflow['id'] ?? 0),
            'name' => (string)($workflow['name'] ?? ''),
            'description' => (string)($workflow['description'] ?? ''),
            'templateHandle' => (string)($workflow['templateHandle'] ?? ''),
            'templateDisplayName' => (string)($workflow['template']['displayName'] ?? $workflow['templateHandle'] ?? 'Workflow'),
            'mode' => (string)($workflow['template']['mode'] ?? 'read-only'),
            'schedule' => $schedule,
            'readBoundary' => $this->buildReadBoundaryPayload($config),
            'accountScopeCoverage' => [
                'isBroader' => (bool)($workflow['accountScopeCoverage']['isBroader'] ?? false),
                'extraScopes' => array_values(array_map('strval', (array)($workflow['accountScopeCoverage']['extraScopes'] ?? []))),
                'summary' => (string)($workflow['accountScopeCoverage']['summary'] ?? ''),
                'meta' => (string)($workflow['accountScopeCoverage']['meta'] ?? ''),
            ],
            'config' => [
                'promptContext' => (string)($config['promptContext'] ?? ''),
                'operatorNotes' => (string)($config['operatorNotes'] ?? ''),
            ],
            'latestRun' => $latestRun,
        ];
    }

    private function buildRuntimeSchedulePayload(array $workflow): array
    {
        $latestRun = is_array($workflow['latestRun'] ?? null) ? (array)$workflow['latestRun'] : null;
        $latestScheduledAt = $this->resolveLatestScheduledOccurrenceUtc($workflow);
        $nextDueAt = $this->resolveNextScheduledOccurrenceUtc($workflow);
        $latestRunAt = $this->resolveLatestRunActivityAt($latestRun);
        $createdAt = $this->normalizeDateTimeValue($workflow['dateCreated'] ?? null);
        $isDue = false;

        if (
            strtolower(trim((string)($workflow['status'] ?? ''))) === self::STATUS_ACTIVE
            && $latestScheduledAt !== null
            && ($createdAt === null || strcmp($latestScheduledAt, $createdAt) >= 0)
        ) {
            $isDue = $latestRunAt === null || strcmp($latestRunAt, $latestScheduledAt) < 0;
        }

        return [
            'cadence' => (string)($workflow['cadence'] ?? 'weekly'),
            'weekday' => (int)($workflow['weekday'] ?? 0),
            'timeOfDay' => (string)($workflow['timeOfDay'] ?? ''),
            'timezone' => (string)($workflow['timezone'] ?? 'UTC'),
            'label' => (string)($workflow['scheduleLabel'] ?? 'Manual'),
            'nextDueAt' => $nextDueAt,
            'latestScheduledAt' => $latestScheduledAt,
            'isDue' => $isDue,
        ];
    }

    private function buildReadBoundaryPayload(array $config): array
    {
        $entryIds = array_values(array_map('intval', (array)($config['entryIds'] ?? [])));
        $productIds = array_values(array_map('intval', (array)($config['productIds'] ?? [])));
        $sectionHandles = array_values(array_map('strval', (array)($config['sectionHandles'] ?? [])));
        $siteIds = array_values(array_map('intval', (array)($config['siteIds'] ?? [])));

        return [
            'entryIds' => $entryIds,
            'productIds' => $productIds,
            'sectionHandles' => $sectionHandles,
            'siteIds' => $siteIds,
            'summary' => $this->buildConfigSummary($config),
            'isBounded' => !empty($entryIds) || !empty($productIds) || !empty($sectionHandles) || !empty($siteIds),
        ];
    }

    private function evaluateWorkflowAccountScopeCoverage(?array $account, ?array $template): array
    {
        if (!is_array($account) || !is_array($template)) {
            return [
                'isBroader' => false,
                'extraScopes' => [],
                'allowedScopes' => [],
                'summary' => '',
                'meta' => '',
            ];
        }

        $allowedScopes = $this->buildWorkflowAllowedScopes($template);
        $accountScopes = array_values(array_unique(array_filter(array_map(
            static fn($scope): string => trim((string)$scope),
            (array)($account['scopes'] ?? [])
        ))));
        $extraScopes = [];
        foreach ($accountScopes as $scope) {
            if (!in_array($scope, $allowedScopes, true)) {
                $extraScopes[] = $scope;
            }
        }

        return [
            'isBroader' => !empty($extraScopes),
            'extraScopes' => $extraScopes,
            'allowedScopes' => $allowedScopes,
            'summary' => !empty($extraScopes) ? 'Account broader than workflow' : 'Aligned with workflow',
            'meta' => !empty($extraScopes) ? $this->describeScopeList($extraScopes, 'extra scope') : '',
        ];
    }

    private function buildWorkflowAllowedScopes(array $template): array
    {
        $scopes = array_merge(
            self::RUNTIME_BASELINE_SCOPES,
            array_values(array_map('strval', (array)($template['requiredScopes'] ?? []))),
            array_values(array_map('strval', (array)($template['recommendedOptionalScopes'] ?? [])))
        );

        return array_values(array_unique(array_filter(array_map(
            static fn($scope): string => trim((string)$scope),
            $scopes
        ))));
    }

    private function describeScopeList(array $scopes, string $noun): string
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn($scope): string => trim((string)$scope),
            $scopes
        ))));
        if (empty($normalized)) {
            return '';
        }

        $visible = array_slice($normalized, 0, 3);
        $label = count($normalized) . ' ' . $noun . (count($normalized) === 1 ? '' : 's');
        $detail = implode(', ', $visible);
        if (count($normalized) > count($visible)) {
            $detail .= ', +' . (count($normalized) - count($visible)) . ' more';
        }

        return $label . ': ' . $detail;
    }

    private function normalizeWorkflowRunPayload(array $payload): array
    {
        $workflowId = (int)($payload['workflowId'] ?? 0);
        if ($workflowId <= 0) {
            throw new InvalidArgumentException('`workflowId` is required.');
        }

        $status = strtolower(trim((string)($payload['status'] ?? '')));
        $allowedStatuses = ['started', 'succeeded', 'failed', 'blocked', 'approval_requested'];
        if (!in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException('Unsupported workflow run status.');
        }

        return [
            'workflowId' => $workflowId,
            'runId' => (int)($payload['runId'] ?? 0),
            'status' => $status,
            'workerId' => trim((string)($payload['workerId'] ?? '')),
            'summary' => trim((string)($payload['summary'] ?? '')),
            'logExcerpt' => trim((string)($payload['logExcerpt'] ?? '')),
            'scheduledFor' => $this->normalizeWorkflowRunDateTime($payload['scheduledFor'] ?? null, 'scheduledFor'),
            'claimedAt' => $this->normalizeWorkflowRunDateTime($payload['claimedAt'] ?? null, 'claimedAt'),
            'startedAt' => $this->normalizeWorkflowRunDateTime($payload['startedAt'] ?? null, 'startedAt'),
            'completedAt' => $this->normalizeWorkflowRunDateTime($payload['completedAt'] ?? null, 'completedAt'),
            'heartbeatAt' => $this->normalizeWorkflowRunDateTime($payload['heartbeatAt'] ?? null, 'heartbeatAt'),
            'approvalIds' => $this->normalizeIntegerIds((array)($payload['approvalIds'] ?? [])),
            'outcomeRefs' => array_values(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                (array)($payload['outcomeRefs'] ?? [])
            ))),
            'metadata' => is_array($payload['metadata'] ?? null) ? (array)$payload['metadata'] : [],
        ];
    }

    private function normalizeWorkflowRunDateTime(mixed $value, string $fieldName): ?string
    {
        $normalized = trim((string)$value);
        if ($normalized === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($normalized))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new InvalidArgumentException(sprintf('`%s` must be a valid datetime.', $fieldName));
        }
    }

    private function runStatusIsTerminal(string $status): bool
    {
        return in_array($status, ['succeeded', 'failed', 'blocked', 'approval_requested'], true);
    }

    private function resolveLatestRunActivityAt(?array $latestRun): ?string
    {
        if (!is_array($latestRun)) {
            return null;
        }

        foreach (['completedAt', 'startedAt', 'claimedAt', 'scheduledFor'] as $field) {
            $value = $this->normalizeDateTimeValue($latestRun[$field] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function resolveLatestScheduledOccurrenceUtc(array $workflow): ?string
    {
        $schedule = $this->resolveScheduleWindow($workflow);
        return $schedule['latestScheduledAtUtc'];
    }

    private function resolveNextScheduledOccurrenceUtc(array $workflow): ?string
    {
        $schedule = $this->resolveScheduleWindow($workflow);
        return $schedule['nextScheduledAtUtc'];
    }

    private function resolveScheduleWindow(array $workflow): array
    {
        $weekday = (int)($workflow['weekday'] ?? 0);
        $timeOfDay = trim((string)($workflow['timeOfDay'] ?? ''));
        $timezone = $this->resolveWorkflowTimezone($workflow['timezone'] ?? null, null, []);
        if ($weekday < 1 || $weekday > 7 || !preg_match('/^(?<hour>\d{2}):(?<minute>\d{2})$/', $timeOfDay, $matches)) {
            return [
                'latestScheduledAtUtc' => null,
                'nextScheduledAtUtc' => null,
            ];
        }

        try {
            $zone = new DateTimeZone($timezone);
            $now = new DateTimeImmutable('now', $zone);
            $hour = (int)($matches['hour'] ?? 0);
            $minute = (int)($matches['minute'] ?? 0);
            $candidate = $now->setTime($hour, $minute);
            $daysUntilScheduled = ($weekday - (int)$now->format('N') + 7) % 7;
            if ($daysUntilScheduled > 0) {
                $candidate = $candidate->modify('+' . $daysUntilScheduled . ' days');
            } elseif ($candidate <= $now) {
                $candidate = $candidate->modify('+7 days');
            }

            $latestScheduledAt = $candidate->modify('-7 days');

            return [
                'latestScheduledAtUtc' => $latestScheduledAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'nextScheduledAtUtc' => $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ];
        } catch (\Throwable) {
            return [
                'latestScheduledAtUtc' => null,
                'nextScheduledAtUtc' => null,
            ];
        }
    }

    private function setWorkflowStatus(int $workflowId, string $status): bool
    {
        if ($workflowId <= 0 || !$this->workflowsTableExists()) {
            return false;
        }

        return Craft::$app->getDb()->createCommand()->update(self::TABLE_WORKFLOWS, [
            'status' => $status,
            'dateUpdated' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $workflowId])->execute() > 0;
    }

    private function buildBundleReadme(array $workflow, array $template, string $workflowSlug): string
    {
        $config = (array)($workflow['config'] ?? []);
        $lines = [
            '# ' . trim((string)($workflow['name'] ?? 'Managed Workflow')),
            '',
            'Workflow type: `' . trim((string)($template['displayName'] ?? ($workflow['templateHandle'] ?? 'workflow'))) . '`',
            '',
            trim((string)($workflow['description'] ?? '')),
            '',
            '## What this bundle is for',
            '',
            '- bootstrap an external worker for this configured workflow instance',
            '- keep schedule intent and read-only bindings visible outside Craft',
            '- start from a safe review/audit worker pattern before adding any deeper automation',
            '',
            '## Workflow bindings',
            '',
            '- Managed account: `' . trim((string)($workflow['accountDisplayName'] ?? '')) . '`',
            '- Mode: `' . trim((string)($template['mode'] ?? 'read-only')) . '`',
            '- Focus: `' . trim((string)($template['focusLabel'] ?? 'Content review')) . '`',
            '- Schedule: `' . trim((string)($workflow['scheduleLabel'] ?? '')) . '`',
            '- Read boundary: `' . trim((string)($workflow['readBoundary']['summary'] ?? $this->buildConfigSummary($config) ?: 'Unbounded')) . '`',
            '- Entry IDs: `' . (!empty($config['entryIds']) ? implode(', ', array_map('strval', (array)$config['entryIds'])) : 'unbounded') . '`',
            '- Product IDs: `' . (!empty($config['productIds']) ? implode(', ', array_map('strval', (array)$config['productIds'])) : 'unbounded') . '`',
            '- Sections: `' . implode(', ', array_values(array_map('strval', (array)($config['sectionHandles'] ?? [])))) . '`',
            '- Sites: `' . implode(', ', array_map('strval', (array)($config['siteIds'] ?? []))) . '`',
            '',
            '## Files',
            '',
            '- `.env.example`',
            '- `config.example.json`',
            '- `worker.mjs`',
            '- `run-worker.sh`',
            '- `cron.example`',
            '- `output-contract.md`',
            '- workflow polling/reporting API contract',
            '',
            '## Important boundary',
            '',
            '- this bundle does not include secrets',
            '- download the matching account handoff from `Agents -> Accounts` when you need a token-filled `.env`',
            '- this first workflow slice is read-only and does not mutate Craft content',
            '- Agents stores workflow state and visibility; the worker still runs externally',
            '- recent runs in Craft are backed by the workflow polling/reporting API',
            '- the cron example assumes the host already runs in the intended server/runtime timezone',
            '',
            '## External output storage',
            '',
            '- Agents does not currently store fetched raw inputs, reasoning artifacts, or reports for workflow runs.',
            '- Save those outputs outside the Craft webroot and outside the plugin directory.',
            '- Suggested root: `/absolute/path/to/agents/workflows/' . $workflowSlug . '`',
            '- At minimum persist:',
            '  - `state/state.json` for cursors and last-successful checkpoints',
            '  - `reports/latest.md` for the latest human-readable review',
            '  - `raw/` snapshots only when audit/debugging matters',
            '',
            '## Docs',
            '',
            '- Workflow library: ' . trim((string)($template['docsUrl'] ?? 'https://marcusscheller.com/docs/agents/workflows')),
            '- First worker: https://marcusscheller.com/docs/agents/get-started/first-worker',
        ];

        return implode("\n", array_filter($lines, static fn($line): bool => $line !== '')) . "\n";
    }

    private function buildBundleEnvExample(array $workflow): string
    {
        $config = (array)($workflow['config'] ?? []);

        $lines = [
            'SITE_URL=https://example.test',
            'BASE_URL=${SITE_URL}/agents/v1',
            'AGENTS_TOKEN=replace-with-token-from-accounts',
            'WORKFLOW_ID=' . (int)($workflow['id'] ?? 0),
            'WORKFLOW_TEMPLATE=' . trim((string)($workflow['templateHandle'] ?? '')),
            'WORKFLOW_NAME=' . trim((string)($workflow['name'] ?? '')),
            'WORKFLOW_DISCOVERY_PATH=/workflows',
            'WORKFLOW_DETAIL_PATH=/workflows/show',
            'WORKFLOW_REPORT_PATH=/workflows/run-report',
            'ENTRY_IDS=' . implode(',', array_map('strval', (array)($config['entryIds'] ?? []))),
            'PRODUCT_IDS=' . implode(',', array_map('strval', (array)($config['productIds'] ?? []))),
            'SECTION_HANDLES=' . implode(',', array_map('strval', (array)($config['sectionHandles'] ?? []))),
            'SITE_IDS=' . implode(',', array_map('strval', (array)($config['siteIds'] ?? []))),
            'PROMPT_CONTEXT=' . trim((string)($config['promptContext'] ?? '')),
            'REQUEST_TIMEOUT_MS=15000',
            'PRINT_JSON=1',
            'OUTPUT_ROOT=/absolute/path/to/agents/workflows/' . (StringHelper::toKebabCase((string)($workflow['name'] ?? 'managed-workflow')) ?: 'managed-workflow'),
            'STATE_PATH=${OUTPUT_ROOT}/state/state.json',
            'REPORT_PATH=${OUTPUT_ROOT}/reports/latest.md',
            'RAW_ARCHIVE_DIR=${OUTPUT_ROOT}/raw',
            'DRY_RUN=0',
        ];

        return implode("\n", $lines) . "\n";
    }

    private function buildBundleWorkerScript(array $workflow, array $template): string
    {
        $workflowId = (int)($workflow['id'] ?? 0);
        $workflowName = trim((string)($workflow['name'] ?? 'Managed Workflow'));
        $templateHandle = trim((string)($workflow['templateHandle'] ?? ''));
        $encodedWorkflowName = $this->encodeJsString($workflowName);
        $encodedTemplateHandle = $this->encodeJsString($templateHandle);

        return sprintf(<<<'JS'
#!/usr/bin/env node

const workflowId = process.env.WORKFLOW_ID || '%d';
const workflowName = process.env.WORKFLOW_NAME || %s;
const templateHandle = process.env.WORKFLOW_TEMPLATE || %s;
const baseUrl = (process.env.BASE_URL || '').replace(/\/$/, '');
const token = process.env.AGENTS_TOKEN || '';
const workflowDiscoveryPath = process.env.WORKFLOW_DISCOVERY_PATH || '/workflows';
const workflowDetailPath = process.env.WORKFLOW_DETAIL_PATH || '/workflows/show';
const workflowReportPath = process.env.WORKFLOW_REPORT_PATH || '/workflows/run-report';
const entryIds = (process.env.ENTRY_IDS || '').split(',').map((value) => value.trim()).filter(Boolean);
const productIds = (process.env.PRODUCT_IDS || '').split(',').map((value) => value.trim()).filter(Boolean);
const sectionHandles = (process.env.SECTION_HANDLES || '').split(',').map((value) => value.trim()).filter(Boolean);
const siteIds = (process.env.SITE_IDS || '').split(',').map((value) => value.trim()).filter(Boolean);
const promptContext = process.env.PROMPT_CONTEXT || '';
const outputRoot = process.env.OUTPUT_ROOT || '';
const reportPath = process.env.REPORT_PATH || '';

async function fetchJson(path) {
  const response = await fetch(baseUrl + path, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    },
  });

  if (!response.ok) {
    throw new Error(`Request failed: ${response.status} ${response.statusText}`);
  }

  return response.json();
}

async function postJson(path, body) {
  const response = await fetch(baseUrl + path, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
  });

  if (!response.ok) {
    throw new Error(`Request failed: ${response.status} ${response.statusText}`);
  }

  return response.json();
}

async function main() {
  if (!baseUrl || !token) {
    throw new Error('Set BASE_URL and AGENTS_TOKEN before running this worker scaffold.');
  }

  const whoami = await fetchJson('/auth/whoami');
  const due = await fetchJson(`${workflowDiscoveryPath}?dueOnly=1&limit=20`);
  const candidate = Array.isArray(due.data)
    ? due.data.find((item) => String(item.id) === String(workflowId))
    : null;

  if (!candidate) {
    console.log(`[workflow] ${workflowName}: nothing due right now.`);
    return;
  }

  const detail = await fetchJson(`${workflowDetailPath}?id=${encodeURIComponent(String(workflowId))}`);
  const started = await postJson(workflowReportPath, {
    workflowId: Number(workflowId),
    status: 'started',
    scheduledFor: candidate.schedule?.latestScheduledAt || null,
    summary: 'Scaffold run started.',
    metadata: {
      source: 'bundle-scaffold',
      templateHandle,
    },
  });

  const readBoundary = detail.data?.readBoundary || {};
  const summary = [
    `Bound account: ${whoami.principal?.credentialId || 'unknown'}`,
    `Entry IDs: ${entryIds.join(', ') || readBoundary.entryIds?.join(', ') || 'unbounded'}`,
    `Product IDs: ${productIds.join(', ') || readBoundary.productIds?.join(', ') || 'unbounded'}`,
    `Sections: ${sectionHandles.join(', ') || readBoundary.sectionHandles?.join(', ') || 'unbounded'}`,
    `Sites: ${siteIds.join(', ') || readBoundary.siteIds?.join(', ') || 'unbounded'}`,
    `Prompt context: ${promptContext || 'none'}`,
    'Next step: replace this scaffold body with the real external fetch/review/report loop.',
  ].join('\n');

  if (reportPath) {
    const { mkdir, writeFile } = await import('node:fs/promises');
    const path = await import('node:path');
    await mkdir(path.dirname(reportPath), { recursive: true });
    await writeFile(reportPath, `${summary}\n`, 'utf8');
  }

  await postJson(workflowReportPath, {
    workflowId: Number(workflowId),
    runId: started.data?.id || null,
    status: 'succeeded',
    summary: 'Scaffold run completed and reported.',
    metadata: {
      outputRoot,
      reportPath,
    },
  });

  console.log(`[workflow] ${workflowName} (${templateHandle})`);
  console.log(summary);
}

main().catch((error) => {
  console.error('[workflow] failed:', error.message);
  process.exitCode = 1;
});
JS,
            $workflowId,
            $encodedWorkflowName,
            $encodedTemplateHandle
        );
    }

    private function buildBundleRunScript(string $workflowSlug): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "\${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if [[ -f ".env" ]]; then
  set -a
  source ".env"
  set +a
fi

node worker.mjs
BASH;
    }

    private function buildScheduleLabel(array $row): string
    {
        $weekday = (int)($row['weekday'] ?? 0);
        $timeOfDay = trim((string)($row['timeOfDay'] ?? ''));

        $weekdayLabels = [
            1 => 'Mon',
            2 => 'Tue',
            3 => 'Wed',
            4 => 'Thu',
            5 => 'Fri',
            6 => 'Sat',
            7 => 'Sun',
        ];

        if ($weekday > 0 && $timeOfDay !== '') {
            return sprintf('%s %s', $weekdayLabels[$weekday] ?? 'Day', $timeOfDay);
        }

        return 'Manual';
    }

    private function buildNextDueLabel(array $row, string $status): string
    {
        if ($status !== self::STATUS_ACTIVE) {
            return $status === self::STATUS_PAUSED ? 'Paused' : 'n/a';
        }

        $weekday = (int)($row['weekday'] ?? 0);
        $timeOfDay = trim((string)($row['timeOfDay'] ?? ''));
        $timezone = $this->resolveWorkflowTimezone($row['timezone'] ?? null, null, []);
        if ($weekday < 1 || $weekday > 7 || $timeOfDay === '') {
            return 'Manual';
        }

        try {
            [$hour, $minute] = array_map('intval', explode(':', $timeOfDay));
            $zone = new DateTimeZone($timezone);
            $now = new DateTimeImmutable('now', $zone);
            $daysAhead = ($weekday - (int)$now->format('N') + 7) % 7;
            $candidate = $now->setTime($hour, $minute);
            if ($daysAhead > 0) {
                $candidate = $candidate->modify('+' . $daysAhead . ' days');
            }
            if ($candidate <= $now) {
                $candidate = $candidate->modify('+7 days');
            }

            return $candidate->format('D H:i');
        } catch (\Throwable) {
            return 'Scheduled';
        }
    }

    private function resolveWorkflowTimezone(mixed $payloadTimezone, mixed $existingTimezone, array $template): string
    {
        $candidates = [
            trim((string)$payloadTimezone),
            trim((string)$existingTimezone),
            trim((string)Craft::$app->getTimeZone()),
            trim((string)($template['defaultTimezone'] ?? '')),
            'UTC',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && in_array($candidate, DateTimeZone::listIdentifiers(), true)) {
                return $candidate;
            }
        }

        return 'UTC';
    }

    private function buildConfigSummary(array $config): string
    {
        $entryIds = array_values(array_map('intval', (array)($config['entryIds'] ?? [])));
        $productIds = array_values(array_map('intval', (array)($config['productIds'] ?? [])));
        $sections = array_values(array_map('strval', (array)($config['sectionHandles'] ?? [])));
        $sites = array_values(array_map('intval', (array)($config['siteIds'] ?? [])));

        $parts = [];
        if (!empty($entryIds)) {
            $parts[] = count($entryIds) === 1
                ? 'Entry ID: ' . (string)$entryIds[0]
                : count($entryIds) . ' entry IDs';
        }
        if (!empty($productIds)) {
            $parts[] = count($productIds) === 1
                ? 'Product ID: ' . (string)$productIds[0]
                : count($productIds) . ' product IDs';
        }
        if (!empty($sections)) {
            $parts[] = 'Sections: ' . implode(', ', $sections);
        }
        if (!empty($sites)) {
            $parts[] = 'Sites: ' . implode(', ', array_map('strval', $sites));
        }
        if (!empty($config['promptContext'])) {
            $parts[] = 'Context set';
        }

        return !empty($parts) ? implode(' · ', $parts) : 'Unbounded read review';
    }

    private function buildOutputContract(array $workflow, string $workflowSlug): string
    {
        return <<<MD
# Output Contract

Agents does not currently store fetched raw inputs, reasoning artifacts, or reports for workflow runs.

Store those files outside the Craft webroot and outside the plugin directory.

Suggested structure:

```text
/absolute/path/to/agents/workflows/{$workflowSlug}/
  state/
    state.json
  raw/
    2026-03-22-input.json
  reports/
    latest.md
    2026-03-22-summary.md
```

Recommended minimum:

- `state/state.json`
  - cursors
  - last-successful run timestamp
  - checkpoint data needed for the next scheduled run
- `reports/latest.md`
  - latest human-readable workflow result
- `raw/`
  - optional snapshots kept only when audit/debugging matters

Recommended handoff pattern:

1. Discover due workflows through `GET /agents/v1/workflows`.
2. Mark the run as started through `POST /agents/v1/workflows/run-report`.
3. Save normalized input data to `raw/` only if you need audit/debugging.
4. Let the external agent reason over those saved inputs or an in-memory normalized dataset.
5. Save the final summary/report to `reports/latest.md`.
6. Report the final run outcome back to Agents so `Latest Run` and `Recent runs` stay accurate.
MD;
    }

    private function statusToneForWorkflow(string $status, bool $needsAttention): string
    {
        if ($needsAttention) {
            return 'warn';
        }
        if ($status === self::STATUS_ACTIVE) {
            return 'good';
        }
        if ($status === self::STATUS_PAUSED || $status === self::STATUS_DRAFT) {
            return 'muted';
        }

        return 'warn';
    }

    private function normalizeIntegerIds(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0 && !in_array($id, $normalized, true)) {
                $normalized[] = $id;
            }
        }

        return $normalized;
    }

    private function decodeJsonObject(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        try {
            $decoded = Json::decode($value);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function decodeJsonArray(string $value): array
    {
        $decoded = $this->decodeJsonObject($value);
        return array_values($decoded);
    }

    private function normalizeDateTimeValue(mixed $value): ?string
    {
        $normalized = trim((string)$value);
        return $normalized !== '' ? $normalized : null;
    }

    private function templateRequiresTargetSet(?array $template): bool
    {
        return (bool)($template['supportsTargetSet'] ?? false);
    }

    private function encodeJsString(string $value): string
    {
        return Json::encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function workflowsTableExists(): bool
    {
        return Craft::$app->getDb()->tableExists(self::TABLE_WORKFLOWS);
    }

    private function workflowRunsTableExists(): bool
    {
        return Craft::$app->getDb()->tableExists(self::TABLE_WORKFLOW_RUNS);
    }
}
