<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260302_150000_add_webhook_dlq_table extends Migration
{
    private const TABLE = '{{%agents_webhook_dlq}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'eventId' => $this->string(64)->notNull(),
            'resourceType' => $this->string(32),
            'resourceId' => $this->string(64),
            'action' => $this->string(16),
            'status' => $this->string(16)->notNull()->defaultValue('failed'),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'lastError' => $this->text(),
            'payload' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE, ['eventId'], false);
        $this->createIndex(null, self::TABLE, ['status'], false);
        $this->createIndex(null, self::TABLE, ['dateCreated'], false);

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            $this->dropTable(self::TABLE);
        }

        return true;
    }
}
