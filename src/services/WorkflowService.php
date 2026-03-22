<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
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

    public function getWorkflowTemplates(): array
    {
        $templates = [
            [
                'handle' => self::TEMPLATE_CONTENT_QUALITY_REVIEW,
                'displayName' => 'Content Quality Review',
                'description' => 'Review entries, assets, and taxonomy on a recurring schedule and surface content-quality issues for operators.',
                'requiredScopes' => [
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
                'timezone' => (string)($workflow['timezone'] ?? ''),
            ],
            'config' => [
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
        }

        return $summary;
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
            $needsAttention = $status === self::STATUS_ERROR
                || !$hasAccount
                || !$hasTargetSet
                || in_array($latestRunStatus, ['blocked', 'failed', 'error'], true);
            $attentionState = $this->buildWorkflowAttentionState(
                !$hasAccount,
                !$hasTargetSet,
                $status,
                $latestRunStatus,
                $latestRun
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
        ?array $latestRun
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

        $timezone = trim((string)($payload['timezone'] ?? $existing['timezone'] ?? (string)($template['defaultTimezone'] ?? 'UTC')));
        if ($timezone === '' || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Choose a valid timezone.');
        }

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
            throw new InvalidArgumentException('Choose at least one section.');
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
            throw new InvalidArgumentException('Choose at least one site.');
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
            'Template: `' . trim((string)($template['displayName'] ?? ($workflow['templateHandle'] ?? 'workflow'))) . '`',
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
            '',
            '## Important boundary',
            '',
            '- this bundle does not include secrets',
            '- download the matching account handoff from `Agents -> Accounts` when you need a token-filled `.env`',
            '- this first workflow slice is read-only and does not mutate Craft content',
            '- Agents stores workflow state and visibility; the worker still runs externally',
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
const sectionHandles = (process.env.SECTION_HANDLES || '').split(',').map((value) => value.trim()).filter(Boolean);
const siteIds = (process.env.SITE_IDS || '').split(',').map((value) => value.trim()).filter(Boolean);
const promptContext = process.env.PROMPT_CONTEXT || '';

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

async function main() {
  if (!baseUrl || !token) {
    throw new Error('Set BASE_URL and AGENTS_TOKEN before running this worker scaffold.');
  }

  const whoami = await fetchJson('/auth/whoami');
  console.log(`[workflow] ${workflowName} (${templateHandle})`);
  console.log(`[workflow] bound account: ${whoami.handle || whoami.id || 'unknown'}`);
  console.log(`[workflow] sections: ${sectionHandles.join(', ') || 'all configured in workflow config'}`);
  console.log(`[workflow] sites: ${siteIds.join(', ') || 'all configured in workflow config'}`);
  console.log(`[workflow] prompt context: ${promptContext || 'none'}`);
  console.log(`[workflow] output root: ${process.env.OUTPUT_ROOT || 'set OUTPUT_ROOT in .env'}`);
  console.log(`[workflow] next step: implement the read-only fetch + analysis loop for workflow #${workflowId}.`);
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
        $timezone = trim((string)($row['timezone'] ?? 'UTC'));

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
            return sprintf('%s %s · %s', $weekdayLabels[$weekday] ?? 'Day', $timeOfDay, $timezone);
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
        $timezone = trim((string)($row['timezone'] ?? 'UTC'));
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

    private function buildConfigSummary(array $config): string
    {
        $sections = array_values(array_map('strval', (array)($config['sectionHandles'] ?? [])));
        $sites = array_values(array_map('intval', (array)($config['siteIds'] ?? [])));

        $parts = [];
        if (!empty($sections)) {
            $parts[] = 'Sections: ' . implode(', ', $sections);
        }
        if (!empty($sites)) {
            $parts[] = 'Sites: ' . implode(', ', array_map('strval', $sites));
        }
        if (!empty($config['promptContext'])) {
            $parts[] = 'Context set';
        }

        return implode(' · ', $parts);
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

1. Save normalized input data to `raw/` only if you need audit/debugging.
2. Let the external agent reason over those saved inputs or an in-memory normalized dataset.
3. Save the final summary/report to `reports/latest.md`.
4. Update `state/state.json` after a successful run.
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
