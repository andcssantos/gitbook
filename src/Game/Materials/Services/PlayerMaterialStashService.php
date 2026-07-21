<?php

namespace App\Game\Materials\Services;

use App\Support\DB;
use App\Utils\Config;
use PDO;

class PlayerMaterialStashService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?MaterialStashTabResolver $tabs = null
    ) {
        $this->tabs ??= new MaterialStashTabResolver();
    }

    public function listForPlayer(int $playerId, ?string $tab = null): array
    {
        if (!$this->tableExists()) {
            return [
                'tabs' => $this->tabs->tabs(),
                'stacks' => [],
                'grid' => [
                    'columns' => (int) Config::get('materials.grid_columns', 12),
                    'cell_px' => (int) Config::get('materials.grid_cell_px', 52),
                ],
            ];
        }

        $sql = 'SELECT
                pms.quantity,
                pms.stash_tab,
                mf.code AS family_code,
                mf.name AS family_name,
                mf.description AS family_description,
                mo.code AS origin_code,
                mo.name AS origin_name,
                mo.description AS origin_description
            FROM player_material_stacks pms
            INNER JOIN material_families mf ON mf.id = pms.material_family_id
            INNER JOIN material_origins mo ON mo.id = pms.material_origin_id
            WHERE pms.player_id = :player_id AND pms.quantity > 0';
        $params = ['player_id' => $playerId];

        if ($tab !== null && $tab !== '') {
            $sql .= ' AND pms.stash_tab = :stash_tab';
            $params['stash_tab'] = $tab;
        }

        $sql .= ' ORDER BY pms.stash_tab ASC, mf.name ASC, mo.name ASC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        $stacks = array_map(fn (array $row): array => $this->presentStack($row), $stmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'tabs' => $this->tabs->tabs(),
            'stacks' => $stacks,
            'grid' => [
                'columns' => (int) Config::get('materials.grid_columns', 12),
                'cell_px' => (int) Config::get('materials.grid_cell_px', 52),
            ],
        ];
    }

    public function credit(int $playerId, int $familyId, int $originId, int $quantity, string $stashTab): void
    {
        if ($quantity <= 0 || !$this->tableExists()) {
            return;
        }

        if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo()->prepare('INSERT INTO player_material_stacks (player_id, material_family_id, material_origin_id, stash_tab, quantity)
                VALUES (:player_id, :material_family_id, :material_origin_id, :stash_tab, :quantity)
                ON CONFLICT(player_id, material_family_id, material_origin_id) DO UPDATE SET
                    quantity = quantity + excluded.quantity,
                    stash_tab = excluded.stash_tab');
        } else {
            $stmt = $this->pdo()->prepare('INSERT INTO player_material_stacks (player_id, material_family_id, material_origin_id, stash_tab, quantity)
                VALUES (:player_id, :material_family_id, :material_origin_id, :stash_tab, :quantity)
                ON DUPLICATE KEY UPDATE
                    quantity = quantity + VALUES(quantity),
                    stash_tab = VALUES(stash_tab)');
        }

        $stmt->execute([
            'player_id' => $playerId,
            'material_family_id' => $familyId,
            'material_origin_id' => $originId,
            'stash_tab' => $stashTab,
            'quantity' => $quantity,
        ]);
    }

    private function presentStack(array $row): array
    {
        $familyCode = (string) ($row['family_code'] ?? '');
        $originCode = (string) ($row['origin_code'] ?? '');

        return [
            'stack_key' => $this->stackKey($familyCode, $originCode),
            'stash_tab' => (string) $row['stash_tab'],
            'family_code' => $familyCode,
            'family_name' => (string) ($row['family_name'] ?? ''),
            'family_description' => (string) ($row['family_description'] ?? ''),
            'origin_code' => $originCode,
            'origin_name' => (string) ($row['origin_name'] ?? ''),
            'origin_description' => (string) ($row['origin_description'] ?? ''),
            'quantity' => (int) ($row['quantity'] ?? 0),
            'label' => $this->labelForStack($row),
            'icon_url' => $this->iconUrlForStack($familyCode, $originCode),
            'craft_source' => [
                'kind' => 'material_stack',
                'family_code' => $familyCode,
                'origin_code' => $originCode,
            ],
        ];
    }

    private function stackKey(string $familyCode, string $originCode): string
    {
        return "{$familyCode}::{$originCode}";
    }

    private function iconUrlForFamily(string $familyCode): ?string
    {
        if ($familyCode === '' || !preg_match('/^[a-z0-9_-]+$/i', $familyCode)) {
            return null;
        }

        return "/assets/game/materials/{$familyCode}.png";
    }

    private function iconUrlForStack(string $familyCode, string $originCode): ?string
    {
        if ($originCode !== '' && preg_match('/^[a-z0-9_-]+$/i', $originCode)) {
            $originPath = dirname(__DIR__, 4).'/public/assets/game/materials/'.$originCode.'.png';
            if (is_file($originPath)) {
                return '/assets/game/materials/'.$originCode.'.png';
            }
        }

        return $this->iconUrlForFamily($familyCode);
    }

    private function labelForStack(array $row): string
    {
        $origin = trim((string) ($row['origin_name'] ?? ''));
        if ($origin !== '') {
            return $origin;
        }

        return (string) ($row['family_name'] ?? $row['family_code'] ?? 'Material');
    }

    private function tableExists(): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'player_material_stacks' LIMIT 1");
            $stmt->execute();

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => 'player_material_stacks']);

        return (bool) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
