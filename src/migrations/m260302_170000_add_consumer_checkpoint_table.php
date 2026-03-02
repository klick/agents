<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260302_170000_add_consumer_checkpoint_table extends Migration
{
    private const TABLE = '{{%agents_consumer_checkpoints}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'integrationKey' => $this->string(64)->notNull(),
            'resourceType' => $this->string(32)->notNull(),
            'cursor' => $this->string(255),
            'updatedSince' => $this->dateTime(),
            'checkpointAt' => $this->dateTime()->notNull(),
            'lagSeconds' => $this->integer()->notNull()->defaultValue(0),
            'metadata' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE, ['integrationKey', 'resourceType'], true);
        $this->createIndex(null, self::TABLE, ['resourceType'], false);
        $this->createIndex(null, self::TABLE, ['lagSeconds'], false);
        $this->createIndex(null, self::TABLE, ['checkpointAt'], false);
        $this->createIndex(null, self::TABLE, ['dateUpdated'], false);

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
