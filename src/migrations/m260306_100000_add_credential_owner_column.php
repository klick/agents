<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260306_100000_add_credential_owner_column extends Migration
{
    private const TABLE = '{{%agents_credentials}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return true;
        }

        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema === null || $schema->getColumn('owner') !== null) {
            return true;
        }

        $this->addColumn(self::TABLE, 'owner', $this->string(255)->null()->after('displayName'));

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return true;
        }

        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema !== null && $schema->getColumn('owner') !== null) {
            $this->dropColumn(self::TABLE, 'owner');
        }

        return true;
    }
}
