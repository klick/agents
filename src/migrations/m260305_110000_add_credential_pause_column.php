<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260305_110000_add_credential_pause_column extends Migration
{
    private const TABLE = '{{%agents_credentials}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return true;
        }

        $tableSchema = $this->db->getTableSchema(self::TABLE, true);
        if ($tableSchema === null) {
            return true;
        }

        if ($tableSchema->getColumn('pausedAt') === null) {
            $this->addColumn(self::TABLE, 'pausedAt', $this->dateTime()->after('revokedAt'));
        }

        $tableSchema = $this->db->getTableSchema(self::TABLE, true);
        if ($tableSchema !== null && $tableSchema->getColumn('pausedAt') !== null) {
            $this->createIndex(null, self::TABLE, ['pausedAt'], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return true;
        }

        $tableSchema = $this->db->getTableSchema(self::TABLE, true);
        if ($tableSchema === null) {
            return true;
        }

        if ($tableSchema->getColumn('pausedAt') !== null) {
            $this->dropColumn(self::TABLE, 'pausedAt');
        }

        return true;
    }
}
