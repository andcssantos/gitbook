<?php

namespace App\Game\Identity\Repositories;

use App\Support\DB;
use PDO;

class AccountRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function findActiveByEmail(string $email): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM accounts WHERE email = :email AND status = :status AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([
            'email' => mb_strtolower(trim($email)),
            'status' => 'active',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findActiveById(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM accounts WHERE id = :id AND status = :status AND deleted_at IS NULL LIMIT 1');
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
