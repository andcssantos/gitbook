<?php

namespace App\Game\Containers\Repositories;

use App\Support\DB;
use PDO;

class ContainerAcceptanceRuleRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function listForContainerDefinition(int $containerDefinitionId): array
    {
        $stmt = $this->pdo()->prepare('SELECT *
            FROM container_acceptance_rules
            WHERE container_definition_id = :container_definition_id
            ORDER BY priority ASC, id ASC');
        $stmt->execute(['container_definition_id' => $containerDefinitionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
