<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;
use yii\db\Query;

class m260313_160000_add_credential_user_relation_columns extends Migration
{
    private const CREDENTIALS_TABLE = '{{%agents_credentials}}';
    private const USERS_TABLE = '{{%users}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::CREDENTIALS_TABLE)) {
            return true;
        }

        $schema = $this->db->getTableSchema(self::CREDENTIALS_TABLE, true);
        if ($schema === null) {
            return true;
        }

        if ($schema->getColumn('ownerUserId') === null) {
            $this->addColumn(self::CREDENTIALS_TABLE, 'ownerUserId', $this->integer()->null()->after('owner'));
            $this->createIndex(null, self::CREDENTIALS_TABLE, ['ownerUserId'], false);
            if ($this->db->tableExists(self::USERS_TABLE)) {
                $this->addForeignKey(
                    null,
                    self::CREDENTIALS_TABLE,
                    ['ownerUserId'],
                    self::USERS_TABLE,
                    ['id'],
                    'SET NULL',
                    'CASCADE'
                );
            }
        }

        if ($schema->getColumn('approvalRecipientUserIds') === null) {
            $this->addColumn(self::CREDENTIALS_TABLE, 'approvalRecipientUserIds', $this->text()->null()->after('forceHumanApproval'));
        }

        $this->backfillOwnerUsers();

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->tableExists(self::CREDENTIALS_TABLE)) {
            return true;
        }

        $schema = $this->db->getTableSchema(self::CREDENTIALS_TABLE, true);
        if ($schema === null) {
            return true;
        }

        if ($schema->getColumn('ownerUserId') !== null) {
            $foreignKeys = $schema->foreignKeys;
            foreach ($foreignKeys as $name => $definition) {
                if (($definition[0] ?? null) === self::USERS_TABLE && (($definition[1]['ownerUserId'] ?? null) === 'id')) {
                    $this->dropForeignKey($name, self::CREDENTIALS_TABLE);
                }
            }
            $this->dropIndexByColumnsIfExists(self::CREDENTIALS_TABLE, ['ownerUserId']);
            $this->dropColumn(self::CREDENTIALS_TABLE, 'ownerUserId');
        }

        if ($schema->getColumn('approvalRecipientUserIds') !== null) {
            $this->dropColumn(self::CREDENTIALS_TABLE, 'approvalRecipientUserIds');
        }

        return true;
    }

    private function backfillOwnerUsers(): void
    {
        if (
            !$this->db->tableExists(self::CREDENTIALS_TABLE)
            || !$this->db->tableExists(self::USERS_TABLE)
        ) {
            return;
        }

        $rows = (new Query())
            ->from(self::CREDENTIALS_TABLE)
            ->where(['ownerUserId' => null])
            ->andWhere(['not', ['owner' => null]])
            ->all($this->db);

        foreach ($rows as $row) {
            $owner = trim((string)($row['owner'] ?? ''));
            $credentialId = (int)($row['id'] ?? 0);
            if ($credentialId <= 0 || $owner === '') {
                continue;
            }

            $matches = (new Query())
                ->select(['id'])
                ->from(self::USERS_TABLE)
                ->where(['or', ['email' => $owner], ['username' => $owner]])
                ->limit(2)
                ->column($this->db);

            $matches = array_values(array_unique(array_map('intval', $matches)));
            if (count($matches) !== 1 || $matches[0] <= 0) {
                continue;
            }

            $this->update(self::CREDENTIALS_TABLE, [
                'ownerUserId' => $matches[0],
            ], [
                'id' => $credentialId,
            ], [], false);
        }
    }

    private function dropIndexByColumnsIfExists(string $table, array $columns): void
    {
        $schema = $this->db->getTableSchema($table, true);
        if ($schema === null) {
            return;
        }

        foreach ($schema->indexes as $name => $index) {
            $indexColumns = array_values((array)($index['columns'] ?? []));
            if ($indexColumns === $columns) {
                $this->dropIndex($name, $table);
                return;
            }
        }
    }
}
