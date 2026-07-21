<?php

namespace App\Game\Inventory\Services;

use App\Game\Materials\Services\PlayerMaterialStashService;
use App\Support\DB;
use PDO;

class StashVaultService
{
    private const TABS = [
        'materials' => 'Materiais',
        'gems' => 'Gemas',
        'jewels' => 'Joias',
        'sellables' => 'Venda',
    ];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function listForPlayer(int $playerId, ?string $tab = null): array
    {
        $tab = strtolower(trim((string) $tab));
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'materials';
        $tabs = array_map(static fn (string $code, string $name): array => [
            'code' => $code, 'name' => $name, 'active' => $code === $tab,
        ], array_keys(self::TABS), self::TABS);

        if ($tab === 'materials') {
            $materials = (new PlayerMaterialStashService($this->pdo()))->listForPlayer($playerId);
            return ['tabs' => $tabs, 'active_tab' => $tab, 'stacks' => $materials['stacks'], 'grid' => $materials['grid']];
        }

        $conditions = match ($tab) {
            'gems' => "(id.code LIKE 'gem_%' OR ic.code IN ('gem', 'gems'))",
            'jewels' => "(id.code LIKE 'jewel_%' OR ic.code IN ('jewel', 'jewels'))",
            default => "id.tradeable = 1 AND ii.state = 'available'",
        };
        $stmt = $this->pdo()->prepare("SELECT ii.public_id, ii.item_name, ii.quantity, ii.quality_bucket, ii.state,
                id.code AS definition_code, id.name AS definition_name, id.equip_slot_code, ic.code AS category_code, ic.name AS category_name
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            WHERE ii.owner_player_id = :player_id AND {$conditions}
            ORDER BY id.name ASC, ii.id ASC");
        $stmt->execute(['player_id' => $playerId]);

        return [
            'tabs' => $tabs,
            'active_tab' => $tab,
            'items' => array_map(static fn (array $item): array => [
                'public_id' => (string) $item['public_id'],
                'name' => (string) ($item['item_name'] ?: $item['definition_name']),
                'definition_code' => (string) $item['definition_code'],
                'category_code' => (string) $item['category_code'],
                'quantity' => (int) $item['quantity'],
                'quality_bucket' => $item['quality_bucket'] !== null ? (string) $item['quality_bucket'] : null,
                'state' => (string) $item['state'],
                'equip_slot_code' => $item['equip_slot_code'] !== null ? (string) $item['equip_slot_code'] : null,
            ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
        ];
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
