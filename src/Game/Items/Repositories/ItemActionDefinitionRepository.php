<?php

namespace App\Game\Items\Repositories;

use App\Support\DB;
use PDO;

class ItemActionDefinitionRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function listActive(): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM item_action_definitions WHERE status = \'active\' ORDER BY code ASC');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findActiveByCode(string $code): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM item_action_definitions WHERE code = :code AND status = :status LIMIT 1');
        $stmt->execute([
            'code' => $code,
            'status' => 'active',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
