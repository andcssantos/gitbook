<?php

namespace App\Game\Containers\Repositories;

use App\Support\DB;
use PDO;

class ContainerDefinitionRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function findActiveByCode(string $code): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM container_definitions WHERE code = :code AND status = :status LIMIT 1');
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
