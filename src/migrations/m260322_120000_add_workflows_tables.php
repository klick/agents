<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260322_120000_add_workflows_tables extends Migration
{
    private const TABLE_WORKFLOWS = '{{%agents_workflows}}';
    private const TABLE_WORKFLOW_RUNS = '{{%agents_workflow_runs}}';
    private const TABLE_CREDENTIALS = '{{%agents_credentials}}';
    private const TABLE_TARGET_SETS = '{{%agents_target_sets}}';
    private const TABLE_USERS = '{{%users}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE_WORKFLOWS)) {
            $this->createTable(self::TABLE_WORKFLOWS, [
                'id' => $this->primaryKey(),
                'templateHandle' => $this->string(64)->notNull(),
                'name' => $this->string(255)->notNull(),
                'description' => $this->string(255),
                'status' => $this->string(32)->notNull()->defaultValue('active'),
                'cadence' => $this->string(32)->notNull()->defaultValue('weekly'),
                'weekday' => $this->smallInteger(),
                'timeOfDay' => $this->string(5),
                'timezone' => $this->string(64),
                'accountId' => $this->integer()->notNull(),
                'targetSetId' => $this->integer()->notNull(),
                'ownerUserId' => $this->integer(),
                'configJson' => $this->text()->notNull(),
                'lastWorkerId' => $this->string(255),
                'lastClaimedAt' => $this->dateTime(),
                'lastHeartbeatAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::TABLE_WORKFLOWS, ['templateHandle'], false);
            $this->createIndex(null, self::TABLE_WORKFLOWS, ['status'], false);
            $this->createIndex(null, self::TABLE_WORKFLOWS, ['accountId'], false);
            $this->createIndex(null, self::TABLE_WORKFLOWS, ['targetSetId'], false);

            $this->addForeignKey(
                null,
                self::TABLE_WORKFLOWS,
                ['accountId'],
                self::TABLE_CREDENTIALS,
                ['id'],
                'CASCADE',
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                self::TABLE_WORKFLOWS,
                ['targetSetId'],
                self::TABLE_TARGET_SETS,
                ['id'],
                'CASCADE',
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                self::TABLE_WORKFLOWS,
                ['ownerUserId'],
                self::TABLE_USERS,
                ['id'],
                'SET NULL',
                'CASCADE'
            );
        }

        if (!$this->db->tableExists(self::TABLE_WORKFLOW_RUNS)) {
            $this->createTable(self::TABLE_WORKFLOW_RUNS, [
                'id' => $this->primaryKey(),
                'workflowId' => $this->integer()->notNull(),
                'status' => $this->string(32)->notNull()->defaultValue('queued'),
                'scheduledFor' => $this->dateTime(),
                'claimedAt' => $this->dateTime(),
                'startedAt' => $this->dateTime(),
                'completedAt' => $this->dateTime(),
                'workerId' => $this->string(255),
                'summary' => $this->text(),
                'logExcerpt' => $this->text(),
                'approvalIdsJson' => $this->text(),
                'outcomeRefsJson' => $this->text(),
                'metadataJson' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::TABLE_WORKFLOW_RUNS, ['workflowId'], false);
            $this->createIndex(null, self::TABLE_WORKFLOW_RUNS, ['status'], false);
            $this->createIndex(null, self::TABLE_WORKFLOW_RUNS, ['scheduledFor'], false);

            $this->addForeignKey(
                null,
                self::TABLE_WORKFLOW_RUNS,
                ['workflowId'],
                self::TABLE_WORKFLOWS,
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists(self::TABLE_WORKFLOW_RUNS)) {
            $this->dropTable(self::TABLE_WORKFLOW_RUNS);
        }

        if ($this->db->tableExists(self::TABLE_WORKFLOWS)) {
            $this->dropTable(self::TABLE_WORKFLOWS);
        }

        return true;
    }
}
