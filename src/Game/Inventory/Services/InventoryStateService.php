<?php

namespace App\Game\Inventory\Services;

use App\Game\Inventory\InventoryException;
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
            'containers' => array_values(array_map(function (array $container): array {
                unset($container['internal_id']);

                return $container;
            }, $containers)),
        ];
    }

    public function summaryForPlayer(int $playerId): array
    {
        $state = $this->forPlayer($playerId);
        $containers = [];

        foreach ($state['containers'] as $container) {
            $columns = (int) $container['grid']['columns'];
            $rows = (int) $container['grid']['rows'];
            $capacityCells = $columns * $rows;
            $occupiedCells = 0;

            foreach ($container['items'] as $item) {
                $placement = $item['placement'];
                $occupiedCells += (int) $placement['grid_w'] * (int) $placement['grid_h'];
            }

            $containers[] = [
                'public_id' => $container['public_id'],
                'definition_code' => $container['definition_code'],
                'name' => $container['name'],
                'type' => $container['type'],
                'item_count' => count($container['items']),
                'occupied_cells' => $occupiedCells,
                'capacity_cells' => $capacityCells,
                'occupancy_ratio' => $capacityCells > 0 ? round($occupiedCells / $capacityCells, 4) : 0.0,
                'source_item_public_id' => $container['source_item_public_id'],
                'is_physical' => $container['source_item_public_id'] !== null,
            ];
        }

        return [
            'container_count' => count($containers),
            'item_count' => array_sum(array_column($containers, 'item_count')),
            'containers' => $containers,
        ];
    }

    public function containerForPlayer(int $playerId, string $containerPublicId): array
    {
        $containers = $this->containers($playerId);
        $selected = null;

        foreach ($containers as $container) {
            if ($container['public_id'] === $containerPublicId) {
                $selected = $container;
                break;
            }
        }

        if ($selected === null) {
            throw new InventoryException('INVENTORY_CONTAINER_NOT_FOUND', 'Inventory container was not found.', 404);
        }

        foreach ($this->items($playerId) as $item) {
            if ((int) $item['container_id'] !== (int) $selected['internal_id']) {
                continue;
            }

            $selected['items'][] = $this->mapItem($item);
        }

        unset($selected['internal_id']);

        return ['container' => $selected];
    }

    public function itemForPlayer(int $playerId, string $itemPublicId): array
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
                id.tradeable,
                cinst.public_id AS container_public_id,
                cinst.name AS container_name,
                cd.code AS container_definition_code
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN container_items ci ON ci.item_instance_id = ii.id
            INNER JOIN container_instances cinst ON cinst.id = ci.container_instance_id
            INNER JOIN container_definitions cd ON cd.id = cinst.container_definition_id
            WHERE ii.public_id = :item_public_id
                AND ii.owner_player_id = :player_id
                AND cinst.owner_player_id = :player_id
                AND cinst.status = :status
            LIMIT 1');
        $stmt->execute([
            'item_public_id' => $itemPublicId,
            'player_id' => $playerId,
            'status' => 'active',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $exists = $this->pdo()->prepare('SELECT id FROM item_instances WHERE public_id = :public_id LIMIT 1');
            $exists->execute(['public_id' => $itemPublicId]);
            if ($exists->fetchColumn() !== false) {
                throw new InventoryException('INVENTORY_FORBIDDEN', 'Inventory item does not belong to the authenticated player.', 403);
            }

            throw new InventoryException('INVENTORY_ITEM_NOT_FOUND', 'Inventory item was not found.', 404);
        }

        $mapped = $this->mapItem($row);
        $mapped['container'] = [
            'public_id' => (string) $row['container_public_id'],
            'definition_code' => (string) $row['container_definition_code'],
            'name' => (string) $row['container_name'],
        ];

        $linkedContainer = $this->linkedContainerForItem($playerId, $itemPublicId);
        if ($linkedContainer !== null) {
            $mapped['linked_container'] = $linkedContainer;
        }

        return ['item' => $mapped];
    }

    private function linkedContainerForItem(int $playerId, string $itemPublicId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT
                cinst.public_id,
                cinst.name,
                cd.code AS definition_code
            FROM container_instances cinst
            INNER JOIN container_definitions cd ON cd.id = cinst.container_definition_id
            INNER JOIN item_instances ii ON ii.id = cinst.source_item_instance_id
            WHERE ii.public_id = :item_public_id
                AND ii.owner_player_id = :player_id
                AND cinst.owner_player_id = :player_id
                AND cinst.status = :status
            LIMIT 1');
        $stmt->execute([
            'item_public_id' => $itemPublicId,
            'player_id' => $playerId,
            'status' => 'active',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'public_id' => (string) $row['public_id'],
            'definition_code' => (string) $row['definition_code'],
            'name' => (string) $row['name'],
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
                src_ii.public_id AS source_item_public_id,
                cd.code AS definition_code,
                cd.name AS definition_name,
                cd.container_type,
                cd.allow_container_items
            FROM container_instances ci
            INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id
            LEFT JOIN item_instances src_ii ON src_ii.id = ci.source_item_instance_id
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
                'internal_id' => $id,
                'public_id' => (string) $row['public_id'],
                'definition_code' => (string) $row['definition_code'],
                'name' => (string) $row['name'],
                'type' => (string) $row['container_type'],
                'grid' => [
                    'columns' => (int) $row['grid_columns'],
                    'rows' => (int) $row['grid_rows'],
                ],
                'allow_container_items' => (bool) $row['allow_container_items'],
                'source_item_public_id' => $row['source_item_public_id'] !== null ? (string) $row['source_item_public_id'] : null,
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
                id.tradeable,
                linked.public_id AS linked_container_public_id,
                linked_cd.code AS linked_container_definition_code,
                linked.name AS linked_container_name
            FROM container_items ci
            INNER JOIN item_instances ii ON ii.id = ci.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN container_instances cinst ON cinst.id = ci.container_instance_id
            LEFT JOIN container_instances linked ON linked.source_item_instance_id = ii.id AND linked.status = :linked_status
            LEFT JOIN container_definitions linked_cd ON linked_cd.id = linked.container_definition_id
            WHERE cinst.owner_player_id = :container_owner_player_id
                AND ii.owner_player_id = :item_owner_player_id
                AND cinst.status = :container_status
            ORDER BY cinst.sort_order ASC, ci.grid_y ASC, ci.grid_x ASC, ci.id ASC');
        $stmt->execute([
            'container_owner_player_id' => $playerId,
            'item_owner_player_id' => $playerId,
            'container_status' => 'active',
            'linked_status' => 'active',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function mapItem(array $row): array
    {
        $mapped = [
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

        if (!empty($row['linked_container_public_id'])) {
            $mapped['linked_container'] = [
                'public_id' => (string) $row['linked_container_public_id'],
                'definition_code' => (string) ($row['linked_container_definition_code'] ?? ''),
                'name' => (string) ($row['linked_container_name'] ?? ''),
            ];
        }

        return $mapped;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
