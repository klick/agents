<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260317_120000_add_webhook_probe_runs_table extends Migration
{
    private const TABLE = '{{%agents_webhook_probe_runs}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'probeId' => $this->string(64)->notNull(),
            'eventId' => $this->string(64)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('failed'),
            'deliveryMode' => $this->string(16)->notNull()->defaultValue('sync'),
            'httpStatusCode' => $this->integer(),
            'httpReason' => $this->string(255),
            'errorMessage' => $this->text(),
            'triggeredByUserId' => $this->integer(),
            'triggeredByLabel' => $this->string(255),
            'targetUrl' => $this->text(),
            'payload' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE, ['dateCreated'], false);
        $this->createIndex(null, self::TABLE, ['probeId'], true);
        $this->createIndex(null, self::TABLE, ['eventId'], false);
        $this->createIndex(null, self::TABLE, ['status'], false);
        $this->createIndex(null, self::TABLE, ['triggeredByUserId'], false);

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
