<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260317_160000_add_target_sets_tables extends Migration
{
    private const TABLE_TARGET_SETS = '{{%agents_target_sets}}';
    private const TABLE_CREDENTIAL_TARGET_SETS = '{{%agents_credential_target_sets}}';
    private const TABLE_CREDENTIALS = '{{%agents_credentials}}';
    private const TABLE_USERS = '{{%users}}';

    public function safeUp(): bool
    {
        $this->ensureCredentialsTableExists();

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
        }

        $this->ensureForeignKeyIfMissing(
            self::TABLE_CREDENTIAL_TARGET_SETS,
            ['credentialId'],
            self::TABLE_CREDENTIALS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );
        $this->ensureForeignKeyIfMissing(
            self::TABLE_CREDENTIAL_TARGET_SETS,
            ['targetSetId'],
            self::TABLE_TARGET_SETS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

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

    private function ensureCredentialsTableExists(): void
    {
        if ($this->db->tableExists(self::TABLE_CREDENTIALS)) {
            return;
        }

        // Some older installs can drift into a state where the base credentials
        // migration is marked as applied but the table is gone. Recreate the full
        // current credential schema so later migrations do not silently skip columns.
        $this->createTable(self::TABLE_CREDENTIALS, [
            'id' => $this->primaryKey(),
            'handle' => $this->string(64)->notNull(),
            'displayName' => $this->string(255)->notNull(),
            'description' => $this->string(255),
            'owner' => $this->string(255),
            'ownerUserId' => $this->integer(),
            'tokenHash' => $this->char(64)->notNull(),
            'tokenPrefix' => $this->string(16)->notNull(),
            'scopes' => $this->text()->notNull(),
            'webhookResourceTypes' => $this->text(),
            'webhookActions' => $this->text(),
            'forceHumanApproval' => $this->boolean()->notNull()->defaultValue(true),
            'approvalRecipientUserIds' => $this->text(),
            'lastUsedAt' => $this->dateTime(),
            'lastUsedIp' => $this->string(64),
            'lastAuthMethod' => $this->string(32),
            'rotatedAt' => $this->dateTime(),
            'revokedAt' => $this->dateTime(),
            'pausedAt' => $this->dateTime(),
            'expiresAt' => $this->dateTime(),
            'expiryReminderDays' => $this->integer()->notNull()->defaultValue(7),
            'ipAllowlist' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE_CREDENTIALS, ['handle'], true);
        $this->createIndex(null, self::TABLE_CREDENTIALS, ['tokenHash'], true);
        $this->createIndex(null, self::TABLE_CREDENTIALS, ['revokedAt'], false);
        $this->createIndex(null, self::TABLE_CREDENTIALS, ['lastUsedAt'], false);
        $this->createIndex(null, self::TABLE_CREDENTIALS, ['pausedAt'], false);
        $this->createIndex(null, self::TABLE_CREDENTIALS, ['expiresAt'], false);
        $this->createIndex(null, self::TABLE_CREDENTIALS, ['ownerUserId'], false);

        if ($this->db->tableExists(self::TABLE_USERS)) {
            $this->ensureForeignKeyIfMissing(
                self::TABLE_CREDENTIALS,
                ['ownerUserId'],
                self::TABLE_USERS,
                ['id'],
                'SET NULL',
                'CASCADE'
            );
        }
    }

    private function ensureForeignKeyIfMissing(
        string $table,
        array $columns,
        string $refTable,
        array $refColumns,
        ?string $delete = null,
        ?string $update = null
    ): void {
        if (!$this->db->tableExists($table) || !$this->db->tableExists($refTable)) {
            return;
        }

        $schema = $this->db->getTableSchema($table, true);
        if ($schema === null) {
            return;
        }

        foreach ($schema->foreignKeys as $definition) {
            $referencedTable = $definition[0] ?? null;
            $localColumns = array_keys($definition[1] ?? []);
            $referencedColumns = array_values($definition[1] ?? []);

            if (
                $referencedTable === $refTable
                && array_values($columns) === $localColumns
                && array_values($refColumns) === $referencedColumns
            ) {
                return;
            }
        }

        $this->addForeignKey(null, $table, $columns, $refTable, $refColumns, $delete, $update);
    }
}
