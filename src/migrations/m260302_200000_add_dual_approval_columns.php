<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260302_200000_add_dual_approval_columns extends Migration
{
    private const TABLE = '{{%agents_control_approvals}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return true;
        }

        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            return true;
        }

        if ($schema->getColumn('requiredApprovals') === null) {
            $this->addColumn(self::TABLE, 'requiredApprovals', $this->integer()->notNull()->defaultValue(1)->after('metadata'));
        }

        if ($schema->getColumn('secondaryDecisionBy') === null) {
            $this->addColumn(self::TABLE, 'secondaryDecisionBy', $this->string(128)->after('decidedBy'));
        }

        if ($schema->getColumn('secondaryDecisionReason') === null) {
            $this->addColumn(self::TABLE, 'secondaryDecisionReason', $this->text()->after('decisionReason'));
        }

        if ($schema->getColumn('secondaryDecisionAt') === null) {
            $this->addColumn(self::TABLE, 'secondaryDecisionAt', $this->dateTime()->after('decidedAt'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return true;
        }

        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            return true;
        }

        if ($schema->getColumn('secondaryDecisionAt') !== null) {
            $this->dropColumn(self::TABLE, 'secondaryDecisionAt');
        }

        if ($schema->getColumn('secondaryDecisionReason') !== null) {
            $this->dropColumn(self::TABLE, 'secondaryDecisionReason');
        }

        if ($schema->getColumn('secondaryDecisionBy') !== null) {
            $this->dropColumn(self::TABLE, 'secondaryDecisionBy');
        }

        if ($schema->getColumn('requiredApprovals') !== null) {
            $this->dropColumn(self::TABLE, 'requiredApprovals');
        }

        return true;
    }
}
