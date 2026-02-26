<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260226_233000_add_agents_control_plane_tables extends Migration
{
    private const TABLE_POLICIES = '{{%agents_control_policies}}';
    private const TABLE_APPROVALS = '{{%agents_control_approvals}}';
    private const TABLE_EXECUTIONS = '{{%agents_control_executions}}';
    private const TABLE_AUDIT = '{{%agents_control_audit_log}}';
    private const FK_EXECUTIONS_APPROVAL = 'agents_control_executions_approval_fk';

    public function safeUp(): bool
    {
        $this->createPoliciesTable();
        $this->createApprovalsTable();
        $this->createExecutionsTable();
        $this->createAuditTable();

        if ($this->db->tableExists(self::TABLE_EXECUTIONS) && $this->db->tableExists(self::TABLE_APPROVALS)) {
            $this->addForeignKey(
                self::FK_EXECUTIONS_APPROVAL,
                self::TABLE_EXECUTIONS,
                ['approvalId'],
                self::TABLE_APPROVALS,
                ['id'],
                'SET NULL',
                null
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists(self::TABLE_EXECUTIONS)) {
            try {
                $this->dropForeignKey(self::FK_EXECUTIONS_APPROVAL, self::TABLE_EXECUTIONS);
            } catch (\Throwable) {
                // Ignore missing foreign key in partially migrated environments.
            }
        }

        if ($this->db->tableExists(self::TABLE_AUDIT)) {
            $this->dropTable(self::TABLE_AUDIT);
        }

        if ($this->db->tableExists(self::TABLE_EXECUTIONS)) {
            $this->dropTable(self::TABLE_EXECUTIONS);
        }

        if ($this->db->tableExists(self::TABLE_APPROVALS)) {
            $this->dropTable(self::TABLE_APPROVALS);
        }

        if ($this->db->tableExists(self::TABLE_POLICIES)) {
            $this->dropTable(self::TABLE_POLICIES);
        }

        return true;
    }

    private function createPoliciesTable(): void
    {
        if ($this->db->tableExists(self::TABLE_POLICIES)) {
            return;
        }

        $this->createTable(self::TABLE_POLICIES, [
            'id' => $this->primaryKey(),
            'handle' => $this->string(64)->notNull(),
            'displayName' => $this->string(255)->notNull(),
            'actionPattern' => $this->string(191)->notNull(),
            'requiresApproval' => $this->boolean()->notNull()->defaultValue(true),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'riskLevel' => $this->string(16)->notNull()->defaultValue('medium'),
            'config' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE_POLICIES, ['handle'], true);
        $this->createIndex(null, self::TABLE_POLICIES, ['enabled'], false);
        $this->createIndex(null, self::TABLE_POLICIES, ['actionPattern'], false);
    }

    private function createApprovalsTable(): void
    {
        if ($this->db->tableExists(self::TABLE_APPROVALS)) {
            return;
        }

        $this->createTable(self::TABLE_APPROVALS, [
            'id' => $this->primaryKey(),
            'actionType' => $this->string(96)->notNull(),
            'actionRef' => $this->string(128),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'requestedBy' => $this->string(128)->notNull(),
            'decidedBy' => $this->string(128),
            'reason' => $this->text(),
            'decisionReason' => $this->text(),
            'idempotencyKey' => $this->string(128),
            'requestPayload' => $this->text()->notNull(),
            'metadata' => $this->text()->notNull(),
            'decidedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE_APPROVALS, ['status'], false);
        $this->createIndex(null, self::TABLE_APPROVALS, ['actionType'], false);
        $this->createIndex(null, self::TABLE_APPROVALS, ['idempotencyKey'], true);
        $this->createIndex(null, self::TABLE_APPROVALS, ['dateCreated'], false);
    }

    private function createExecutionsTable(): void
    {
        if ($this->db->tableExists(self::TABLE_EXECUTIONS)) {
            return;
        }

        $this->createTable(self::TABLE_EXECUTIONS, [
            'id' => $this->primaryKey(),
            'actionType' => $this->string(96)->notNull(),
            'actionRef' => $this->string(128),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'requestedBy' => $this->string(128)->notNull(),
            'requiredScope' => $this->string(96),
            'approvalId' => $this->integer(),
            'idempotencyKey' => $this->string(128)->notNull(),
            'requestPayload' => $this->text()->notNull(),
            'resultPayload' => $this->text()->notNull(),
            'errorMessage' => $this->text(),
            'executedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE_EXECUTIONS, ['idempotencyKey'], true);
        $this->createIndex(null, self::TABLE_EXECUTIONS, ['status'], false);
        $this->createIndex(null, self::TABLE_EXECUTIONS, ['actionType'], false);
        $this->createIndex(null, self::TABLE_EXECUTIONS, ['approvalId'], false);
        $this->createIndex(null, self::TABLE_EXECUTIONS, ['dateCreated'], false);
    }

    private function createAuditTable(): void
    {
        if ($this->db->tableExists(self::TABLE_AUDIT)) {
            return;
        }

        $this->createTable(self::TABLE_AUDIT, [
            'id' => $this->primaryKey(),
            'category' => $this->string(64)->notNull(),
            'action' => $this->string(64)->notNull(),
            'outcome' => $this->string(16)->notNull()->defaultValue('info'),
            'actorType' => $this->string(32)->notNull()->defaultValue('system'),
            'actorId' => $this->string(128),
            'requestId' => $this->string(128),
            'ipAddress' => $this->string(64),
            'entityType' => $this->string(64),
            'entityId' => $this->string(128),
            'summary' => $this->string(255),
            'metadata' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE_AUDIT, ['category'], false);
        $this->createIndex(null, self::TABLE_AUDIT, ['actorType', 'actorId'], false);
        $this->createIndex(null, self::TABLE_AUDIT, ['entityType', 'entityId'], false);
        $this->createIndex(null, self::TABLE_AUDIT, ['dateCreated'], false);
    }
}
