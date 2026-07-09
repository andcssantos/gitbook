<?php

namespace App\Game\Inventory\Services;

use App\Support\DB;
use PDO;

class InventoryStateService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function forPlayer(int $playerId): array
    {
        $containers = $this->containers($playerId);
        $items = $this->items($playerId);

        foreach ($items as $item) {
            $containerId = (int) $item['container_id'];
            if (!isset($containers[$containerId])) {
                continue;
            }

            $containers[$containerId]['items'][] = $this->mapItem($item);
        }

        return [
            'containers' => array_values($containers),
        ];
    }

    private function containers(int $playerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT
                ci.id,
                ci.public_id,
                ci.name,
                ci.grid_columns,
                ci.grid_rows,
                ci.status,
                ci.sort_order,
                ci.source_item_instance_id,
                cd.code AS definition_code,
                cd.name AS definition_name,
                cd.container_type,
                cd.allow_container_items
            FROM container_instances ci
            INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id
            WHERE ci.owner_player_id = :player_id AND ci.status = :status
            ORDER BY ci.sort_order ASC, ci.id ASC');
        $stmt->execute([
            'player_id' => $playerId,
            'status' => 'active',
        ]);

        $containers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $containers[$id] = [
                'public_id' => (string) $row['public_id'],
                'definition_code' => (string) $row['definition_code'],
                'name' => (string) $row['name'],
                'type' => (string) $row['container_type'],
                'grid' => [
                    'columns' => (int) $row['grid_columns'],
                    'rows' => (int) $row['grid_rows'],
                ],
                'allow_container_items' => (bool) $row['allow_container_items'],
                'source_item_instance_id' => $row['source_item_instance_id'] !== null ? (int) $row['source_item_instance_id'] : null,
                'items' => [],
            ];
        }

        return $containers;
    }

    private function items(int $playerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT
                ci.container_instance_id AS container_id,
                ci.grid_x,
                ci.grid_y,
                ci.grid_w,
                ci.grid_h,
                ci.rotated,
                ci.locked,
                ci.placement_version,
                ii.public_id AS item_public_id,
                ii.quantity,
                ii.quality_value,
                ii.quality_bucket,
                ii.item_name,
                ii.current_durability,
                ii.max_durability,
                ii.bind_type,
                ii.state,
                id.code AS definition_code,
                id.name AS definition_name,
                id.description AS definition_description,
                id.stackable,
                id.max_stack,
                id.grid_w AS definition_grid_w,
                id.grid_h AS definition_grid_h,
                id.equip_slot_code,
                id.is_container,
                id.tradeable
            FROM container_items ci
            INNER JOIN item_instances ii ON ii.id = ci.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN container_instances cinst ON cinst.id = ci.container_instance_id
            WHERE cinst.owner_player_id = :container_owner_player_id
                AND ii.owner_player_id = :item_owner_player_id
                AND cinst.status = :container_status
            ORDER BY cinst.sort_order ASC, ci.grid_y ASC, ci.grid_x ASC, ci.id ASC');
        $stmt->execute([
            'container_owner_player_id' => $playerId,
            'item_owner_player_id' => $playerId,
            'container_status' => 'active',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function mapItem(array $row): array
    {
        return [
            'public_id' => (string) $row['item_public_id'],
            'definition' => [
                'code' => (string) $row['definition_code'],
                'name' => (string) $row['definition_name'],
                'description' => $row['definition_description'] !== null ? (string) $row['definition_description'] : null,
                'stackable' => (bool) $row['stackable'],
                'max_stack' => (int) $row['max_stack'],
                'grid_w' => (int) $row['definition_grid_w'],
                'grid_h' => (int) $row['definition_grid_h'],
                'equip_slot_code' => $row['equip_slot_code'] !== null ? (string) $row['equip_slot_code'] : null,
                'is_container' => (bool) $row['is_container'],
                'tradeable' => (bool) $row['tradeable'],
            ],
            'quantity' => (int) $row['quantity'],
            'quality_value' => $row['quality_value'] !== null ? (float) $row['quality_value'] : null,
            'quality_bucket' => $row['quality_bucket'] !== null ? (string) $row['quality_bucket'] : null,
            'item_name' => $row['item_name'] !== null ? (string) $row['item_name'] : null,
            'durability' => [
                'current' => $row['current_durability'] !== null ? (int) $row['current_durability'] : null,
                'max' => $row['max_durability'] !== null ? (int) $row['max_durability'] : null,
            ],
            'bind_type' => (string) $row['bind_type'],
            'state' => (string) $row['state'],
            'placement' => [
                'grid_x' => (int) $row['grid_x'],
                'grid_y' => (int) $row['grid_y'],
                'grid_w' => (int) $row['grid_w'],
                'grid_h' => (int) $row['grid_h'],
                'rotated' => (bool) $row['rotated'],
                'locked' => (bool) $row['locked'],
                'placement_version' => (int) $row['placement_version'],
            ],
        ];
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
