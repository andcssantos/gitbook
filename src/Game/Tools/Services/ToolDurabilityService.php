<?php

namespace App\Game\Tools\Services;

use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Support\DB;
use PDO;

class ToolDurabilityService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return array{worn: bool, current: ?int, max: ?int, broken: bool, amount: int} */
    public function wear(int $playerId, int $itemInstanceId, int $amount = 1): array
    {
        if ($amount < 1) {
            return [
                'worn' => false,
                'current' => null,
                'max' => null,
                'broken' => false,
                'amount' => 0,
            ];
        }

        $items = new ItemInstanceRepository($this->pdo());
        $item = $items->findById($itemInstanceId, true);
        if ($item === null || (int) ($item['owner_player_id'] ?? 0) !== $playerId) {
            return [
                'worn' => false,
                'current' => null,
                'max' => null,
                'broken' => false,
                'amount' => 0,
            ];
        }

        if ($item['current_durability'] === null || $item['max_durability'] === null) {
            return [
                'worn' => false,
                'current' => null,
                'max' => null,
                'broken' => false,
                'amount' => 0,
            ];
        }

        $current = max(0, (int) $item['current_durability'] - $amount);
        $max = (int) $item['max_durability'];
        $items->updateDurability($itemInstanceId, $current);

        return [
            'worn' => true,
            'current' => $current,
            'max' => $max,
            'broken' => $current <= 0,
            'amount' => $amount,
        ];
    }

    /** @return array<string, mixed>|null */
    public function equippedWeaponForPlayer(int $playerId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ii.*
            FROM player_equipment pe
            INNER JOIN equipment_slots es ON es.id = pe.equipment_slot_id
            INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
            WHERE pe.player_id = :player_id AND es.code = :slot_code
            LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'slot_code' => 'weapon',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
