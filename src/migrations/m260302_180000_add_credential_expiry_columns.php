<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260302_180000_add_credential_expiry_columns extends Migration
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

        if ($tableSchema->getColumn('expiresAt') === null) {
            $this->addColumn(self::TABLE, 'expiresAt', $this->dateTime()->after('webhookActions'));
        }

        if ($tableSchema->getColumn('expiryReminderDays') === null) {
            $this->addColumn(self::TABLE, 'expiryReminderDays', $this->integer()->notNull()->defaultValue(7)->after('expiresAt'));
        }

        $this->createIndex(null, self::TABLE, ['expiresAt'], false);

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

        if ($tableSchema->getColumn('expiryReminderDays') !== null) {
            $this->dropColumn(self::TABLE, 'expiryReminderDays');
        }

        if ($tableSchema->getColumn('expiresAt') !== null) {
            $this->dropColumn(self::TABLE, 'expiresAt');
        }

        return true;
    }
}
