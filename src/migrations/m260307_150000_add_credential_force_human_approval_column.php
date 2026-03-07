<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260307_150000_add_credential_force_human_approval_column extends Migration
{
    private const TABLE = '{{%agents_credentials}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return true;
        }

        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema === null || $schema->getColumn('forceHumanApproval') !== null) {
            return true;
        }

        $this->addColumn(self::TABLE, 'forceHumanApproval', $this->boolean()->notNull()->defaultValue(true)->after('owner'));

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return true;
        }

        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema !== null && $schema->getColumn('forceHumanApproval') !== null) {
            $this->dropColumn(self::TABLE, 'forceHumanApproval');
        }

        return true;
    }
}
