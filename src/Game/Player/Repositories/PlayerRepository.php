<?php

namespace App\Game\Player\Repositories;

use App\Support\DB;
use PDO;

class PlayerRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function findDefaultActiveByAccountId(int $accountId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM players WHERE account_id = :account_id AND status = :status ORDER BY id ASC LIMIT 1');
        $stmt->execute([
            'account_id' => $accountId,
            'status' => 'active',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findActiveById(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM players WHERE id = :id AND status = :status LIMIT 1');
        $stmt->execute([
            'id' => $id,
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
