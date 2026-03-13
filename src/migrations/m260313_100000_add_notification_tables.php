<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260313_100000_add_notification_tables extends Migration
{
    private const TABLE_LOG = '{{%agents_notification_log}}';
    private const TABLE_MONITORS = '{{%agents_notification_monitors}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE_LOG)) {
            $this->createTable(self::TABLE_LOG, [
                'id' => $this->primaryKey(),
                'eventType' => $this->string(64)->notNull(),
                'fingerprint' => $this->string(255)->notNull(),
                'channel' => $this->string(32)->notNull()->defaultValue('email'),
                'recipient' => $this->string(255)->notNull(),
                'subject' => $this->string(255)->notNull(),
                'bodyText' => $this->text()->notNull(),
                'payload' => $this->text()->notNull(),
                'status' => $this->string(16)->notNull()->defaultValue('queued'),
                'attempts' => $this->integer()->notNull()->defaultValue(0),
                'lastAttemptAt' => $this->dateTime(),
                'sentAt' => $this->dateTime(),
                'errorMessage' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::TABLE_LOG, ['eventType'], false);
            $this->createIndex(null, self::TABLE_LOG, ['fingerprint'], false);
            $this->createIndex(null, self::TABLE_LOG, ['status'], false);
            $this->createIndex(null, self::TABLE_LOG, ['recipient'], false);
            $this->createIndex(null, self::TABLE_LOG, ['dateCreated'], false);
        }

        if (!$this->db->tableExists(self::TABLE_MONITORS)) {
            $this->createTable(self::TABLE_MONITORS, [
                'id' => $this->primaryKey(),
                'monitorKey' => $this->string(64)->notNull(),
                'status' => $this->string(16)->notNull()->defaultValue('unknown'),
                'payload' => $this->text()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::TABLE_MONITORS, ['monitorKey'], true);
            $this->createIndex(null, self::TABLE_MONITORS, ['status'], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists(self::TABLE_MONITORS)) {
            $this->dropTable(self::TABLE_MONITORS);
        }

        if ($this->db->tableExists(self::TABLE_LOG)) {
            $this->dropTable(self::TABLE_LOG);
        }

        return true;
    }
}
