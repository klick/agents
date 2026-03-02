<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260302_190000_add_credential_ip_allowlist_column extends Migration
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

        if ($tableSchema->getColumn('ipAllowlist') === null) {
            $this->addColumn(self::TABLE, 'ipAllowlist', $this->text()->after('expiryReminderDays'));
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

        if ($tableSchema->getColumn('ipAllowlist') !== null) {
            $this->dropColumn(self::TABLE, 'ipAllowlist');
        }

        return true;
    }
}
