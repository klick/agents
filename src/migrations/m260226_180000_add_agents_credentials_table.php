<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260226_180000_add_agents_credentials_table extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%agents_credentials}}';
        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'handle' => $this->string(64)->notNull(),
            'displayName' => $this->string(255)->notNull(),
            'tokenHash' => $this->char(64)->notNull(),
            'tokenPrefix' => $this->string(16)->notNull(),
            'scopes' => $this->text()->notNull(),
            'lastUsedAt' => $this->dateTime(),
            'lastUsedIp' => $this->string(64),
            'lastAuthMethod' => $this->string(32),
            'rotatedAt' => $this->dateTime(),
            'revokedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['handle'], true);
        $this->createIndex(null, $table, ['tokenHash'], true);
        $this->createIndex(null, $table, ['revokedAt'], false);
        $this->createIndex(null, $table, ['lastUsedAt'], false);

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%agents_credentials}}';
        if ($this->db->tableExists($table)) {
            $this->dropTable($table);
        }

        return true;
    }
}
