<?php

namespace App\Game\Materials\Services;

use App\Support\DB;
use App\Utils\Config;
use PDO;

class DismantleYieldCalculator
{
    public function __construct(
        private ?PDO $connection = null,
        private ?MaterialCompositionResolver $composition = null
    ) {
        $this->composition ??= new MaterialCompositionResolver($this->connection);
    }

    public function preview(array $item): array
    {
        $composition = $this->composition->resolveForItem($item, true);
        if ($composition === []) {
            return [];
        }

        $categoryCode = (string) ($item['category_code'] ?? $item['definition']['category_code'] ?? 'material');
        $baseUnits = $this->baseUnits($categoryCode);
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $upgradeLevel = $this->upgradeLevel($item);
        $upgradeBonus = 1 + ($upgradeLevel * (float) Config::get('materials.dismantle.upgrade_bonus_per_level', 0.04));
        $rarityMultiplier = $this->rarityMultiplier((string) ($item['quality_bucket'] ?? 'common'));
        $scale = $baseUnits * $quantity * $upgradeBonus * $rarityMultiplier;

        $yields = [];
        foreach ($composition as $row) {
            $amount = max(1, (int) floor($scale * ((float) $row['percentage'] / 100)));
            $yields[] = [
                'material_family_id' => (int) $row['material_family_id'],
                'material_origin_id' => (int) $row['material_origin_id'],
                'family_code' => (string) $row['family_code'],
                'family_name' => (string) $row['family_name'],
                'origin_code' => (string) $row['origin_code'],
                'origin_name' => (string) $row['origin_name'],
                'stash_tab' => (string) $row['stash_tab'],
                'quantity' => $amount,
                'label' => $this->label($row),
            ];
        }

        foreach ($this->socketGemYields($item) as $gemYield) {
            $yields[] = $gemYield;
        }

        return $yields;
    }

    private function socketGemYields(array $item): array
    {
        $yields = [];
        $amount = max(1, (int) Config::get('materials.dismantle.socket_gem_yield', 1));

        foreach ($item['sockets'] ?? [] as $socket) {
            $gem = $socket['gem'] ?? null;
            if (!is_array($gem) || empty($gem['definition_code'])) {
                continue;
            }

            $definition = $this->lookupDefinition((string) $gem['definition_code']);
            if ($definition === null) {
                continue;
            }

            $familyId = (int) ($definition['material_family_id'] ?? 0);
            $originId = $this->defaultOriginId();
            if ($familyId <= 0 || $originId <= 0) {
                continue;
            }

            $familyCode = (string) ($definition['family_code'] ?? 'essence');
            $yields[] = [
                'material_family_id' => $familyId,
                'material_origin_id' => $originId,
                'family_code' => $familyCode,
                'family_name' => (string) ($definition['family_name'] ?? $familyCode),
                'origin_code' => (string) ($definition['origin_code'] ?? ''),
                'origin_name' => (string) ($definition['origin_name'] ?? ''),
                'stash_tab' => 'gems',
                'quantity' => $amount,
                'label' => (string) ($gem['name'] ?? $definition['name'] ?? 'Gema'),
            ];
        }

        return $yields;
    }

    private function lookupDefinition(string $code): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT
                id.id,
                id.name,
                id.material_family_id,
                mf.code AS family_code,
                mf.name AS family_name
            FROM item_definitions id
            LEFT JOIN material_families mf ON mf.id = id.material_family_id
            WHERE id.code = :code
            LIMIT 1');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $originId = $this->defaultOriginId();
        $origin = $this->pdo()->prepare('SELECT code, name FROM material_origins WHERE id = :id LIMIT 1');
        $origin->execute(['id' => $originId]);
        $originRow = $origin->fetch(\PDO::FETCH_ASSOC) ?: [];

        return array_merge($row, [
            'origin_code' => (string) ($originRow['code'] ?? ''),
            'origin_name' => (string) ($originRow['name'] ?? ''),
        ]);
    }

    private function defaultOriginId(): int
    {
        $code = (string) Config::get('materials.dismantle.default_origin_code', 'starter_forest');
        $stmt = $this->pdo()->prepare('SELECT id FROM material_origins WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function baseUnits(string $categoryCode): int
    {
        $map = (array) Config::get('materials.dismantle.base_units', []);

        return max(1, (int) ($map[$categoryCode] ?? $map['default'] ?? 3));
    }

    private function rarityMultiplier(string $qualityBucket): float
    {
        $map = (array) Config::get('materials.dismantle.rarity_multiplier', []);

        return (float) ($map[strtolower($qualityBucket)] ?? 1.0);
    }

    private function upgradeLevel(array $item): int
    {
        foreach ($item['properties'] ?? [] as $property) {
            if ((string) ($property['code'] ?? '') === 'upgrade_level') {
                return max(0, (int) ($property['value'] ?? 0));
            }
        }

        return 0;
    }

    private function label(array $row): string
    {
        $family = (string) ($row['family_name'] ?? $row['family_code'] ?? 'Material');
        $origin = (string) ($row['origin_name'] ?? '');

        return $origin !== '' && $origin !== $family
            ? "{$family} ({$origin})"
            : $family;
    }

    private function pdo(): PDO
    {
        return $this->connection ?? DB::pdo();
    }
}
