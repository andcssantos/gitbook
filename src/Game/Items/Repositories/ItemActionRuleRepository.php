<?php

namespace App\Game\Items\Repositories;

use App\Support\DB;
use PDO;

class ItemActionRuleRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function listForActionDefinition(int $actionDefinitionId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM item_action_rules WHERE action_definition_id = :action_definition_id ORDER BY priority ASC, id ASC');
        $stmt->execute(['action_definition_id' => $actionDefinitionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
