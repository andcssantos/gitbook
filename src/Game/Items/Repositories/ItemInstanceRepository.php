<?php

namespace App\Game\Items\Repositories;

use App\Support\DB;
use App\Support\PublicId;
use PDO;

class ItemInstanceRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function create(array $data): int
    {
        $payload = array_merge([
            'public_id' => PublicId::uuid(),
            'quantity' => 1,
            'state' => 'available',
            'bind_type' => 'none',
        ], $data);

        $stmt = $this->pdo()->prepare('INSERT INTO item_instances (
            public_id,
            item_definition_id,
            owner_player_id,
            quantity,
            quality_value,
            quality_bucket,
            material_origin_id,
            item_name,
            current_durability,
            max_durability,
            bind_type,
            state
        ) VALUES (
            :public_id,
            :item_definition_id,
            :owner_player_id,
            :quantity,
            :quality_value,
            :quality_bucket,
            :material_origin_id,
            :item_name,
            :current_durability,
            :max_durability,
            :bind_type,
            :state
        )');

        $stmt->execute([
            'public_id' => $payload['public_id'],
            'item_definition_id' => $payload['item_definition_id'],
            'owner_player_id' => $payload['owner_player_id'],
            'quantity' => $payload['quantity'],
            'quality_value' => $payload['quality_value'] ?? null,
            'quality_bucket' => $payload['quality_bucket'] ?? null,
            'material_origin_id' => $payload['material_origin_id'] ?? null,
            'item_name' => $payload['item_name'] ?? null,
            'current_durability' => $payload['current_durability'] ?? null,
            'max_durability' => $payload['max_durability'] ?? null,
            'bind_type' => $payload['bind_type'],
            'state' => $payload['state'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function findByPublicIdAndOwner(string $publicId, int $playerId, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT
                ii.*,
                id.code AS definition_code,
                id.grid_w AS definition_grid_w,
                id.grid_h AS definition_grid_h,
                id.is_container,
                id.stackable,
                id.max_stack,
                id.equip_slot_code,
                id.base_config,
                ic.code AS category_code,
                mf.code AS material_family_code
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            LEFT JOIN material_families mf ON mf.id = id.material_family_id
            WHERE ii.public_id = :public_id AND ii.owner_player_id = :player_id
            LIMIT 1' . $this->lockClause($lock));
        $stmt->execute([
            'public_id' => $publicId,
            'player_id' => $playerId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findByPublicId(string $publicId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM item_instances WHERE public_id = :public_id LIMIT 1');
        $stmt->execute(['public_id' => $publicId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function updateStack(int $itemInstanceId, int $quantity, ?float $qualityValue): void
    {
        $stmt = $this->pdo()->prepare('UPDATE item_instances
            SET quantity = :quantity,
                quality_value = :quality_value,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id');
        $stmt->execute([
            'id' => $itemInstanceId,
            'quantity' => $quantity,
            'quality_value' => $qualityValue,
        ]);
    }

    public function deleteById(int $itemInstanceId): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM item_instances WHERE id = :id');
        $stmt->execute(['id' => $itemInstanceId]);
    }

    public function listPlacedForPlayer(int $playerId, bool $lock = false): array
    {
        $stmt = $this->pdo()->prepare('SELECT
                ii.*,
                id.code AS definition_code,
                id.grid_w AS definition_grid_w,
                id.grid_h AS definition_grid_h,
                id.is_container,
                id.stackable,
                id.max_stack,
                id.equip_slot_code,
                id.base_config,
                ic.code AS category_code,
                mf.code AS material_family_code,
                ci.container_instance_id,
                ci.grid_x,
                ci.grid_y,
                cinst.public_id AS container_public_id,
                cinst.sort_order AS container_sort_order,
                cd.container_type,
                cd.code AS container_definition_code
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            LEFT JOIN material_families mf ON mf.id = id.material_family_id
            INNER JOIN container_items ci ON ci.item_instance_id = ii.id
            INNER JOIN container_instances cinst ON cinst.id = ci.container_instance_id
            INNER JOIN container_definitions cd ON cd.id = cinst.container_definition_id
            WHERE ii.owner_player_id = :player_id
                AND cinst.owner_player_id = :player_id
                AND cinst.status = :status
            ORDER BY cinst.sort_order ASC, ci.grid_y ASC, ci.grid_x ASC, ci.id ASC' . $this->lockClause($lock));
        $stmt->execute([
            'player_id' => $playerId,
            'status' => 'active',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createSplitStack(array $source, int $quantity): int
    {
        return $this->create([
            'item_definition_id' => (int) $source['item_definition_id'],
            'owner_player_id' => (int) $source['owner_player_id'],
            'quantity' => $quantity,
            'quality_value' => $source['quality_value'] !== null ? (float) $source['quality_value'] : null,
            'quality_bucket' => $source['quality_bucket'] !== null ? (string) $source['quality_bucket'] : null,
            'material_origin_id' => $source['material_origin_id'] !== null ? (int) $source['material_origin_id'] : null,
            'item_name' => $source['item_name'] !== null ? (string) $source['item_name'] : null,
            'current_durability' => $source['current_durability'] !== null ? (int) $source['current_durability'] : null,
            'max_durability' => $source['max_durability'] !== null ? (int) $source['max_durability'] : null,
            'bind_type' => (string) $source['bind_type'],
            'state' => (string) $source['state'],
        ]);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }

    private function lockClause(bool $lock): string
    {
        return $lock && $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
