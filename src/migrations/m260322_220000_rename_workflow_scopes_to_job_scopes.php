<?php

namespace Klick\Agents\migrations;

use craft\db\Migration;
use craft\helpers\Json;
use yii\db\Query;

class m260322_220000_rename_workflow_scopes_to_job_scopes extends Migration
{
    private const TABLE_CREDENTIALS = '{{%agents_credentials}}';

    public function safeUp(): bool
    {
        return $this->rewriteScopes([
            'workflows:read' => 'jobs:read',
            'workflows:report' => 'jobs:report',
        ]);
    }

    public function safeDown(): bool
    {
        return $this->rewriteScopes([
            'jobs:read' => 'workflows:read',
            'jobs:report' => 'workflows:report',
        ]);
    }

    private function rewriteScopes(array $replacements): bool
    {
        if (!$this->db->tableExists(self::TABLE_CREDENTIALS)) {
            return true;
        }

        $rows = (new Query())
            ->select(['id', 'scopes'])
            ->from(self::TABLE_CREDENTIALS)
            ->all($this->db);

        foreach ($rows as $row) {
            $scopeJson = (string)($row['scopes'] ?? '[]');
            $decoded = Json::decodeIfJson($scopeJson);
            $scopes = is_array($decoded) ? $decoded : [];

            $normalizedScopes = [];
            foreach ($scopes as $scope) {
                $normalizedScope = strtolower(trim((string)$scope));
                if ($normalizedScope === '') {
                    continue;
                }

                $normalizedScopes[] = $replacements[$normalizedScope] ?? $normalizedScope;
            }

            $normalizedScopes = array_values(array_unique($normalizedScopes));
            $encodedScopes = Json::encode($normalizedScopes);

            if ($encodedScopes === $scopeJson) {
                continue;
            }

            $this->update(
                self::TABLE_CREDENTIALS,
                ['scopes' => $encodedScopes],
                ['id' => (int)($row['id'] ?? 0)]
            );
        }

        return true;
    }
}
