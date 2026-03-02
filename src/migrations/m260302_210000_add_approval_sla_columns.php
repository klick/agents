<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260302_210000_add_approval_sla_columns extends Migration
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

        if ($schema->getColumn('slaDueAt') === null) {
            $this->addColumn(self::TABLE, 'slaDueAt', $this->dateTime()->after('requiredApprovals'));
        }

        if ($schema->getColumn('escalateAfterMinutes') === null) {
            $this->addColumn(self::TABLE, 'escalateAfterMinutes', $this->integer()->after('slaDueAt'));
        }

        if ($schema->getColumn('expireAfterMinutes') === null) {
            $this->addColumn(self::TABLE, 'expireAfterMinutes', $this->integer()->after('escalateAfterMinutes'));
        }

        if ($schema->getColumn('escalatedAt') === null) {
            $this->addColumn(self::TABLE, 'escalatedAt', $this->dateTime()->after('secondaryDecisionAt'));
        }

        if ($schema->getColumn('expiredAt') === null) {
            $this->addColumn(self::TABLE, 'expiredAt', $this->dateTime()->after('escalatedAt'));
        }

        $this->createIndex(null, self::TABLE, ['status', 'dateCreated'], false);
        $this->createIndex(null, self::TABLE, ['slaDueAt'], false);

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

        if ($schema->getColumn('expiredAt') !== null) {
            $this->dropColumn(self::TABLE, 'expiredAt');
        }

        if ($schema->getColumn('escalatedAt') !== null) {
            $this->dropColumn(self::TABLE, 'escalatedAt');
        }

        if ($schema->getColumn('expireAfterMinutes') !== null) {
            $this->dropColumn(self::TABLE, 'expireAfterMinutes');
        }

        if ($schema->getColumn('escalateAfterMinutes') !== null) {
            $this->dropColumn(self::TABLE, 'escalateAfterMinutes');
        }

        if ($schema->getColumn('slaDueAt') !== null) {
            $this->dropColumn(self::TABLE, 'slaDueAt');
        }

        return true;
    }
}
