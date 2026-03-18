<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260317_160000_add_target_sets_tables extends Migration
{
    private const TABLE_TARGET_SETS = '{{%agents_target_sets}}';
    private const TABLE_CREDENTIAL_TARGET_SETS = '{{%agents_credential_target_sets}}';
    private const TABLE_CREDENTIALS = '{{%agents_credentials}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE_TARGET_SETS)) {
            $this->createTable(self::TABLE_TARGET_SETS, [
                'id' => $this->primaryKey(),
                'handle' => $this->string(64)->notNull(),
                'name' => $this->string(255)->notNull(),
                'description' => $this->string(255),
                'allowedActionTypes' => $this->text()->notNull(),
                'allowedEntryIds' => $this->text()->notNull(),
                'allowedSiteIds' => $this->text()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::TABLE_TARGET_SETS, ['handle'], true);
            $this->createIndex(null, self::TABLE_TARGET_SETS, ['name'], false);
        }

        if (!$this->db->tableExists(self::TABLE_CREDENTIAL_TARGET_SETS)) {
            $this->createTable(self::TABLE_CREDENTIAL_TARGET_SETS, [
                'id' => $this->primaryKey(),
                'credentialId' => $this->integer()->notNull(),
                'targetSetId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::TABLE_CREDENTIAL_TARGET_SETS, ['credentialId', 'targetSetId'], true);
            $this->createIndex(null, self::TABLE_CREDENTIAL_TARGET_SETS, ['targetSetId'], false);
            $this->addForeignKey(
                null,
                self::TABLE_CREDENTIAL_TARGET_SETS,
                ['credentialId'],
                self::TABLE_CREDENTIALS,
                ['id'],
                'CASCADE',
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                self::TABLE_CREDENTIAL_TARGET_SETS,
                ['targetSetId'],
                self::TABLE_TARGET_SETS,
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists(self::TABLE_CREDENTIAL_TARGET_SETS)) {
            $this->dropTable(self::TABLE_CREDENTIAL_TARGET_SETS);
        }

        if ($this->db->tableExists(self::TABLE_TARGET_SETS)) {
            $this->dropTable(self::TABLE_TARGET_SETS);
        }

        return true;
    }
}
