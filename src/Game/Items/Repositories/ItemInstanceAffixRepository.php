<?php

namespace App\Game\Items\Repositories;

use App\Support\DB;
use PDO;

class ItemInstanceAffixRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function listForItem(int $itemInstanceId): array
    {
        $stmt = $this->pdo()->prepare('SELECT
                iia.*,
                iad.code,
                iad.name,
                iad.affix_type,
                iad.min_value,
                iad.max_value,
                ipd.code AS property_code,
                ipd.name AS property_name,
                ipd.equipment_scope
            FROM item_instance_affixes iia
            INNER JOIN item_affix_definitions iad ON iad.id = iia.affix_definition_id
            INNER JOIN item_property_definitions ipd ON ipd.id = iad.property_definition_id
            WHERE iia.item_instance_id = :item_instance_id
            ORDER BY iia.id ASC');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countForItem(int $itemInstanceId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM item_instance_affixes WHERE item_instance_id = :item_instance_id');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return (int) $stmt->fetchColumn();
    }

    public function updateRolledValue(int $affixInstanceId, float $value): void
    {
        $stmt = $this->pdo()->prepare('UPDATE item_instance_affixes SET rolled_value = :rolled_value WHERE id = :id');
        $stmt->execute([
            'id' => $affixInstanceId,
            'rolled_value' => $value,
        ]);
    }

    public function insert(int $itemInstanceId, int $affixDefinitionId, float $rolledValue, string $source = 'soul_jewel'): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO item_instance_affixes (
            item_instance_id,
            affix_definition_id,
            rolled_value,
            source
        ) VALUES (
            :item_instance_id,
            :affix_definition_id,
            :rolled_value,
            :source
        )');
        $stmt->execute([
            'item_instance_id' => $itemInstanceId,
            'affix_definition_id' => $affixDefinitionId,
            'rolled_value' => $rolledValue,
            'source' => $source,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function deleteById(int $affixInstanceId): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM item_instance_affixes WHERE id = :id');
        $stmt->execute(['id' => $affixInstanceId]);
    }

    public function listEligibleDefinitionsForCategory(string $categoryCode, int $upgradeLevel = 0): array
    {
        $stmt = $this->pdo()->prepare('SELECT
                iad.*,
                ipd.code AS property_code,
                ipd.equipment_scope
            FROM item_affix_definitions iad
            INNER JOIN item_property_definitions ipd ON ipd.id = iad.property_definition_id
            WHERE iad.status = :status
                AND iad.min_item_level <= :upgrade_level
            ORDER BY iad.rarity_weight DESC, iad.name ASC');
        $stmt->execute([
            'status' => 'active',
            'upgrade_level' => max(1, $upgradeLevel),
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
