<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260313_180000_add_credential_description_column extends Migration
{
    private const TABLE = '{{%agents_credentials}}';

    public function safeUp(): bool
    {
        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema === null || $schema->getColumn('description') !== null) {
            return true;
        }

        $this->addColumn(self::TABLE, 'description', $this->string(255)->null()->after('displayName'));

        return true;
    }

    public function safeDown(): bool
    {
        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema !== null && $schema->getColumn('description') !== null) {
            $this->dropColumn(self::TABLE, 'description');
        }

        return true;
    }
}
