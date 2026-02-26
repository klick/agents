<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use yii\db\Query;

class ControlPlaneService extends Component
{
    private const TABLE_POLICIES = '{{%agents_control_policies}}';
    private const TABLE_APPROVALS = '{{%agents_control_approvals}}';
    private const TABLE_EXECUTIONS = '{{%agents_control_executions}}';
    private const TABLE_AUDIT = '{{%agents_control_audit_log}}';

    private const APPROVAL_STATUS_PENDING = 'pending';
    private const APPROVAL_STATUS_APPROVED = 'approved';
    private const APPROVAL_STATUS_REJECTED = 'rejected';

    private const EXECUTION_STATUS_PENDING = 'pending';
    private const EXECUTION_STATUS_BLOCKED = 'blocked';
    private const EXECUTION_STATUS_SUCCEEDED = 'succeeded';
    private const EXECUTION_STATUS_FAILED = 'failed';

    private const RISK_LEVEL_LOW = 'low';
    private const RISK_LEVEL_MEDIUM = 'medium';
    private const RISK_LEVEL_HIGH = 'high';
    private const RISK_LEVEL_CRITICAL = 'critical';

    public function getControlPlaneSnapshot(int $limit = 20): array
    {
        return [
            'summary' => $this->getSummary(),
            'policies' => $this->getPolicies(),
            'approvals' => $this->getApprovals([], $limit),
            'executions' => $this->getExecutions([], $limit),
            'audit' => $this->getAuditEvents([], $limit),
        ];
    }

    public function getSummary(): array
    {
        return [
            'policies' => $this->countRows(self::TABLE_POLICIES),
            'approvalsPending' => $this->countRows(self::TABLE_APPROVALS, ['status' => self::APPROVAL_STATUS_PENDING]),
            'executionsBlocked' => $this->countRows(self::TABLE_EXECUTIONS, ['status' => self::EXECUTION_STATUS_BLOCKED]),
            'executionsSucceeded' => $this->countRows(self::TABLE_EXECUTIONS, ['status' => self::EXECUTION_STATUS_SUCCEEDED]),
            'auditEvents' => $this->countRows(self::TABLE_AUDIT),
        ];
    }

    public function getPolicies(): array
    {
        if (!$this->tableExists(self::TABLE_POLICIES)) {
            return [];
        }

        $rows = (new Query())
            ->from(self::TABLE_POLICIES)
            ->orderBy(['actionPattern' => SORT_ASC, 'handle' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => $this->hydratePolicy($row), $rows);
    }

    public function upsertPolicy(array $input, array $actor = []): array
    {
        $this->requireTable(self::TABLE_POLICIES, 'Policy storage table is unavailable. Run plugin migrations.');

        $handle = $this->normalizeHandle((string)($input['handle'] ?? ''));
        if ($handle === '') {
            throw new \InvalidArgumentException('Policy handle is required.');
        }

        $actionPattern = $this->normalizeActionPattern((string)($input['actionPattern'] ?? ''));
        if ($actionPattern === '') {
            throw new \InvalidArgumentException('Policy action pattern is required.');
        }

        $displayName = $this->normalizeDisplayName((string)($input['displayName'] ?? ''), $handle);
        $riskLevel = $this->normalizeRiskLevel((string)($input['riskLevel'] ?? self::RISK_LEVEL_MEDIUM));
        $requiresApproval = $this->normalizeBool($input['requiresApproval'] ?? true, true);
        $enabled = $this->normalizeBool($input['enabled'] ?? true, true);
        $config = $this->normalizeArray($input['config'] ?? []);

        $now = gmdate('Y-m-d H:i:s');
        $encodedConfig = $this->encodeJson($config);

        $existing = (new Query())
            ->from(self::TABLE_POLICIES)
            ->where(['handle' => $handle])
            ->one();

        if (is_array($existing)) {
            Craft::$app->getDb()->createCommand()->update(self::TABLE_POLICIES, [
                'displayName' => $displayName,
                'actionPattern' => $actionPattern,
                'requiresApproval' => $requiresApproval,
                'enabled' => $enabled,
                'riskLevel' => $riskLevel,
                'config' => $encodedConfig,
                'dateUpdated' => $now,
            ], ['id' => (int)$existing['id']])->execute();

            $policy = (new Query())
                ->from(self::TABLE_POLICIES)
                ->where(['id' => (int)$existing['id']])
                ->one();

            $hydrated = $this->hydratePolicy(is_array($policy) ? $policy : $existing);
            $this->writeAuditEvent([
                'category' => 'control.policy',
                'action' => 'upsert',
                'outcome' => 'success',
                'actorType' => (string)($actor['actorType'] ?? 'system'),
                'actorId' => (string)($actor['actorId'] ?? 'system'),
                'requestId' => (string)($actor['requestId'] ?? ''),
                'ipAddress' => (string)($actor['ipAddress'] ?? ''),
                'entityType' => 'policy',
                'entityId' => (string)($hydrated['id'] ?? ''),
                'summary' => sprintf('Updated policy `%s` (%s).', $handle, $actionPattern),
                'metadata' => [
                    'handle' => $handle,
                    'actionPattern' => $actionPattern,
                    'requiresApproval' => $requiresApproval,
                    'enabled' => $enabled,
                    'riskLevel' => $riskLevel,
                ],
            ]);

            return $hydrated;
        }

        Craft::$app->getDb()->createCommand()->insert(self::TABLE_POLICIES, [
            'handle' => $handle,
            'displayName' => $displayName,
            'actionPattern' => $actionPattern,
            'requiresApproval' => $requiresApproval,
            'enabled' => $enabled,
            'riskLevel' => $riskLevel,
            'config' => $encodedConfig,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $policy = (new Query())
            ->from(self::TABLE_POLICIES)
            ->where(['handle' => $handle])
            ->one();

        if (!is_array($policy)) {
            throw new \RuntimeException('Unable to load newly created policy.');
        }

        $hydrated = $this->hydratePolicy($policy);
        $this->writeAuditEvent([
            'category' => 'control.policy',
            'action' => 'create',
            'outcome' => 'success',
            'actorType' => (string)($actor['actorType'] ?? 'system'),
            'actorId' => (string)($actor['actorId'] ?? 'system'),
            'requestId' => (string)($actor['requestId'] ?? ''),
            'ipAddress' => (string)($actor['ipAddress'] ?? ''),
            'entityType' => 'policy',
            'entityId' => (string)($hydrated['id'] ?? ''),
            'summary' => sprintf('Created policy `%s` (%s).', $handle, $actionPattern),
            'metadata' => [
                'handle' => $handle,
                'actionPattern' => $actionPattern,
                'requiresApproval' => $requiresApproval,
                'enabled' => $enabled,
                'riskLevel' => $riskLevel,
            ],
        ]);

        return $hydrated;
    }

    public function getApprovals(array $filters = [], int $limit = 50): array
    {
        if (!$this->tableExists(self::TABLE_APPROVALS)) {
            return [];
        }

        $query = (new Query())
            ->from(self::TABLE_APPROVALS)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($this->normalizeLimit($limit, 50));

        if (isset($filters['status']) && is_string($filters['status']) && trim($filters['status']) !== '') {
            $query->andWhere(['status' => strtolower(trim($filters['status']))]);
        }

        if (isset($filters['actionType']) && is_string($filters['actionType']) && trim($filters['actionType']) !== '') {
            $query->andWhere(['actionType' => trim($filters['actionType'])]);
        }

        $rows = $query->all();
        return array_map(fn(array $row) => $this->hydrateApproval($row), $rows);
    }

    public function requestApproval(array $input, array $actor = []): array
    {
        $this->requireTable(self::TABLE_APPROVALS, 'Approval storage table is unavailable. Run plugin migrations.');

        $actionType = $this->normalizeActionType((string)($input['actionType'] ?? ''));
        if ($actionType === '') {
            throw new \InvalidArgumentException('Approval action type is required.');
        }

        $idempotencyKey = $this->normalizeIdempotencyKey((string)($input['idempotencyKey'] ?? ''));
        if ($idempotencyKey !== '') {
            $existing = $this->findApprovalByIdempotencyKey($idempotencyKey);
            if (is_array($existing)) {
                $approval = $this->hydrateApproval($existing);
                $approval['idempotentReplay'] = true;
                return $approval;
            }
        }

        $actionRef = $this->normalizeOptionalString($input['actionRef'] ?? null, 128);
        $reason = $this->normalizeOptionalString($input['reason'] ?? null);
        $requestedBy = $this->resolveActorId($actor);
        $payload = $this->normalizeArray($input['payload'] ?? []);
        $metadata = $this->normalizeArray($input['metadata'] ?? []);

        $now = gmdate('Y-m-d H:i:s');

        Craft::$app->getDb()->createCommand()->insert(self::TABLE_APPROVALS, [
            'actionType' => $actionType,
            'actionRef' => $actionRef,
            'status' => self::APPROVAL_STATUS_PENDING,
            'requestedBy' => $requestedBy,
            'decidedBy' => null,
            'reason' => $reason,
            'decisionReason' => null,
            'idempotencyKey' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'requestPayload' => $this->encodeJson($payload),
            'metadata' => $this->encodeJson($metadata),
            'decidedAt' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $approval = (new Query())
            ->from(self::TABLE_APPROVALS)
            ->where(['id' => (int)Craft::$app->getDb()->getLastInsertID()])
            ->one();

        if (!is_array($approval)) {
            throw new \RuntimeException('Unable to load newly requested approval.');
        }

        $hydrated = $this->hydrateApproval($approval);

        $this->writeAuditEvent([
            'category' => 'control.approval',
            'action' => 'request',
            'outcome' => 'info',
            'actorType' => (string)($actor['actorType'] ?? 'system'),
            'actorId' => $requestedBy,
            'requestId' => (string)($actor['requestId'] ?? ''),
            'ipAddress' => (string)($actor['ipAddress'] ?? ''),
            'entityType' => 'approval',
            'entityId' => (string)($hydrated['id'] ?? ''),
            'summary' => sprintf('Approval requested for `%s`.', $actionType),
            'metadata' => [
                'actionType' => $actionType,
                'actionRef' => $actionRef,
                'idempotencyKey' => $idempotencyKey,
            ],
        ]);

        return $hydrated;
    }

    public function decideApproval(int $approvalId, string $decision, ?string $decisionReason, array $actor = []): ?array
    {
        if ($approvalId <= 0 || !$this->tableExists(self::TABLE_APPROVALS)) {
            return null;
        }

        $existing = (new Query())
            ->from(self::TABLE_APPROVALS)
            ->where(['id' => $approvalId])
            ->one();

        if (!is_array($existing)) {
            return null;
        }

        $status = $this->normalizeDecisionStatus($decision);
        if ($status === null) {
            throw new \InvalidArgumentException('Decision must be `approved` or `rejected`.');
        }

        $currentStatus = strtolower(trim((string)($existing['status'] ?? '')));
        if ($currentStatus !== self::APPROVAL_STATUS_PENDING) {
            return $this->hydrateApproval($existing);
        }

        $now = gmdate('Y-m-d H:i:s');
        $resolvedDecisionReason = $this->normalizeOptionalString($decisionReason);
        $decidedBy = $this->resolveActorId($actor);

        Craft::$app->getDb()->createCommand()->update(self::TABLE_APPROVALS, [
            'status' => $status,
            'decidedBy' => $decidedBy,
            'decisionReason' => $resolvedDecisionReason,
            'decidedAt' => $now,
            'dateUpdated' => $now,
        ], ['id' => $approvalId])->execute();

        $updated = (new Query())
            ->from(self::TABLE_APPROVALS)
            ->where(['id' => $approvalId])
            ->one();

        if (!is_array($updated)) {
            return null;
        }

        $hydrated = $this->hydrateApproval($updated);

        $this->writeAuditEvent([
            'category' => 'control.approval',
            'action' => 'decision',
            'outcome' => $status === self::APPROVAL_STATUS_APPROVED ? 'success' : 'warning',
            'actorType' => (string)($actor['actorType'] ?? 'system'),
            'actorId' => $decidedBy,
            'requestId' => (string)($actor['requestId'] ?? ''),
            'ipAddress' => (string)($actor['ipAddress'] ?? ''),
            'entityType' => 'approval',
            'entityId' => (string)$approvalId,
            'summary' => sprintf('Approval `%d` marked `%s`.', $approvalId, $status),
            'metadata' => [
                'decision' => $status,
                'decisionReason' => $resolvedDecisionReason,
            ],
        ]);

        return $hydrated;
    }

    public function getExecutions(array $filters = [], int $limit = 50): array
    {
        if (!$this->tableExists(self::TABLE_EXECUTIONS)) {
            return [];
        }

        $query = (new Query())
            ->from(self::TABLE_EXECUTIONS)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($this->normalizeLimit($limit, 50));

        if (isset($filters['status']) && is_string($filters['status']) && trim($filters['status']) !== '') {
            $query->andWhere(['status' => strtolower(trim($filters['status']))]);
        }

        if (isset($filters['actionType']) && is_string($filters['actionType']) && trim($filters['actionType']) !== '') {
            $query->andWhere(['actionType' => trim($filters['actionType'])]);
        }

        $rows = $query->all();
        return array_map(fn(array $row) => $this->hydrateExecution($row), $rows);
    }

    public function executeAction(array $input, array $actor = []): array
    {
        $this->requireTable(self::TABLE_EXECUTIONS, 'Execution storage table is unavailable. Run plugin migrations.');

        $actionType = $this->normalizeActionType((string)($input['actionType'] ?? ''));
        if ($actionType === '') {
            throw new \InvalidArgumentException('Execution action type is required.');
        }

        $idempotencyKey = $this->normalizeIdempotencyKey((string)($input['idempotencyKey'] ?? ''));
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('Execution idempotency key is required.');
        }

        $existing = $this->findExecutionByIdempotencyKey($idempotencyKey);
        if (is_array($existing)) {
            $execution = $this->hydrateExecution($existing);
            $execution['idempotentReplay'] = true;
            return $execution;
        }

        $actionRef = $this->normalizeOptionalString($input['actionRef'] ?? null, 128);
        $requestedBy = $this->resolveActorId($actor);
        $payload = $this->normalizeArray($input['payload'] ?? []);
        $approvalId = isset($input['approvalId']) ? (int)$input['approvalId'] : 0;

        $policy = $this->resolvePolicyForAction($actionType);
        $requiredScope = $this->normalizeOptionalString($policy['requiredScope'] ?? null, 96);

        $status = self::EXECUTION_STATUS_PENDING;
        $errorMessage = null;
        $resultPayload = [
            'executionMode' => 'record_only',
            'message' => 'Action execution recorded. Integrate downstream adapters for side effects.',
            'policyHandle' => (string)($policy['handle'] ?? 'default'),
            'riskLevel' => (string)($policy['riskLevel'] ?? self::RISK_LEVEL_HIGH),
            'requiresApproval' => (bool)($policy['requiresApproval'] ?? true),
        ];

        if (!(bool)($policy['enabled'] ?? true)) {
            $status = self::EXECUTION_STATUS_BLOCKED;
            $errorMessage = 'Matched policy is disabled for execution.';
            $resultPayload['message'] = $errorMessage;
        }

        $approvalEntityId = null;
        if ($status === self::EXECUTION_STATUS_PENDING && (bool)($policy['requiresApproval'] ?? true)) {
            $approval = $approvalId > 0 ? $this->findApprovalById($approvalId) : null;
            if (!is_array($approval)) {
                $status = self::EXECUTION_STATUS_BLOCKED;
                $errorMessage = 'Approval is required before execution.';
                $resultPayload['message'] = $errorMessage;
            } else {
                $approvalEntityId = (int)($approval['id'] ?? 0);
                $approvalStatus = strtolower(trim((string)($approval['status'] ?? '')));
                if ($approvalStatus !== self::APPROVAL_STATUS_APPROVED) {
                    $status = self::EXECUTION_STATUS_BLOCKED;
                    $errorMessage = 'Linked approval is not approved.';
                    $resultPayload['message'] = $errorMessage;
                } elseif ((string)($approval['actionType'] ?? '') !== $actionType) {
                    $status = self::EXECUTION_STATUS_BLOCKED;
                    $errorMessage = 'Linked approval action type mismatch.';
                    $resultPayload['message'] = $errorMessage;
                }
            }
        }

        if ($status === self::EXECUTION_STATUS_PENDING) {
            $status = self::EXECUTION_STATUS_SUCCEEDED;
        }

        $now = gmdate('Y-m-d H:i:s');

        try {
            Craft::$app->getDb()->createCommand()->insert(self::TABLE_EXECUTIONS, [
                'actionType' => $actionType,
                'actionRef' => $actionRef,
                'status' => $status,
                'requestedBy' => $requestedBy,
                'requiredScope' => $requiredScope,
                'approvalId' => $approvalEntityId,
                'idempotencyKey' => $idempotencyKey,
                'requestPayload' => $this->encodeJson($payload),
                'resultPayload' => $this->encodeJson($resultPayload),
                'errorMessage' => $errorMessage,
                'executedAt' => $now,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable) {
            $race = $this->findExecutionByIdempotencyKey($idempotencyKey);
            if (!is_array($race)) {
                throw new \RuntimeException('Unable to persist action execution ledger entry.');
            }

            $execution = $this->hydrateExecution($race);
            $execution['idempotentReplay'] = true;
            return $execution;
        }

        $execution = $this->findExecutionByIdempotencyKey($idempotencyKey);
        if (!is_array($execution)) {
            throw new \RuntimeException('Unable to load newly stored execution entry.');
        }

        $hydrated = $this->hydrateExecution($execution);

        $this->writeAuditEvent([
            'category' => 'control.execution',
            'action' => 'execute',
            'outcome' => $status === self::EXECUTION_STATUS_SUCCEEDED ? 'success' : 'warning',
            'actorType' => (string)($actor['actorType'] ?? 'system'),
            'actorId' => $requestedBy,
            'requestId' => (string)($actor['requestId'] ?? ''),
            'ipAddress' => (string)($actor['ipAddress'] ?? ''),
            'entityType' => 'execution',
            'entityId' => (string)($hydrated['id'] ?? ''),
            'summary' => sprintf('Execution `%s` for `%s` finished as `%s`.', $idempotencyKey, $actionType, $status),
            'metadata' => [
                'actionType' => $actionType,
                'actionRef' => $actionRef,
                'status' => $status,
                'policyHandle' => (string)($policy['handle'] ?? 'default'),
                'approvalId' => $approvalEntityId,
                'requiredScope' => $requiredScope,
                'error' => $errorMessage,
            ],
        ]);

        return $hydrated;
    }

    public function getAuditEvents(array $filters = [], int $limit = 100): array
    {
        if (!$this->tableExists(self::TABLE_AUDIT)) {
            return [];
        }

        $query = (new Query())
            ->from(self::TABLE_AUDIT)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($this->normalizeLimit($limit, 100));

        if (isset($filters['category']) && is_string($filters['category']) && trim($filters['category']) !== '') {
            $query->andWhere(['category' => trim($filters['category'])]);
        }

        if (isset($filters['actorId']) && is_string($filters['actorId']) && trim($filters['actorId']) !== '') {
            $query->andWhere(['actorId' => trim($filters['actorId'])]);
        }

        $rows = $query->all();
        return array_map(fn(array $row) => $this->hydrateAuditEvent($row), $rows);
    }

    public function writeAuditEvent(array $event): int
    {
        if (!$this->tableExists(self::TABLE_AUDIT)) {
            return 0;
        }

        $category = trim((string)($event['category'] ?? 'control'));
        if ($category === '') {
            $category = 'control';
        }

        $action = trim((string)($event['action'] ?? 'event'));
        if ($action === '') {
            $action = 'event';
        }

        $outcome = strtolower(trim((string)($event['outcome'] ?? 'info')));
        if (!in_array($outcome, ['info', 'success', 'warning', 'error'], true)) {
            $outcome = 'info';
        }

        $metadata = $this->normalizeArray($event['metadata'] ?? []);
        $now = gmdate('Y-m-d H:i:s');

        Craft::$app->getDb()->createCommand()->insert(self::TABLE_AUDIT, [
            'category' => substr($category, 0, 64),
            'action' => substr($action, 0, 64),
            'outcome' => substr($outcome, 0, 16),
            'actorType' => substr((string)($event['actorType'] ?? 'system'), 0, 32),
            'actorId' => $this->normalizeOptionalString($event['actorId'] ?? null, 128),
            'requestId' => $this->normalizeOptionalString($event['requestId'] ?? null, 128),
            'ipAddress' => $this->normalizeOptionalString($event['ipAddress'] ?? null, 64),
            'entityType' => $this->normalizeOptionalString($event['entityType'] ?? null, 64),
            'entityId' => $this->normalizeOptionalString($event['entityId'] ?? null, 128),
            'summary' => $this->normalizeOptionalString($event['summary'] ?? null, 255),
            'metadata' => $this->encodeJson($metadata),
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    public function resolvePolicyForAction(string $actionType): array
    {
        $normalizedActionType = $this->normalizeActionType($actionType);

        if ($normalizedActionType === '' || !$this->tableExists(self::TABLE_POLICIES)) {
            return $this->defaultPolicy();
        }

        $rows = (new Query())
            ->from(self::TABLE_POLICIES)
            ->orderBy(['actionPattern' => SORT_DESC, 'handle' => SORT_ASC])
            ->all();

        $matched = null;
        $matchedPatternLength = -1;

        foreach ($rows as $row) {
            $policy = $this->hydratePolicy($row);
            $pattern = (string)($policy['actionPattern'] ?? '');
            if (!$this->matchesActionPattern($normalizedActionType, $pattern)) {
                continue;
            }

            $length = strlen($pattern);
            if ($length > $matchedPatternLength) {
                $matchedPatternLength = $length;
                $matched = $policy;
            }
        }

        if (!is_array($matched)) {
            return $this->defaultPolicy();
        }

        $config = $this->normalizeArray($matched['config'] ?? []);
        $requiredScope = $this->normalizeOptionalString($config['requiredScope'] ?? null, 96);
        if ($requiredScope === null || $requiredScope === '') {
            $requiredScope = 'control:actions:execute';
        }

        $matched['requiredScope'] = $requiredScope;
        return $matched;
    }

    private function defaultPolicy(): array
    {
        return [
            'id' => 0,
            'handle' => 'default-control-policy',
            'displayName' => 'Default control policy',
            'actionPattern' => '*',
            'requiresApproval' => true,
            'enabled' => true,
            'riskLevel' => self::RISK_LEVEL_HIGH,
            'config' => [
                'requiredScope' => 'control:actions:execute',
                'mode' => 'fail-safe',
            ],
            'requiredScope' => 'control:actions:execute',
            'dateCreated' => null,
            'dateUpdated' => null,
        ];
    }

    private function hydratePolicy(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'handle' => (string)($row['handle'] ?? ''),
            'displayName' => (string)($row['displayName'] ?? ''),
            'actionPattern' => (string)($row['actionPattern'] ?? ''),
            'requiresApproval' => (bool)($row['requiresApproval'] ?? false),
            'enabled' => (bool)($row['enabled'] ?? false),
            'riskLevel' => $this->normalizeRiskLevel((string)($row['riskLevel'] ?? self::RISK_LEVEL_MEDIUM)),
            'config' => $this->decodeJsonArray((string)($row['config'] ?? '[]')),
            'dateCreated' => $this->toIso8601($row['dateCreated'] ?? null),
            'dateUpdated' => $this->toIso8601($row['dateUpdated'] ?? null),
        ];
    }

    private function hydrateApproval(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'actionType' => (string)($row['actionType'] ?? ''),
            'actionRef' => $this->normalizeOptionalString($row['actionRef'] ?? null, 128),
            'status' => strtolower(trim((string)($row['status'] ?? self::APPROVAL_STATUS_PENDING))),
            'requestedBy' => (string)($row['requestedBy'] ?? ''),
            'decidedBy' => $this->normalizeOptionalString($row['decidedBy'] ?? null, 128),
            'reason' => $this->normalizeOptionalString($row['reason'] ?? null),
            'decisionReason' => $this->normalizeOptionalString($row['decisionReason'] ?? null),
            'idempotencyKey' => $this->normalizeOptionalString($row['idempotencyKey'] ?? null, 128),
            'requestPayload' => $this->decodeJsonArray((string)($row['requestPayload'] ?? '[]')),
            'metadata' => $this->decodeJsonArray((string)($row['metadata'] ?? '[]')),
            'decidedAt' => $this->toIso8601($row['decidedAt'] ?? null),
            'dateCreated' => $this->toIso8601($row['dateCreated'] ?? null),
            'dateUpdated' => $this->toIso8601($row['dateUpdated'] ?? null),
        ];
    }

    private function hydrateExecution(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'actionType' => (string)($row['actionType'] ?? ''),
            'actionRef' => $this->normalizeOptionalString($row['actionRef'] ?? null, 128),
            'status' => strtolower(trim((string)($row['status'] ?? self::EXECUTION_STATUS_PENDING))),
            'requestedBy' => (string)($row['requestedBy'] ?? ''),
            'requiredScope' => $this->normalizeOptionalString($row['requiredScope'] ?? null, 96),
            'approvalId' => isset($row['approvalId']) ? (int)$row['approvalId'] : null,
            'idempotencyKey' => (string)($row['idempotencyKey'] ?? ''),
            'requestPayload' => $this->decodeJsonArray((string)($row['requestPayload'] ?? '[]')),
            'resultPayload' => $this->decodeJsonArray((string)($row['resultPayload'] ?? '[]')),
            'errorMessage' => $this->normalizeOptionalString($row['errorMessage'] ?? null),
            'executedAt' => $this->toIso8601($row['executedAt'] ?? null),
            'dateCreated' => $this->toIso8601($row['dateCreated'] ?? null),
            'dateUpdated' => $this->toIso8601($row['dateUpdated'] ?? null),
        ];
    }

    private function hydrateAuditEvent(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'category' => (string)($row['category'] ?? ''),
            'action' => (string)($row['action'] ?? ''),
            'outcome' => (string)($row['outcome'] ?? 'info'),
            'actorType' => (string)($row['actorType'] ?? 'system'),
            'actorId' => $this->normalizeOptionalString($row['actorId'] ?? null, 128),
            'requestId' => $this->normalizeOptionalString($row['requestId'] ?? null, 128),
            'ipAddress' => $this->normalizeOptionalString($row['ipAddress'] ?? null, 64),
            'entityType' => $this->normalizeOptionalString($row['entityType'] ?? null, 64),
            'entityId' => $this->normalizeOptionalString($row['entityId'] ?? null, 128),
            'summary' => $this->normalizeOptionalString($row['summary'] ?? null, 255),
            'metadata' => $this->decodeJsonArray((string)($row['metadata'] ?? '[]')),
            'dateCreated' => $this->toIso8601($row['dateCreated'] ?? null),
            'dateUpdated' => $this->toIso8601($row['dateUpdated'] ?? null),
        ];
    }

    private function findApprovalById(int $approvalId): ?array
    {
        if ($approvalId <= 0 || !$this->tableExists(self::TABLE_APPROVALS)) {
            return null;
        }

        $row = (new Query())
            ->from(self::TABLE_APPROVALS)
            ->where(['id' => $approvalId])
            ->one();

        return is_array($row) ? $row : null;
    }

    private function findApprovalByIdempotencyKey(string $idempotencyKey): ?array
    {
        if ($idempotencyKey === '' || !$this->tableExists(self::TABLE_APPROVALS)) {
            return null;
        }

        $row = (new Query())
            ->from(self::TABLE_APPROVALS)
            ->where(['idempotencyKey' => $idempotencyKey])
            ->one();

        return is_array($row) ? $row : null;
    }

    private function findExecutionByIdempotencyKey(string $idempotencyKey): ?array
    {
        if ($idempotencyKey === '' || !$this->tableExists(self::TABLE_EXECUTIONS)) {
            return null;
        }

        $row = (new Query())
            ->from(self::TABLE_EXECUTIONS)
            ->where(['idempotencyKey' => $idempotencyKey])
            ->one();

        return is_array($row) ? $row : null;
    }

    private function countRows(string $table, array $conditions = []): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        $query = (new Query())
            ->from($table);

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return (int)$query->count('*');
    }

    private function tableExists(string $table): bool
    {
        return Craft::$app->getDb()->getTableSchema($table, true) !== null;
    }

    private function requireTable(string $table, string $message): void
    {
        if ($this->tableExists($table)) {
            return;
        }

        throw new \RuntimeException($message);
    }

    private function resolveActorId(array $actor): string
    {
        $candidate = trim((string)($actor['actorId'] ?? ''));
        if ($candidate !== '') {
            return substr($candidate, 0, 128);
        }

        return 'system';
    }

    private function normalizeHandle(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9:_\-.]+/', '-', $normalized) ?: '';
        return trim($normalized, '-');
    }

    private function normalizeActionPattern(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9:*_\-.]+/', '', $normalized) ?: '';
        return trim($normalized);
    }

    private function normalizeActionType(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9:_\-.]+/', '', $normalized) ?: '';
        return trim($normalized);
    }

    private function normalizeDisplayName(string $value, string $fallback): string
    {
        $displayName = trim($value);
        if ($displayName === '') {
            $displayName = $fallback;
        }

        if (strlen($displayName) > 255) {
            $displayName = substr($displayName, 0, 255);
        }

        return $displayName;
    }

    private function normalizeRiskLevel(string $value): string
    {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, [self::RISK_LEVEL_LOW, self::RISK_LEVEL_MEDIUM, self::RISK_LEVEL_HIGH, self::RISK_LEVEL_CRITICAL], true)) {
            return $normalized;
        }

        return self::RISK_LEVEL_MEDIUM;
    }

    private function normalizeBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int)$value) === 1;
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeDecisionStatus(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['approve', 'approved'], true)) {
            return self::APPROVAL_STATUS_APPROVED;
        }

        if (in_array($normalized, ['reject', 'rejected'], true)) {
            return self::APPROVAL_STATUS_REJECTED;
        }

        return null;
    }

    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function normalizeOptionalString(mixed $value, int $maxLength = 65535): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $normalized = trim((string)$value);
        if ($normalized === '') {
            return null;
        }

        if (strlen($normalized) > $maxLength) {
            $normalized = substr($normalized, 0, $maxLength);
        }

        return $normalized;
    }

    private function normalizeIdempotencyKey(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[^A-Za-z0-9._:-]+/', '-', $normalized) ?: '';
        $normalized = trim($normalized, '-');

        if (strlen($normalized) > 128) {
            $normalized = substr($normalized, 0, 128);
        }

        return $normalized;
    }

    private function normalizeLimit(int $limit, int $default): int
    {
        if ($limit <= 0) {
            return $default;
        }

        return min($limit, 200);
    }

    private function encodeJson(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return '{}';
        }

        return $encoded;
    }

    private function decodeJsonArray(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function toIso8601(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function matchesActionPattern(string $actionType, string $pattern): bool
    {
        $normalizedPattern = $this->normalizeActionPattern($pattern);
        if ($normalizedPattern === '') {
            return false;
        }

        if ($normalizedPattern === '*') {
            return true;
        }

        $escaped = preg_quote($normalizedPattern, '/');
        $regex = '/^' . str_replace('\\*', '.*', $escaped) . '$/';
        return preg_match($regex, $actionType) === 1;
    }
}
