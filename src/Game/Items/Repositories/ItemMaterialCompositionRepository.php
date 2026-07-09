<?php

namespace App\Game\Items\Repositories;

use App\Support\DB;
use PDO;

class ItemMaterialCompositionRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function listForItem(int $itemInstanceId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM item_material_composition WHERE item_instance_id = :item_instance_id ORDER BY id ASC');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function replaceForItem(int $itemInstanceId, array $rows): void
    {
        $this->deleteForItem($itemInstanceId);

        foreach ($rows as $row) {
            $stmt = $this->pdo()->prepare('INSERT INTO item_material_composition (
                item_instance_id,
                material_family_id,
                material_origin_id,
                percentage,
                average_quality
            ) VALUES (
                :item_instance_id,
                :material_family_id,
                :material_origin_id,
                :percentage,
                :average_quality
            )');
            $stmt->execute([
                'item_instance_id' => $itemInstanceId,
                'material_family_id' => $row['material_family_id'],
                'material_origin_id' => $row['material_origin_id'],
                'percentage' => $row['percentage'],
                'average_quality' => $row['average_quality'] ?? null,
            ]);
        }
    }

    public function copyForItem(int $sourceItemInstanceId, int $targetItemInstanceId): void
    {
        $this->replaceForItem($targetItemInstanceId, $this->listForItem($sourceItemInstanceId));
    }

    public function deleteForItem(int $itemInstanceId): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM item_material_composition WHERE item_instance_id = :item_instance_id');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
