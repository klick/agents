<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\StringHelper;
use Klick\Agents\Plugin;
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
    private const APPROVAL_STATUS_EXPIRED = 'expired';
    private const APPROVAL_ASSURANCE_METADATA_KEY = '_agentsApproval';
    private const APPROVAL_DRAFT_EXECUTION_METADATA_KEY = '_agentsDraftExecution';
    private const APPROVAL_ASSURANCE_SINGLE = 'single_approval';
    private const APPROVAL_ASSURANCE_DUAL = 'dual_control';
    private const APPROVAL_ASSURANCE_DEGRADED = 'single_operator_degraded';
    private const APPROVAL_ASSURANCE_REASON_NONE = 'none';
    private const APPROVAL_ASSURANCE_REASON_INSUFFICIENT_CP_USERS = 'insufficient_active_cp_users';

    private const EXECUTION_STATUS_PENDING = 'pending';
    private const EXECUTION_STATUS_BLOCKED = 'blocked';
    private const EXECUTION_STATUS_SUCCEEDED = 'succeeded';
    private const EXECUTION_STATUS_FAILED = 'failed';

    private const RISK_LEVEL_LOW = 'low';
    private const RISK_LEVEL_MEDIUM = 'medium';
    private const RISK_LEVEL_HIGH = 'high';
    private const RISK_LEVEL_CRITICAL = 'critical';
    private const ACTION_ENTRY_UPDATE_DRAFT = 'entry.updatedraft';

    public function getControlPlaneSnapshot(int $limit = 20): array
    {
        $this->applyApprovalEscalationRules(max(50, $this->normalizeLimit($limit * 2, 50)));

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
            'approvalsExpired' => $this->countRows(self::TABLE_APPROVALS, ['status' => self::APPROVAL_STATUS_EXPIRED]),
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
        $originalHandle = $this->normalizeHandle((string)($input['originalHandle'] ?? ''));
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

        $existing = null;
        if ($originalHandle !== '') {
            $existing = (new Query())
                ->from(self::TABLE_POLICIES)
                ->where(['handle' => $originalHandle])
                ->one();
        }

        if (!is_array($existing)) {
            $existing = (new Query())
                ->from(self::TABLE_POLICIES)
                ->where(['handle' => $handle])
                ->one();
        }

        if (is_array($existing)) {
            $existingId = (int)$existing['id'];
            $duplicateHandle = (new Query())
                ->from(self::TABLE_POLICIES)
                ->where(['handle' => $handle])
                ->andWhere(['not', ['id' => $existingId]])
                ->exists();

            if ($duplicateHandle) {
                throw new \InvalidArgumentException(sprintf('Policy handle `%s` is already in use.', $handle));
            }

            Craft::$app->getDb()->createCommand()->update(self::TABLE_POLICIES, [
                'handle' => $handle,
                'displayName' => $displayName,
                'actionPattern' => $actionPattern,
                'requiresApproval' => $requiresApproval,
                'enabled' => $enabled,
                'riskLevel' => $riskLevel,
                'config' => $encodedConfig,
                'dateUpdated' => $now,
            ], ['id' => $existingId])->execute();

            $policy = (new Query())
                ->from(self::TABLE_POLICIES)
                ->where(['id' => $existingId])
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

    public function deletePolicy(string $handle, array $actor = []): bool
    {
        $this->requireTable(self::TABLE_POLICIES, 'Policy storage table is unavailable. Run plugin migrations.');

        $normalizedHandle = $this->normalizeHandle($handle);
        if ($normalizedHandle === '') {
            throw new \InvalidArgumentException('Policy handle is required.');
        }

        $existing = (new Query())
            ->from(self::TABLE_POLICIES)
            ->where(['handle' => $normalizedHandle])
            ->one();

        if (!is_array($existing)) {
            return false;
        }

        $deleted = (int)Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE_POLICIES, ['id' => (int)$existing['id']])
            ->execute();

        if ($deleted < 1) {
            return false;
        }

        $this->writeAuditEvent([
            'category' => 'control.policy',
            'action' => 'delete',
            'outcome' => 'success',
            'actorType' => (string)($actor['actorType'] ?? 'system'),
            'actorId' => (string)($actor['actorId'] ?? 'system'),
            'requestId' => (string)($actor['requestId'] ?? ''),
            'ipAddress' => (string)($actor['ipAddress'] ?? ''),
            'entityType' => 'policy',
            'entityId' => (string)((int)$existing['id']),
            'summary' => sprintf('Deleted policy `%s`.', $normalizedHandle),
            'metadata' => [
                'handle' => $normalizedHandle,
                'actionPattern' => (string)($existing['actionPattern'] ?? ''),
            ],
        ]);

        return true;
    }

    public function getApprovals(array $filters = [], int $limit = 50): array
    {
        if (!$this->tableExists(self::TABLE_APPROVALS)) {
            return [];
        }

        $this->applyApprovalEscalationRules($this->normalizeLimit($limit, 50));

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

    public function getApprovalById(int $approvalId): ?array
    {
        $row = $this->findApprovalById($approvalId);
        return is_array($row) ? $this->hydrateApproval($row) : null;
    }

    public function applyApprovalEscalationRules(int $limit = 100): array
    {
        if (!$this->tableExists(self::TABLE_APPROVALS)) {
            return ['escalated' => 0, 'expired' => 0];
        }

        $rows = (new Query())
            ->from(self::TABLE_APPROVALS)
            ->where(['status' => self::APPROVAL_STATUS_PENDING])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($this->normalizeLimit($limit, 100))
            ->all();

        $escalated = 0;
        $expired = 0;
        $nowTs = time();
        $now = gmdate('Y-m-d H:i:s');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $createdTs = strtotime((string)($row['dateCreated'] ?? ''));
            if ($createdTs === false) {
                continue;
            }

            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $expireAfterMinutes = max(0, (int)($row['expireAfterMinutes'] ?? 0));
            $escalateAfterMinutes = max(0, (int)($row['escalateAfterMinutes'] ?? 0));
            $escalatedAt = $this->normalizeOptionalString($row['escalatedAt'] ?? null);
            $expiredAt = $this->normalizeOptionalString($row['expiredAt'] ?? null);

            if ($expiredAt !== null && $expiredAt !== '') {
                continue;
            }

            if ($expireAfterMinutes > 0 && $nowTs >= ($createdTs + ($expireAfterMinutes * 60))) {
                Craft::$app->getDb()->createCommand()->update(self::TABLE_APPROVALS, [
                    'status' => self::APPROVAL_STATUS_EXPIRED,
                    'expiredAt' => $now,
                    'dateUpdated' => $now,
                ], ['id' => $id])->execute();

                $expired++;
                $this->writeAuditEvent([
                    'category' => 'control.approval',
                    'action' => 'auto-expire',
                    'outcome' => 'warning',
                    'actorType' => 'system',
                    'actorId' => 'system:sla',
                    'entityType' => 'approval',
                    'entityId' => (string)$id,
                    'summary' => sprintf('Approval `%d` auto-expired after SLA timeout.', $id),
                    'metadata' => [
                        'expireAfterMinutes' => $expireAfterMinutes,
                    ],
                ]);
                continue;
            }

            if ($escalateAfterMinutes > 0 && ($escalatedAt === null || $escalatedAt === '') && $nowTs >= ($createdTs + ($escalateAfterMinutes * 60))) {
                Craft::$app->getDb()->createCommand()->update(self::TABLE_APPROVALS, [
                    'escalatedAt' => $now,
                    'dateUpdated' => $now,
                ], ['id' => $id])->execute();

                $escalated++;
                $this->writeAuditEvent([
                    'category' => 'control.approval',
                    'action' => 'auto-escalate',
                    'outcome' => 'warning',
                    'actorType' => 'system',
                    'actorId' => 'system:sla',
                    'entityType' => 'approval',
                    'entityId' => (string)$id,
                    'summary' => sprintf('Approval `%d` auto-escalated after SLA threshold.', $id),
                    'metadata' => [
                        'escalateAfterMinutes' => $escalateAfterMinutes,
                    ],
                ]);
            }
        }

        return [
            'escalated' => $escalated,
            'expired' => $expired,
        ];
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
        $policy = $this->resolvePolicyForAction($actionType);
        $slaPolicy = $this->resolveApprovalSlaPolicy($policy);
        $now = gmdate('Y-m-d H:i:s');
        $approvalRequirement = $this->resolveApprovalRequirement($actionType, $payload, $metadata, $policy);
        $requiredApprovals = (int)$approvalRequirement['requiredApprovals'];
        $metadata = $this->decorateApprovalMetadata($metadata, $approvalRequirement, $now);

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
            'requiredApprovals' => $requiredApprovals,
            'slaDueAt' => $slaPolicy['slaDueAt'],
            'escalateAfterMinutes' => $slaPolicy['escalateAfterMinutes'],
            'expireAfterMinutes' => $slaPolicy['expireAfterMinutes'],
            'secondaryDecisionBy' => null,
            'secondaryDecisionReason' => null,
            'secondaryDecisionAt' => null,
            'escalatedAt' => null,
            'expiredAt' => null,
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
                'requiredApprovals' => $requiredApprovals,
                'assuranceMode' => (string)$approvalRequirement['assuranceMode'],
                'assuranceReason' => (string)$approvalRequirement['assuranceReason'],
                'assuranceActiveCpUserCount' => (int)$approvalRequirement['activeCpUserCount'],
                'policyHandle' => (string)($policy['handle'] ?? 'default'),
                'slaDueAt' => $slaPolicy['slaDueAt'],
                'escalateAfterMinutes' => $slaPolicy['escalateAfterMinutes'],
                'expireAfterMinutes' => $slaPolicy['expireAfterMinutes'],
            ],
        ]);

        try {
            Plugin::getInstance()->getNotificationService()->queueApprovalRequested($hydrated);
        } catch (\Throwable $e) {
            Craft::warning('Unable to queue approval-request notification: ' . $e->getMessage(), __METHOD__);
        }

        return $hydrated;
    }

    public function decideApproval(int $approvalId, string $decision, ?string $decisionReason, array $actor = []): ?array
    {
        if ($approvalId <= 0 || !$this->tableExists(self::TABLE_APPROVALS)) {
            return null;
        }

        $status = $this->normalizeDecisionStatus($decision);
        if ($status === null) {
            throw new \InvalidArgumentException('Decision must be `approved` or `rejected`.');
        }

        $resolvedDecisionReason = $this->normalizeOptionalString($decisionReason);
        $decidedBy = $this->resolveActorId($actor);
        // Optimistic concurrency loop: prevents parallel first-approval writers
        // from clobbering each other and allows clean promotion to second approval.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $existing = $this->findApprovalById($approvalId);
            if (!is_array($existing)) {
                return null;
            }

            $currentStatus = strtolower(trim((string)($existing['status'] ?? '')));
            if ($currentStatus !== self::APPROVAL_STATUS_PENDING) {
                return $this->hydrateApproval($existing);
            }

            $now = gmdate('Y-m-d H:i:s');
            $requiredApprovals = max(1, (int)($existing['requiredApprovals'] ?? 1));
            $primaryDecider = $this->normalizeOptionalString($existing['decidedBy'] ?? null, 128);
            $secondaryDecider = $this->normalizeOptionalString($existing['secondaryDecisionBy'] ?? null, 128);
            $requestedBy = $this->normalizeOptionalString($existing['requestedBy'] ?? null, 128);
            $assurance = $this->extractApprovalAssurance(
                $this->decodeJsonArray((string)($existing['metadata'] ?? '[]')),
                $requiredApprovals
            );
            $updateData = [];
            $updateWhere = [
                'id' => $approvalId,
                'status' => self::APPROVAL_STATUS_PENDING,
            ];
            $auditOutcome = 'success';
            $auditSummary = sprintf('Approval `%d` marked `%s`.', $approvalId, self::APPROVAL_STATUS_APPROVED);
            $auditMetadata = [
                'decision' => $status,
                'decisionReason' => $resolvedDecisionReason,
                'requiredApprovals' => $requiredApprovals,
                'assuranceMode' => (string)$assurance['mode'],
                'assuranceReason' => (string)$assurance['reason'],
            ];

            if (
                $assurance['mode'] !== self::APPROVAL_ASSURANCE_DEGRADED
                && $requestedBy !== null
                && $requestedBy !== ''
                && $requestedBy === $decidedBy
            ) {
                throw new \InvalidArgumentException('Approval must be decided by a different actor than the requester.');
            }

            if ($status === self::APPROVAL_STATUS_REJECTED) {
                $updateData = [
                    'status' => self::APPROVAL_STATUS_REJECTED,
                    'decidedBy' => $primaryDecider ?: $decidedBy,
                    'decisionReason' => $resolvedDecisionReason,
                    'decidedAt' => $now,
                    'secondaryDecisionBy' => null,
                    'secondaryDecisionReason' => null,
                    'secondaryDecisionAt' => null,
                    'dateUpdated' => $now,
                ];
                $auditOutcome = 'warning';
                $auditSummary = sprintf('Approval `%d` rejected.', $approvalId);
            } else {
                $updateData = [
                    'status' => self::APPROVAL_STATUS_APPROVED,
                    'dateUpdated' => $now,
                ];

                if ($requiredApprovals <= 1) {
                    $updateData['decidedBy'] = $decidedBy;
                    $updateData['decisionReason'] = $resolvedDecisionReason;
                    $updateData['decidedAt'] = $now;
                } elseif ($primaryDecider === null || $primaryDecider === '') {
                    $updateData['status'] = self::APPROVAL_STATUS_PENDING;
                    $updateData['decidedBy'] = $decidedBy;
                    $updateData['decisionReason'] = $resolvedDecisionReason;
                    $updateData['decidedAt'] = $now;
                    $updateWhere['decidedBy'] = null;
                    $updateWhere['secondaryDecisionBy'] = null;
                    $auditOutcome = 'info';
                    $auditSummary = sprintf('Approval `%d` recorded first approval from `%s`; awaiting second approver.', $approvalId, $decidedBy);
                    $auditMetadata['stage'] = 'first-approval';
                } else {
                    if ($primaryDecider === $decidedBy || ($secondaryDecider !== null && $secondaryDecider !== '' && $secondaryDecider === $decidedBy)) {
                        throw new \InvalidArgumentException('Second approval must be made by a different approver.');
                    }

                    $updateData['secondaryDecisionBy'] = $decidedBy;
                    $updateData['secondaryDecisionReason'] = $resolvedDecisionReason;
                    $updateData['secondaryDecisionAt'] = $now;
                    $updateData['decidedAt'] = $existing['decidedAt'] ?? $now;
                    $updateWhere['decidedBy'] = $primaryDecider;
                    $updateWhere['secondaryDecisionBy'] = null;
                    $auditMetadata['stage'] = 'second-approval';
                }
            }

            $updatedRows = Craft::$app->getDb()->createCommand()->update(self::TABLE_APPROVALS, $updateData, $updateWhere)->execute();
            if ($updatedRows < 1) {
                continue;
            }

            $updated = $this->findApprovalById($approvalId);
            if (!is_array($updated)) {
                return null;
            }

            $hydrated = $this->hydrateApproval($updated);
            $this->writeAuditEvent([
                'category' => 'control.approval',
                'action' => 'decision',
                'outcome' => $auditOutcome,
                'actorType' => (string)($actor['actorType'] ?? 'system'),
                'actorId' => $decidedBy,
                'requestId' => (string)($actor['requestId'] ?? ''),
                'ipAddress' => (string)($actor['ipAddress'] ?? ''),
                'entityType' => 'approval',
                'entityId' => (string)$approvalId,
                'summary' => $auditSummary,
                'metadata' => $auditMetadata,
            ]);

            if ((string)($hydrated['status'] ?? self::APPROVAL_STATUS_PENDING) !== self::APPROVAL_STATUS_PENDING) {
                try {
                    Plugin::getInstance()->getNotificationService()->queueApprovalDecided($hydrated);
                } catch (\Throwable $e) {
                    Craft::warning('Unable to queue approval-decision notification: ' . $e->getMessage(), __METHOD__);
                }
            }

            return $hydrated;
        }

        $latest = $this->findApprovalById($approvalId);
        return is_array($latest) ? $this->hydrateApproval($latest) : null;
    }

    public function executeApprovedActionFromApprovalId(int $approvalId, array $actor = []): array
    {
        if ($approvalId <= 0) {
            throw new \InvalidArgumentException('Missing request number.');
        }

        $approvalRow = $this->findApprovalById($approvalId);
        if (!is_array($approvalRow)) {
            throw new \InvalidArgumentException(sprintf('Request #%d was not found.', $approvalId));
        }

        $approval = $this->hydrateApproval($approvalRow);
        $approvalStatus = strtolower(trim((string)($approval['status'] ?? self::APPROVAL_STATUS_PENDING)));
        if ($approvalStatus !== self::APPROVAL_STATUS_APPROVED) {
            throw new \InvalidArgumentException(sprintf(
                'Request #%d is `%s` and cannot be run.',
                $approvalId,
                $approvalStatus !== '' ? $approvalStatus : self::APPROVAL_STATUS_PENDING
            ));
        }

        $idempotencyKey = $this->normalizeIdempotencyKey((string)($approval['idempotencyKey'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = sprintf('approval-%d-exec-v1', $approvalId);
        }

        $payload = (array)($approval['requestPayload'] ?? []);
        if (
            (string)($approval['actionType'] ?? '') === self::ACTION_ENTRY_UPDATE_DRAFT &&
            !isset($payload['draftId']) &&
            isset($approval['boundDraftId']) &&
            (int)$approval['boundDraftId'] > 0
        ) {
            $payload['draftId'] = (int)$approval['boundDraftId'];
        }

        return $this->executeAction([
            'actionType' => (string)($approval['actionType'] ?? ''),
            'actionRef' => (string)($approval['actionRef'] ?? ''),
            'approvalId' => $approvalId,
            'idempotencyKey' => $idempotencyKey,
            'payload' => $payload,
        ], $actor);
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

        if (isset($filters['approvalId'])) {
            $approvalId = (int)$filters['approvalId'];
            if ($approvalId > 0) {
                $query->andWhere(['approvalId' => $approvalId]);
            }
        }

        $rows = $query->all();
        return array_map(fn(array $row) => $this->hydrateExecution($row), $rows);
    }

    public function getLatestExecutionForApproval(int $approvalId): ?array
    {
        if ($approvalId <= 0) {
            return null;
        }

        $executions = $this->getExecutions(['approvalId' => $approvalId], 1);
        return is_array($executions[0] ?? null) ? $executions[0] : null;
    }

    public function simulateAction(array $input): array
    {
        $actionType = $this->normalizeActionType((string)($input['actionType'] ?? ''));
        if ($actionType === '') {
            throw new \InvalidArgumentException('Simulation action type is required.');
        }

        $actionRef = $this->normalizeOptionalString($input['actionRef'] ?? null, 128);
        $payload = $this->normalizeArray($input['payload'] ?? []);
        $approvalId = isset($input['approvalId']) ? (int)$input['approvalId'] : 0;
        $forceHumanApproval = (bool)($input['forceHumanApproval'] ?? false);

        $policy = $this->resolvePolicyForAction($actionType);
        $requiredScope = $this->normalizeOptionalString($policy['requiredScope'] ?? null, 96);

        $status = 'allowed';
        $reasons = [];
        $resolvedApprovalId = null;
        $approvalStatus = 'not_required';

        if (!(bool)($policy['enabled'] ?? true)) {
            $status = 'blocked';
            $reasons[] = 'Matched policy is disabled for execution.';
        }

        $requiresApproval = (bool)($policy['requiresApproval'] ?? true) || $forceHumanApproval;
        if ($requiresApproval) {
            $approvalStatus = 'required';
            if ($approvalId <= 0) {
                if ($status !== 'blocked') {
                    $status = 'requires_approval';
                }
                $reasons[] = $forceHumanApproval
                    ? 'This account requires human approval before execution.'
                    : 'Approval is required before execution.';
            } else {
                $approval = $this->findApprovalById($approvalId);
                if (!is_array($approval)) {
                    $status = 'blocked';
                    $reasons[] = 'Linked approval could not be found.';
                } else {
                    $resolvedApprovalId = (int)($approval['id'] ?? 0);
                    $approvalStatus = strtolower(trim((string)($approval['status'] ?? self::APPROVAL_STATUS_PENDING)));
                    if ($approvalStatus !== self::APPROVAL_STATUS_APPROVED) {
                        $status = 'blocked';
                        if ($approvalStatus === self::APPROVAL_STATUS_EXPIRED) {
                            $reasons[] = 'Linked approval has expired.';
                        } else {
                            $reasons[] = 'Linked approval is not approved.';
                        }
                    } elseif ((string)($approval['actionType'] ?? '') !== $actionType) {
                        $status = 'blocked';
                        $reasons[] = 'Linked approval action type mismatch.';
                    }
                }
            }
        }

        if (empty($reasons) && $status !== 'allowed') {
            $reasons[] = 'Execution is blocked by current policy evaluation.';
        }

        return [
            'simulation' => true,
            'actionType' => $actionType,
            'actionRef' => $actionRef,
            'requiredScope' => $requiredScope,
            'policy' => $policy,
            'approvalId' => $resolvedApprovalId,
            'requestPayload' => $payload,
            'evaluation' => [
                'status' => $status,
                'approvalStatus' => $approvalStatus,
                'wouldExecute' => $status === 'allowed',
                'reasons' => $reasons,
                'forceHumanApproval' => $forceHumanApproval,
            ],
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
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
        $forceHumanApproval = (bool)($input['forceHumanApproval'] ?? false);

        $policy = $this->resolvePolicyForAction($actionType);
        $requiredScope = $this->normalizeOptionalString($policy['requiredScope'] ?? null, 96);

        $status = self::EXECUTION_STATUS_PENDING;
        $errorMessage = null;
        $resultPayload = $this->recordOnlyResultPayload($policy);

        if (!(bool)($policy['enabled'] ?? true)) {
            $status = self::EXECUTION_STATUS_BLOCKED;
            $errorMessage = 'Matched policy is disabled for execution.';
            $resultPayload['message'] = $errorMessage;
        }

        $approvalEntityId = null;
        $requiresApproval = (bool)($policy['requiresApproval'] ?? true) || $forceHumanApproval;
        if ($status === self::EXECUTION_STATUS_PENDING && $requiresApproval) {
            $approval = $approvalId > 0 ? $this->findApprovalById($approvalId) : null;
            if (!is_array($approval)) {
                $status = self::EXECUTION_STATUS_BLOCKED;
                $errorMessage = $forceHumanApproval
                    ? 'This account requires human approval before execution.'
                    : 'Approval is required before execution.';
                $resultPayload['message'] = $errorMessage;
            } else {
                $approvalEntityId = (int)($approval['id'] ?? 0);
                $approvalStatus = strtolower(trim((string)($approval['status'] ?? '')));
                if ($approvalStatus !== self::APPROVAL_STATUS_APPROVED) {
                    $status = self::EXECUTION_STATUS_BLOCKED;
                    if ($approvalStatus === self::APPROVAL_STATUS_EXPIRED) {
                        $errorMessage = 'Linked approval has expired.';
                    } else {
                        $errorMessage = 'Linked approval is not approved.';
                    }
                    $resultPayload['message'] = $errorMessage;
                } elseif ((string)($approval['actionType'] ?? '') !== $actionType) {
                    $status = self::EXECUTION_STATUS_BLOCKED;
                    $errorMessage = 'Linked approval action type mismatch.';
                    $resultPayload['message'] = $errorMessage;
                }
            }
        }

        if ($status === self::EXECUTION_STATUS_PENDING) {
            $executionOutcome = $this->executeActionPayload($actionType, $actionRef, $payload, $policy);
            $status = (string)($executionOutcome['status'] ?? self::EXECUTION_STATUS_SUCCEEDED);
            $errorMessage = $this->normalizeOptionalString($executionOutcome['errorMessage'] ?? null);
            $resultPayloadCandidate = $executionOutcome['resultPayload'] ?? null;
            if (is_array($resultPayloadCandidate) && !empty($resultPayloadCandidate)) {
                $resultPayload = $resultPayloadCandidate;
            }
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

        if ($approvalEntityId !== null && $approvalEntityId > 0) {
            try {
                $this->syncApprovalDraftExecutionMetadata($approvalEntityId, $hydrated);
            } catch (\Throwable $e) {
                Craft::warning('Unable to sync approval draft execution metadata: ' . $e->getMessage(), __METHOD__);
            }
        }

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

        if (in_array($status, [self::EXECUTION_STATUS_BLOCKED, self::EXECUTION_STATUS_FAILED], true)) {
            try {
                Plugin::getInstance()->getNotificationService()->queueExecutionIssue($hydrated);
            } catch (\Throwable $e) {
                Craft::warning('Unable to queue execution-issue notification: ' . $e->getMessage(), __METHOD__);
            }
        }

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
            return $this->defaultPolicy($normalizedActionType);
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
            return $this->defaultPolicy($normalizedActionType);
        }

        $config = $this->normalizeArray($matched['config'] ?? []);
        $requiredScope = $this->normalizeOptionalString($config['requiredScope'] ?? null, 96);
        if ($requiredScope === null || $requiredScope === '') {
            $requiredScope = $this->defaultRequiredScopeForAction($normalizedActionType);
        }

        $matched['requiredScope'] = $requiredScope;
        return $matched;
    }

    private function executeActionPayload(string $actionType, ?string $actionRef, array $payload, array $policy): array
    {
        if ($actionType === self::ACTION_ENTRY_UPDATE_DRAFT) {
            return $this->executeEntryUpdateDraftAction($actionType, $actionRef, $payload, $policy);
        }

        return [
            'status' => self::EXECUTION_STATUS_SUCCEEDED,
            'errorMessage' => null,
            'resultPayload' => $this->recordOnlyResultPayload($policy),
        ];
    }

    private function executeEntryUpdateDraftAction(string $actionType, ?string $actionRef, array $payload, array $policy): array
    {
        $entryId = isset($payload['entryId']) ? (int)$payload['entryId'] : 0;
        if ($entryId <= 0) {
            throw new \InvalidArgumentException('payload.entryId is required and must be a positive integer for actionType `entry.updateDraft`.');
        }

        $siteId = isset($payload['siteId']) ? (int)$payload['siteId'] : 0;
        if (isset($payload['siteId']) && $siteId <= 0) {
            throw new \InvalidArgumentException('payload.siteId must be a positive integer when provided.');
        }

        $draftId = isset($payload['draftId']) ? (int)$payload['draftId'] : 0;
        if (isset($payload['draftId']) && $draftId <= 0) {
            throw new \InvalidArgumentException('payload.draftId must be a positive integer when provided.');
        }

        $titleProvided = array_key_exists('title', $payload);
        $slugProvided = array_key_exists('slug', $payload);
        $draftNameProvided = array_key_exists('draftName', $payload);
        $draftNotesProvided = array_key_exists('draftNotes', $payload);
        $fieldsProvided = array_key_exists('fields', $payload);

        $title = $this->normalizePayloadString($payload['title'] ?? null, 'payload.title');
        $slug = $this->normalizePayloadString($payload['slug'] ?? null, 'payload.slug');
        $draftName = $this->normalizePayloadString($payload['draftName'] ?? null, 'payload.draftName');
        $draftNotes = $this->normalizePayloadString($payload['draftNotes'] ?? null, 'payload.draftNotes');

        $fieldValues = [];
        if ($fieldsProvided) {
            if (!is_array($payload['fields'])) {
                throw new \InvalidArgumentException('payload.fields must be an object/map when provided.');
            }
            $fieldValues = $payload['fields'];
        }

        if (
            !$titleProvided &&
            !$slugProvided &&
            !$draftNameProvided &&
            !$draftNotesProvided &&
            !$fieldsProvided
        ) {
            throw new \InvalidArgumentException('payload must include at least one of: title, slug, draftName, draftNotes, or fields.');
        }

        $canonicalQuery = Entry::find()
            ->id($entryId)
            ->canonicalsOnly()
            ->status(null);

        if ($siteId > 0) {
            $canonicalQuery->siteId($siteId);
        } else {
            $canonicalQuery->site('*');
        }

        /** @var Entry|null $canonical */
        $canonical = $canonicalQuery->one();
        if (!$canonical instanceof Entry) {
            throw new \InvalidArgumentException('payload.entryId references an unknown canonical entry.');
        }

        $createdDraft = false;
        if ($draftId > 0) {
            $draftQuery = Entry::find()
                ->id($draftId)
                ->drafts(true)
                ->savedDraftsOnly()
                ->status(null);

            if ($siteId > 0) {
                $draftQuery->siteId($siteId);
            } else {
                $draftQuery->site('*');
            }

            /** @var Entry|null $draft */
            $draft = $draftQuery->one();
            if (!$draft instanceof Entry || !$draft->getIsDraft()) {
                throw new \InvalidArgumentException('payload.draftId must reference an existing draft entry.');
            }
            if ((int)$draft->getCanonicalId() !== (int)$canonical->id) {
                throw new \InvalidArgumentException('payload.draftId does not belong to the provided payload.entryId canonical entry.');
            }
        } else {
            $conflictingSavedDrafts = $this->findConflictingSavedDraftsForCanonical($canonical, $siteId);
            if (!empty($conflictingSavedDrafts)) {
                $primaryConflict = $conflictingSavedDrafts[0];
                $conflictDraftId = (int)($primaryConflict['id'] ?? 0);
                $conflictSiteId = (int)($primaryConflict['siteId'] ?? 0);
                $conflictTitle = trim((string)($primaryConflict['title'] ?? ''));
                $conflictName = trim((string)($primaryConflict['draftName'] ?? ''));
                $conflictUpdatedAt = trim((string)($primaryConflict['dateUpdated'] ?? ''));
                $conflictLabelParts = [];
                if ($conflictDraftId > 0) {
                    $conflictLabelParts[] = sprintf('draft #%d', $conflictDraftId);
                }
                if ($conflictName !== '') {
                    $conflictLabelParts[] = sprintf('name "%s"', $conflictName);
                }
                if ($conflictTitle !== '') {
                    $conflictLabelParts[] = sprintf('title "%s"', $conflictTitle);
                }
                if ($conflictSiteId > 0) {
                    $conflictLabelParts[] = sprintf('site %d', $conflictSiteId);
                }
                if ($conflictUpdatedAt !== '') {
                    $conflictLabelParts[] = sprintf('updated %s', $conflictUpdatedAt);
                }

                $errorMessage = 'Draft conflict: canonical entry already has an active saved draft.';
                if (!empty($conflictLabelParts)) {
                    $errorMessage .= ' Existing ' . implode(' · ', $conflictLabelParts) . '.';
                }
                $errorMessage .= ' Provide payload.draftId to target an exact saved draft.';

                return [
                    'status' => self::EXECUTION_STATUS_BLOCKED,
                    'errorMessage' => $errorMessage,
                    'resultPayload' => [
                        'executionMode' => 'entry_draft_update',
                        'message' => $errorMessage,
                        'policyHandle' => (string)($policy['handle'] ?? 'default'),
                        'riskLevel' => (string)($policy['riskLevel'] ?? self::RISK_LEVEL_HIGH),
                        'requiresApproval' => (bool)($policy['requiresApproval'] ?? true),
                        'actionType' => $actionType,
                        'actionRef' => $actionRef,
                        'entryId' => (int)$canonical->id,
                        'siteId' => $siteId > 0 ? $siteId : (int)($canonical->siteId ?? 0),
                        'createdDraft' => false,
                        'conflictType' => 'savedDraftExists',
                        'conflictingDrafts' => $conflictingSavedDrafts,
                    ],
                ];
            }

            /** @var Entry $draft */
            $draft = Craft::$app->getDrafts()->createDraft(
                $canonical,
                creatorId: null,
                name: $draftNameProvided ? $draftName : null,
                notes: $draftNotesProvided ? $draftNotes : null,
                newAttributes: [],
                provisional: false,
            );
            $createdDraft = true;
        }

        $changedKeys = [];
        if ($titleProvided) {
            $draft->title = $title;
            $changedKeys[] = 'title';
        }
        if ($slugProvided) {
            $draft->slug = $slug;
            $changedKeys[] = 'slug';
        }
        if ($draftNameProvided) {
            if (!$createdDraft) {
                $draft->draftName = $draftName;
            }
            $changedKeys[] = 'draftName';
        }
        if ($draftNotesProvided) {
            if (!$createdDraft) {
                $draft->draftNotes = $draftNotes;
            }
            $changedKeys[] = 'draftNotes';
        }
        if ($fieldsProvided) {
            $fieldUpdateResult = $this->prepareEntryFieldValues($draft, $fieldValues);
            if (!empty($fieldUpdateResult['unknownFieldHandles'])) {
                $unknown = implode('`, `', (array)$fieldUpdateResult['unknownFieldHandles']);
                throw new \InvalidArgumentException(sprintf('payload.fields contains unknown field handles: `%s`.', $unknown));
            }
            if (!empty($fieldUpdateResult['fieldValues'])) {
                $draft->setFieldValues((array)$fieldUpdateResult['fieldValues']);
                foreach ((array)$fieldUpdateResult['fieldValues'] as $handle => $_value) {
                    $changedKeys[] = 'fields.' . $handle;
                }
            }
        }

        if (empty($changedKeys)) {
            throw new \InvalidArgumentException('payload does not contain any effective draft mutations.');
        }

        $saveSuccess = Craft::$app->getElements()->saveElement(
            $draft,
            propagate: $siteId <= 0,
            saveContent: true,
        );
        if (!$saveSuccess) {
            $validationErrors = $this->normalizeElementErrors($draft->getErrors());
            $firstError = !empty($validationErrors) ? (string)$validationErrors[0] : 'Draft update failed validation.';

            return [
                'status' => self::EXECUTION_STATUS_FAILED,
                'errorMessage' => $firstError,
                'resultPayload' => [
                    'executionMode' => 'entry_draft_update',
                    'message' => 'Draft update failed validation.',
                    'policyHandle' => (string)($policy['handle'] ?? 'default'),
                    'riskLevel' => (string)($policy['riskLevel'] ?? self::RISK_LEVEL_HIGH),
                    'requiresApproval' => (bool)($policy['requiresApproval'] ?? true),
                    'actionType' => $actionType,
                    'actionRef' => $actionRef,
                    'entryId' => (int)$canonical->id,
                    'draftId' => (int)($draft->id ?? 0),
                    'draftRecordId' => (int)($draft->draftId ?? 0),
                    'siteId' => (int)($draft->siteId ?? 0),
                    'createdDraft' => $createdDraft,
                    'changedKeys' => array_values(array_unique($changedKeys)),
                    'validationErrors' => $validationErrors,
                ],
            ];
        }

        return [
            'status' => self::EXECUTION_STATUS_SUCCEEDED,
            'errorMessage' => null,
            'resultPayload' => [
                'executionMode' => 'entry_draft_update',
                'message' => 'Draft updated successfully.',
                'policyHandle' => (string)($policy['handle'] ?? 'default'),
                'riskLevel' => (string)($policy['riskLevel'] ?? self::RISK_LEVEL_HIGH),
                'requiresApproval' => (bool)($policy['requiresApproval'] ?? true),
                'actionType' => $actionType,
                'actionRef' => $actionRef,
                'entryId' => (int)$canonical->id,
                'draftId' => (int)$draft->id,
                'draftRecordId' => (int)($draft->draftId ?? 0),
                'siteId' => (int)($draft->siteId ?? 0),
                'draftName' => $this->extractDraftName($draft),
                'createdDraft' => $createdDraft,
                'changedKeys' => array_values(array_unique($changedKeys)),
            ],
        ];
    }

    private function prepareEntryFieldValues(Entry $draft, array $rawFieldValues): array
    {
        $allowedFieldHandles = [];
        $fieldLayout = $draft->getFieldLayout();
        if ($fieldLayout !== null) {
            foreach ($fieldLayout->getCustomFields() as $field) {
                $handle = trim((string)$field->handle);
                if ($handle !== '') {
                    $allowedFieldHandles[] = $handle;
                }
            }
        }

        $allowedFieldHandles = array_values(array_unique($allowedFieldHandles));
        $fieldValues = [];
        $unknownFieldHandles = [];
        foreach ($rawFieldValues as $fieldHandle => $value) {
            if (!is_string($fieldHandle) && !is_numeric($fieldHandle)) {
                throw new \InvalidArgumentException('payload.fields keys must be field handles.');
            }
            $normalizedHandle = trim((string)$fieldHandle);
            if ($normalizedHandle === '') {
                continue;
            }
            if (!in_array($normalizedHandle, $allowedFieldHandles, true)) {
                $unknownFieldHandles[] = $normalizedHandle;
                continue;
            }
            $fieldValues[$normalizedHandle] = $value;
        }

        return [
            'fieldValues' => $fieldValues,
            'unknownFieldHandles' => array_values(array_unique($unknownFieldHandles)),
        ];
    }

    private function findConflictingSavedDraftsForCanonical(Entry $canonical, int $preferredSiteId = 0): array
    {
        $query = Entry::find()
            ->draftOf($canonical)
            ->savedDraftsOnly()
            ->status(null);

        if ($preferredSiteId > 0) {
            $query->siteId($preferredSiteId);
        } else {
            $query->site('*');
        }

        /** @var Entry[] $drafts */
        $drafts = $query
            ->orderBy(['elements.dateUpdated' => SORT_DESC])
            ->all();

        $conflicts = [];
        foreach ($drafts as $draft) {
            if (!$draft instanceof Entry || !$draft->getIsDraft()) {
                continue;
            }

            $conflicts[] = [
                'id' => (int)$draft->id,
                'draftRecordId' => (int)($draft->draftId ?? 0),
                'siteId' => (int)($draft->siteId ?? 0),
                'draftName' => $this->extractDraftName($draft),
                'title' => trim((string)$draft->title) !== '' ? trim((string)$draft->title) : null,
                'cpEditUrl' => trim((string)$draft->getCpEditUrl()) !== '' ? trim((string)$draft->getCpEditUrl()) : null,
                'dateUpdated' => $draft->dateUpdated?->format('Y-m-d H:i:s'),
            ];
        }

        return $conflicts;
    }

    private function normalizePayloadString(mixed $value, string $path): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string when provided.', $path));
        }

        return trim((string)$value);
    }

    private function normalizeElementErrors(array $errors): array
    {
        $messages = [];
        foreach ($errors as $attribute => $attributeErrors) {
            if (!is_array($attributeErrors)) {
                continue;
            }
            foreach ($attributeErrors as $errorMessage) {
                if (!is_string($errorMessage) && !is_numeric($errorMessage)) {
                    continue;
                }
                $error = trim((string)$errorMessage);
                if ($error === '') {
                    continue;
                }
                $attributeName = trim((string)$attribute);
                $messages[] = $attributeName !== '' ? sprintf('%s: %s', $attributeName, $error) : $error;
            }
        }

        return array_values(array_unique($messages));
    }

    private function extractDraftName(Entry $draft): ?string
    {
        try {
            $name = trim((string)$draft->getDraftName());
            return $name !== '' ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function recordOnlyResultPayload(array $policy): array
    {
        return [
            'executionMode' => 'record_only',
            'message' => 'Action execution recorded. Integrate downstream adapters for side effects.',
            'policyHandle' => (string)($policy['handle'] ?? 'default'),
            'riskLevel' => (string)($policy['riskLevel'] ?? self::RISK_LEVEL_HIGH),
            'requiresApproval' => (bool)($policy['requiresApproval'] ?? true),
        ];
    }

    private function defaultRequiredScopeForAction(string $actionType): string
    {
        return match ($actionType) {
            self::ACTION_ENTRY_UPDATE_DRAFT => 'entries:write:draft',
            default => 'control:actions:execute',
        };
    }

    private function resolveApprovalRequirement(string $actionType, array $payload, array $metadata, array $policy): array
    {
        $required = 1;
        $riskLevel = strtolower(trim((string)($policy['riskLevel'] ?? self::RISK_LEVEL_MEDIUM)));
        if (in_array($riskLevel, [self::RISK_LEVEL_HIGH, self::RISK_LEVEL_CRITICAL], true)) {
            $required = 2;
        }

        $config = $this->normalizeArray($policy['config'] ?? []);
        $configRequired = isset($config['requiredApprovals']) ? (int)$config['requiredApprovals'] : 0;
        if ($configRequired >= 2) {
            $required = 2;
        }

        if ((bool)($config['twoPersonApproval'] ?? false)) {
            $required = 2;
        }

        $amountThreshold = $config['dualApprovalMinAmount'] ?? null;
        if (is_numeric($amountThreshold)) {
            $amount = $this->extractApprovalAmount($payload, $metadata);
            if ($amount !== null && $amount >= (float)$amountThreshold) {
                $required = 2;
            }
        }

        $category = $this->extractApprovalCategory($payload, $metadata);
        $categories = $this->normalizeStringArray($config['dualApprovalCategories'] ?? []);
        if ($category !== null && in_array($category, $categories, true)) {
            $required = 2;
        }

        $actionPatterns = $this->normalizeStringArray($config['dualApprovalActionPatterns'] ?? []);
        foreach ($actionPatterns as $pattern) {
            if ($this->matchesActionPattern($actionType, $pattern)) {
                $required = 2;
                break;
            }
        }

        $activeCpUserCount = $this->getActiveCpUserCount();
        $assuranceMode = $required > 1 ? self::APPROVAL_ASSURANCE_DUAL : self::APPROVAL_ASSURANCE_SINGLE;
        $assuranceReason = self::APPROVAL_ASSURANCE_REASON_NONE;
        if ($required > 1 && $activeCpUserCount < 2) {
            $required = 1;
            $assuranceMode = self::APPROVAL_ASSURANCE_DEGRADED;
            $assuranceReason = self::APPROVAL_ASSURANCE_REASON_INSUFFICIENT_CP_USERS;
        }

        return [
            'requiredApprovals' => min(2, max(1, $required)),
            'assuranceMode' => $assuranceMode,
            'assuranceReason' => $assuranceReason,
            'activeCpUserCount' => $activeCpUserCount,
        ];
    }

    private function resolveRequiredApprovals(string $actionType, array $payload, array $metadata, array $policy): int
    {
        return (int)$this->resolveApprovalRequirement($actionType, $payload, $metadata, $policy)['requiredApprovals'];
    }

    private function getActiveCpUserCount(): int
    {
        try {
            return (int)User::find()
                ->status(User::STATUS_ACTIVE)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function hasMultipleActiveCpUsers(): bool
    {
        return $this->getActiveCpUserCount() > 1;
    }

    private function resolveApprovalSlaPolicy(array $policy): array
    {
        $config = $this->normalizeArray($policy['config'] ?? []);
        $slaMinutes = max(0, (int)($config['approvalSlaMinutes'] ?? 0));
        $escalateAfterMinutes = max(0, (int)($config['escalateAfterMinutes'] ?? 0));
        $expireAfterMinutes = max(0, (int)($config['expireAfterMinutes'] ?? 0));

        if ($expireAfterMinutes > 0 && $escalateAfterMinutes > $expireAfterMinutes) {
            $escalateAfterMinutes = $expireAfterMinutes;
        }

        $slaDueAt = null;
        if ($slaMinutes > 0) {
            $slaDueAt = gmdate('Y-m-d H:i:s', time() + ($slaMinutes * 60));
        }

        return [
            'slaDueAt' => $slaDueAt,
            'escalateAfterMinutes' => $escalateAfterMinutes > 0 ? $escalateAfterMinutes : null,
            'expireAfterMinutes' => $expireAfterMinutes > 0 ? $expireAfterMinutes : null,
        ];
    }

    private function defaultPolicy(string $actionType = ''): array
    {
        $requiredScope = $this->defaultRequiredScopeForAction($actionType);

        return [
            'id' => 0,
            'handle' => 'default-control-policy',
            'displayName' => 'Default control policy',
            'actionPattern' => '*',
            'requiresApproval' => true,
            'enabled' => true,
            'riskLevel' => self::RISK_LEVEL_HIGH,
            'config' => [
                'requiredScope' => $requiredScope,
                'mode' => 'fail-safe',
            ],
            'requiredScope' => $requiredScope,
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
        $requiredApprovals = max(1, (int)($row['requiredApprovals'] ?? 1));
        $metadata = $this->decodeJsonArray((string)($row['metadata'] ?? '[]'));
        $assurance = $this->extractApprovalAssurance($metadata, $requiredApprovals);
        $draftExecution = $this->normalizeArray($metadata[self::APPROVAL_DRAFT_EXECUTION_METADATA_KEY] ?? []);
        unset($metadata[self::APPROVAL_ASSURANCE_METADATA_KEY]);
        unset($metadata[self::APPROVAL_DRAFT_EXECUTION_METADATA_KEY]);
        $primaryDecisionBy = $this->normalizeOptionalString($row['decidedBy'] ?? null, 128);
        $secondaryDecisionBy = $this->normalizeOptionalString($row['secondaryDecisionBy'] ?? null, 128);
        $approvalCount = 0;
        if ($primaryDecisionBy !== null && $primaryDecisionBy !== '') {
            $approvalCount++;
        }
        if ($secondaryDecisionBy !== null && $secondaryDecisionBy !== '') {
            $approvalCount++;
        }
        $status = strtolower(trim((string)($row['status'] ?? self::APPROVAL_STATUS_PENDING)));
        if ($status === self::APPROVAL_STATUS_REJECTED) {
            $approvalCount = min($approvalCount, 1);
        }

        $slaDueAt = $this->toIso8601($row['slaDueAt'] ?? null);
        $escalatedAt = $this->toIso8601($row['escalatedAt'] ?? null);
        $expiredAt = $this->toIso8601($row['expiredAt'] ?? null);
        $slaSecondsRemaining = null;
        if ($slaDueAt !== null) {
            $dueTs = strtotime($slaDueAt);
            if ($dueTs !== false) {
                $slaSecondsRemaining = $dueTs - time();
            }
        }

        $slaState = 'none';
        if ($status === self::APPROVAL_STATUS_EXPIRED || $expiredAt !== null) {
            $slaState = 'expired';
        } elseif (in_array($status, [self::APPROVAL_STATUS_APPROVED, self::APPROVAL_STATUS_REJECTED], true)) {
            $slaState = 'completed';
        } elseif ($escalatedAt !== null) {
            $slaState = 'escalated';
        } elseif ($slaSecondsRemaining !== null) {
            if ($slaSecondsRemaining < 0) {
                $slaState = 'overdue';
            } elseif ($slaSecondsRemaining <= 300) {
                $slaState = 'due_soon';
            } else {
                $slaState = 'on_time';
            }
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'actionType' => (string)($row['actionType'] ?? ''),
            'actionRef' => $this->normalizeOptionalString($row['actionRef'] ?? null, 128),
            'status' => $status,
            'requestedBy' => (string)($row['requestedBy'] ?? ''),
            'decidedBy' => $primaryDecisionBy,
            'secondaryDecisionBy' => $secondaryDecisionBy,
            'reason' => $this->normalizeOptionalString($row['reason'] ?? null),
            'decisionReason' => $this->normalizeOptionalString($row['decisionReason'] ?? null),
            'secondaryDecisionReason' => $this->normalizeOptionalString($row['secondaryDecisionReason'] ?? null),
            'idempotencyKey' => $this->normalizeOptionalString($row['idempotencyKey'] ?? null, 128),
            'requestPayload' => $this->decodeJsonArray((string)($row['requestPayload'] ?? '[]')),
            'metadata' => $metadata,
            'draftExecutionMetadata' => $draftExecution,
            'boundDraftExecutionId' => isset($draftExecution['executionId']) ? (int)$draftExecution['executionId'] : null,
            'boundDraftStatus' => $this->normalizeOptionalString($draftExecution['status'] ?? null, 16),
            'boundDraftEntryId' => isset($draftExecution['entryId']) ? (int)$draftExecution['entryId'] : null,
            'boundDraftSiteId' => isset($draftExecution['siteId']) ? (int)$draftExecution['siteId'] : null,
            'boundDraftId' => isset($draftExecution['draftId']) ? (int)$draftExecution['draftId'] : null,
            'boundDraftRecordId' => isset($draftExecution['draftRecordId']) ? (int)$draftExecution['draftRecordId'] : null,
            'boundDraftName' => $this->normalizeOptionalString($draftExecution['draftName'] ?? null),
            'boundDraftMessage' => $this->normalizeOptionalString($draftExecution['message'] ?? null),
            'boundDraftConflictType' => $this->normalizeOptionalString($draftExecution['conflictType'] ?? null, 64),
            'boundDraftConflicts' => is_array($draftExecution['conflictingDrafts'] ?? null) ? array_values($draftExecution['conflictingDrafts']) : [],
            'requiredApprovals' => $requiredApprovals,
            'approvalCount' => $approvalCount,
            'approvalsRemaining' => max(0, $requiredApprovals - $approvalCount),
            'assuranceMode' => (string)$assurance['mode'],
            'assuranceModeLabel' => $this->formatApprovalAssuranceMode((string)$assurance['mode']),
            'assuranceReason' => (string)$assurance['reason'],
            'assuranceReasonLabel' => $this->formatApprovalAssuranceReason((string)$assurance['reason']),
            'assuranceDegraded' => (string)$assurance['mode'] === self::APPROVAL_ASSURANCE_DEGRADED,
            'assuranceEvaluatedAt' => $this->toIso8601($assurance['evaluatedAt'] ?? null),
            'assuranceActiveCpUserCount' => isset($assurance['activeCpUserCount']) ? (int)$assurance['activeCpUserCount'] : null,
            'slaDueAt' => $slaDueAt,
            'escalateAfterMinutes' => isset($row['escalateAfterMinutes']) ? (int)$row['escalateAfterMinutes'] : null,
            'expireAfterMinutes' => isset($row['expireAfterMinutes']) ? (int)$row['expireAfterMinutes'] : null,
            'slaState' => $slaState,
            'slaSecondsRemaining' => $slaSecondsRemaining,
            'escalatedAt' => $escalatedAt,
            'expiredAt' => $expiredAt,
            'decidedAt' => $this->toIso8601($row['decidedAt'] ?? null),
            'secondaryDecisionAt' => $this->toIso8601($row['secondaryDecisionAt'] ?? null),
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

    private function decorateApprovalMetadata(array $metadata, array $requirement, string $evaluatedAt): array
    {
        $metadata[self::APPROVAL_ASSURANCE_METADATA_KEY] = [
            'mode' => (string)($requirement['assuranceMode'] ?? self::APPROVAL_ASSURANCE_SINGLE),
            'reason' => (string)($requirement['assuranceReason'] ?? self::APPROVAL_ASSURANCE_REASON_NONE),
            'evaluatedAt' => $evaluatedAt,
            'activeCpUserCount' => (int)($requirement['activeCpUserCount'] ?? 0),
        ];

        return $metadata;
    }

    private function syncApprovalDraftExecutionMetadata(int $approvalId, array $execution): void
    {
        if ($approvalId <= 0 || !$this->tableExists(self::TABLE_APPROVALS)) {
            return;
        }

        $draftExecutionMetadata = $this->captureApprovalDraftExecutionMetadata($execution);
        if ($draftExecutionMetadata === null) {
            return;
        }

        $approval = $this->findApprovalById($approvalId);
        if (!is_array($approval)) {
            return;
        }

        $metadata = $this->decodeJsonArray((string)($approval['metadata'] ?? '[]'));
        $metadata[self::APPROVAL_DRAFT_EXECUTION_METADATA_KEY] = $draftExecutionMetadata;

        $now = gmdate('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()->update(self::TABLE_APPROVALS, [
            'metadata' => $this->encodeJson($metadata),
            'dateUpdated' => $now,
        ], [
            'id' => $approvalId,
        ])->execute();
    }

    private function captureApprovalDraftExecutionMetadata(array $execution): ?array
    {
        $actionType = $this->normalizeActionType((string)($execution['actionType'] ?? ''));
        if ($actionType !== self::ACTION_ENTRY_UPDATE_DRAFT) {
            return null;
        }

        $resultPayload = is_array($execution['resultPayload'] ?? null) ? (array)$execution['resultPayload'] : [];
        $conflictingDrafts = [];
        foreach ((array)($resultPayload['conflictingDrafts'] ?? []) as $draft) {
            if (!is_array($draft)) {
                continue;
            }

            $conflictingDrafts[] = [
                'id' => isset($draft['id']) ? (int)$draft['id'] : null,
                'draftRecordId' => isset($draft['draftRecordId']) ? (int)$draft['draftRecordId'] : null,
                'siteId' => isset($draft['siteId']) ? (int)$draft['siteId'] : null,
                'draftName' => $this->normalizeOptionalString($draft['draftName'] ?? null),
                'title' => $this->normalizeOptionalString($draft['title'] ?? null),
                'cpEditUrl' => $this->normalizeOptionalString($draft['cpEditUrl'] ?? null),
                'dateUpdated' => $this->normalizeOptionalString($draft['dateUpdated'] ?? null),
            ];
        }

        return [
            'executionId' => isset($execution['id']) ? (int)$execution['id'] : null,
            'status' => $this->normalizeOptionalString($execution['status'] ?? null, 16),
            'entryId' => isset($resultPayload['entryId']) ? (int)$resultPayload['entryId'] : null,
            'siteId' => isset($resultPayload['siteId']) ? (int)$resultPayload['siteId'] : null,
            'draftId' => isset($resultPayload['draftId']) ? (int)$resultPayload['draftId'] : null,
            'draftRecordId' => isset($resultPayload['draftRecordId']) ? (int)$resultPayload['draftRecordId'] : null,
            'draftName' => $this->normalizeOptionalString($resultPayload['draftName'] ?? null),
            'message' => $this->normalizeOptionalString($resultPayload['message'] ?? ($execution['errorMessage'] ?? null)),
            'conflictType' => $this->normalizeOptionalString($resultPayload['conflictType'] ?? null, 64),
            'conflictingDrafts' => $conflictingDrafts,
            'capturedAt' => $this->normalizeOptionalString($execution['executedAt'] ?? ($execution['dateCreated'] ?? null)),
        ];
    }

    private function extractApprovalAssurance(array $metadata, int $requiredApprovals): array
    {
        $stored = $this->normalizeArray($metadata[self::APPROVAL_ASSURANCE_METADATA_KEY] ?? []);
        $mode = strtolower(trim((string)($stored['mode'] ?? '')));
        if (!in_array($mode, [
            self::APPROVAL_ASSURANCE_SINGLE,
            self::APPROVAL_ASSURANCE_DUAL,
            self::APPROVAL_ASSURANCE_DEGRADED,
        ], true)) {
            $mode = $requiredApprovals > 1 ? self::APPROVAL_ASSURANCE_DUAL : self::APPROVAL_ASSURANCE_SINGLE;
        }

        $reason = strtolower(trim((string)($stored['reason'] ?? '')));
        if ($reason === '') {
            $reason = self::APPROVAL_ASSURANCE_REASON_NONE;
        }

        return [
            'mode' => $mode,
            'reason' => $reason,
            'evaluatedAt' => $stored['evaluatedAt'] ?? null,
            'activeCpUserCount' => isset($stored['activeCpUserCount']) ? (int)$stored['activeCpUserCount'] : null,
        ];
    }

    private function formatApprovalAssuranceMode(string $mode): string
    {
        return match ($mode) {
            self::APPROVAL_ASSURANCE_DUAL => 'Dual control',
            self::APPROVAL_ASSURANCE_DEGRADED => 'Single-operator fallback',
            default => 'Single approval',
        };
    }

    private function formatApprovalAssuranceReason(string $reason): string
    {
        return match ($reason) {
            self::APPROVAL_ASSURANCE_REASON_INSUFFICIENT_CP_USERS => 'Dual control was downgraded because fewer than two active CP users were available when this request was evaluated.',
            default => '',
        };
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

    private function normalizeStringArray(mixed $value): array
    {
        $items = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_string($item) && !is_numeric($item)) {
                    continue;
                }
                $items[] = strtolower(trim((string)$item));
            }
        } elseif (is_string($value) || is_numeric($value)) {
            $chunks = preg_split('/[\s,]+/', strtolower(trim((string)$value))) ?: [];
            foreach ($chunks as $chunk) {
                $items[] = trim((string)$chunk);
            }
        }

        $normalized = [];
        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }
            $normalized[] = $item;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    private function extractApprovalAmount(array $payload, array $metadata): ?float
    {
        $candidates = [
            $payload['amount'] ?? null,
            $payload['total'] ?? null,
            $payload['totalPrice'] ?? null,
            $metadata['amount'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (float)$candidate;
            }
        }

        return null;
    }

    private function extractApprovalCategory(array $payload, array $metadata): ?string
    {
        $candidates = [
            $payload['category'] ?? null,
            $payload['reasonCode'] ?? null,
            $metadata['category'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) && !is_numeric($candidate)) {
                continue;
            }

            $value = strtolower(trim((string)$candidate));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
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
