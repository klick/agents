<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260302_160000_add_credential_webhook_subscription_columns extends Migration
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

        if ($tableSchema->getColumn('webhookResourceTypes') === null) {
            $this->addColumn(self::TABLE, 'webhookResourceTypes', $this->text()->after('scopes'));
        }

        if ($tableSchema->getColumn('webhookActions') === null) {
            $this->addColumn(self::TABLE, 'webhookActions', $this->text()->after('webhookResourceTypes'));
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

        if ($tableSchema->getColumn('webhookActions') !== null) {
            $this->dropColumn(self::TABLE, 'webhookActions');
        }

        if ($tableSchema->getColumn('webhookResourceTypes') !== null) {
            $this->dropColumn(self::TABLE, 'webhookResourceTypes');
        }

        return true;
    }
}
