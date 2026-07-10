<?php

namespace App\Game\Enhancement\Repositories;

use App\Support\DB;
use PDO;

class ItemUpgradeEventRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function record(
        int $itemInstanceId,
        int $playerId,
        int $fromLevel,
        int $toLevel,
        bool $success,
        ?string $jewelCode = null
    ): int {
        $stmt = $this->pdo()->prepare('INSERT INTO item_upgrade_events (
            item_instance_id,
            player_id,
            from_level,
            to_level,
            success,
            cost_currency_code,
            cost_amount
        ) VALUES (
            :item_instance_id,
            :player_id,
            :from_level,
            :to_level,
            :success,
            :cost_currency_code,
            :cost_amount
        )');
        $stmt->execute([
            'item_instance_id' => $itemInstanceId,
            'player_id' => $playerId,
            'from_level' => $fromLevel,
            'to_level' => $toLevel,
            'success' => $success ? 1 : 0,
            'cost_currency_code' => $jewelCode,
            'cost_amount' => 1,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
