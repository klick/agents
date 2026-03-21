<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;

class m260321_100000_add_onboarding_state_table extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%agents_onboarding_state}}';
        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'startedAt' => $this->dateTime()->null(),
            'firstAccountCreatedAt' => $this->dateTime()->null(),
            'firstSuccessfulAuthAt' => $this->dateTime()->null(),
            'dismissedAt' => $this->dateTime()->null(),
            'completedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%agents_onboarding_state}}');
        return true;
    }
}
