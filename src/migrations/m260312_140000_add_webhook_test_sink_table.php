<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260312_140000_add_webhook_test_sink_table extends Migration
{
    private const TABLE = '{{%agents_webhook_test_sink_events}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'eventId' => $this->string(64),
            'resourceType' => $this->string(32),
            'resourceId' => $this->string(64),
            'action' => $this->string(16),
            'verificationStatus' => $this->string(16)->notNull()->defaultValue('unsigned'),
            'requestMethod' => $this->string(8)->notNull()->defaultValue('POST'),
            'contentType' => $this->string(128),
            'remoteIp' => $this->string(64),
            'userAgent' => $this->string(255),
            'payloadBytes' => $this->integer()->notNull()->defaultValue(0),
            'headers' => $this->text()->notNull(),
            'payload' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE, ['dateCreated'], false);
        $this->createIndex(null, self::TABLE, ['eventId'], false);
        $this->createIndex(null, self::TABLE, ['verificationStatus'], false);

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
