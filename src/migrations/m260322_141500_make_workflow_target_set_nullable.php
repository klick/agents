<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260322_141500_make_workflow_target_set_nullable extends Migration
{
    private const TABLE_WORKFLOWS = '{{%agents_workflows}}';
    private const TABLE_TARGET_SETS = '{{%agents_target_sets}}';
    private const TARGET_SET_COLUMN = 'targetSetId';
    private const TARGET_SET_FK = 'agents_workflows_targetSetId_fk';

    public function safeUp(): bool
    {
        $tableSchema = $this->db->getSchema()->getTableSchema(self::TABLE_WORKFLOWS, true);
        if ($tableSchema === null || !isset($tableSchema->columns[self::TARGET_SET_COLUMN])) {
            return true;
        }

        foreach ($tableSchema->foreignKeys as $name => $foreignKey) {
            if (!is_string($name) || !is_array($foreignKey)) {
                continue;
            }

            $links = $foreignKey;
            unset($links[0]);
            if (array_key_exists(self::TARGET_SET_COLUMN, $links)) {
                $this->dropForeignKey($name, self::TABLE_WORKFLOWS);
            }
        }

        $this->alterColumn(self::TABLE_WORKFLOWS, self::TARGET_SET_COLUMN, $this->integer());
        $this->addForeignKey(
            self::TARGET_SET_FK,
            self::TABLE_WORKFLOWS,
            [self::TARGET_SET_COLUMN],
            self::TABLE_TARGET_SETS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260322_141500_make_workflow_target_set_nullable cannot be reverted safely.\n";
        return false;
    }
}
