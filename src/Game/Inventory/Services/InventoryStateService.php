<?php

namespace App\Game\Inventory\Services;

use App\Game\Containers\Services\ContainerAcceptanceSummaryService;
use App\Game\Containers\Services\ContainerNestingService;
use App\Game\Equipment\Services\ExpeditionCarryCapacityService;
use App\Game\Inventory\InventoryException;
use App\Game\Items\Services\ItemPowerService;
use App\Support\DB;
use PDO;

class InventoryStateService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function forPlayer(int $playerId): array
    {
        (new ExpeditionCarryCapacityService($this->pdo()))->ensureBaselineForPlayer($playerId);

        $containers = $this->containers($playerId);
        $items = $this->items($playerId);

        foreach ($items as $item) {
            $containerId = (int) $item['container_id'];
            if (!isset($containers[$containerId])) {
                continue;
            }

            $containers[$containerId]['items'][] = $this->mapItem($item);
        }

        $acceptanceSummary = new ContainerAcceptanceSummaryService(null, $this->pdo());
        $nesting = new ContainerNestingService($this->pdo());
        foreach ($containers as $containerId => $container) {
            $containers[$containerId]['acceptance_summary'] = $acceptanceSummary->forContainer($container);
            $containers[$containerId]['nesting_depth'] = $nesting->nestingDepth($container);
            $containers[$containerId]['parent_chain'] = $nesting->parentChain($container, $playerId);
        }

        $equipment = $this->equipment($playerId);
        $characterStats = $this->characterStats($playerId);

        return [
            'containers' => array_values(array_map(function (array $container): array {
                unset($container['internal_id']);

                return $container;
            }, $containers)),
            'equipment' => $equipment,
            'character_stats' => $characterStats,
            'player_power' => (new ItemPowerService())->forEquippedPlayer($equipment, $characterStats),
            'equipment_links' => $this->equipmentLinks($playerId),
            'active_set_bonuses' => $this->activeSetBonuses($playerId),
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
            'equipped_item_count' => count(array_filter($this->equipment($playerId), fn (array $slot): bool => $slot['item'] !== null)),
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
                ii.id AS item_instance_id,
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
                cinst.grid_columns,
                cinst.grid_rows,
                cd.code AS definition_code,
                (
                    SELECT COUNT(*)
                    FROM container_items ci
                    WHERE ci.container_instance_id = cinst.id
                ) AS item_count
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
            'grid' => [
                'columns' => (int) $row['grid_columns'],
                'rows' => (int) $row['grid_rows'],
            ],
            'item_count' => (int) ($row['item_count'] ?? 0),
            'capacity_cells' => max(1, (int) $row['grid_columns']) * max(1, (int) $row['grid_rows']),
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
                ii.id AS item_instance_id,
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
                ic.code AS category_code,
                linked.public_id AS linked_container_public_id,
                linked_cd.code AS linked_container_definition_code,
                linked.name AS linked_container_name,
                linked.grid_columns AS linked_container_grid_columns,
                linked.grid_rows AS linked_container_grid_rows,
                (
                    SELECT COUNT(*)
                    FROM container_items linked_ci
                    WHERE linked_ci.container_instance_id = linked.id
                ) AS linked_container_item_count
            FROM container_items ci
            INNER JOIN item_instances ii ON ii.id = ci.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            INNER JOIN container_instances cinst ON cinst.id = ci.container_instance_id
            LEFT JOIN player_equipment pe ON pe.item_instance_id = ii.id AND pe.player_id = :equipment_player_id
            LEFT JOIN container_instances linked ON linked.source_item_instance_id = ii.id AND linked.status = :linked_status
            LEFT JOIN container_definitions linked_cd ON linked_cd.id = linked.container_definition_id
            WHERE cinst.owner_player_id = :container_owner_player_id
                AND ii.owner_player_id = :item_owner_player_id
                AND cinst.status = :container_status
                AND pe.item_instance_id IS NULL
            ORDER BY cinst.sort_order ASC, ci.grid_y ASC, ci.grid_x ASC, ci.id ASC');
        $stmt->execute([
            'equipment_player_id' => $playerId,
            'container_owner_player_id' => $playerId,
            'item_owner_player_id' => $playerId,
            'container_status' => 'active',
            'linked_status' => 'active',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function equipment(int $playerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT
                es.id AS slot_id,
                es.code AS slot_code,
                es.name AS slot_name,
                es.sort_order AS slot_sort_order,
                ii.id AS item_instance_id,
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
                id.base_config,
                ic.code AS category_code,
                linked.public_id AS linked_container_public_id,
                linked_cd.code AS linked_container_definition_code,
                linked.name AS linked_container_name,
                linked.grid_columns AS linked_container_grid_columns,
                linked.grid_rows AS linked_container_grid_rows,
                (
                    SELECT COUNT(*)
                    FROM container_items linked_ci
                    WHERE linked_ci.container_instance_id = linked.id
                ) AS linked_container_item_count
            FROM equipment_slots es
            LEFT JOIN player_equipment pe ON pe.equipment_slot_id = es.id AND pe.player_id = :player_id
            LEFT JOIN item_instances ii ON ii.id = pe.item_instance_id AND ii.owner_player_id = :item_owner_player_id
            LEFT JOIN item_definitions id ON id.id = ii.item_definition_id
            LEFT JOIN item_categories ic ON ic.id = id.category_id
            LEFT JOIN container_instances linked ON linked.source_item_instance_id = ii.id AND linked.status = :linked_status
            LEFT JOIN container_definitions linked_cd ON linked_cd.id = linked.container_definition_id
            WHERE es.status = :status
            ORDER BY es.sort_order ASC, es.id ASC');
        $stmt->execute([
            'player_id' => $playerId,
            'item_owner_player_id' => $playerId,
            'linked_status' => 'active',
            'status' => 'active',
        ]);

        return array_map(function (array $row): array {
            return [
                'code' => (string) $row['slot_code'],
                'name' => (string) $row['slot_name'],
                'sort_order' => (int) $row['slot_sort_order'],
                'item' => $row['item_public_id'] !== null ? $this->mapEquipmentItem($row) : null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function characterStats(int $playerId): array
    {
        $stats = [];
        $this->mergeStatRows($stats, $this->equipmentPropertyRows($playerId));

        if ($this->tableExists('item_instance_affixes')) {
            $this->mergeStatRows($stats, $this->equipmentAffixRows($playerId));
        }

        $this->mergeStatRows($stats, $this->setBonusStatRows($playerId));

        ksort($stats);

        return array_values($stats);
    }

    private function equipmentPropertyRows(int $playerId): array
    {
        if (!$this->tableExists('item_instance_properties')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT
                ipd.code,
                ipd.name,
                ipd.unit,
                COALESCE(iip.integer_value, iip.numeric_value, 0) AS value
            FROM player_equipment pe
            INNER JOIN item_instance_properties iip ON iip.item_instance_id = pe.item_instance_id
            INNER JOIN item_property_definitions ipd ON ipd.id = iip.property_definition_id
            WHERE pe.player_id = :player_id');
        $stmt->execute(['player_id' => $playerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function equipmentAffixRows(int $playerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT
                ipd.code,
                ipd.name,
                ipd.unit,
                iia.rolled_value AS value
            FROM player_equipment pe
            INNER JOIN item_instance_affixes iia ON iia.item_instance_id = pe.item_instance_id
            INNER JOIN item_affix_definitions iad ON iad.id = iia.affix_definition_id
            INNER JOIN item_property_definitions ipd ON ipd.id = iad.property_definition_id
            WHERE pe.player_id = :player_id');
        $stmt->execute(['player_id' => $playerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function mergeStatRows(array &$stats, array $rows): void
    {
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            if (!isset($stats[$code])) {
                $stats[$code] = [
                    'code' => $code,
                    'name' => (string) $row['name'],
                    'unit' => $row['unit'] !== null ? (string) $row['unit'] : null,
                    'value' => 0.0,
                ];
            }

            $stats[$code]['value'] += (float) $row['value'];
        }
    }

    private function equipmentLinks(int $playerId): array
    {
        if (!$this->tableExists('item_sets')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT
                iset.code AS set_code,
                iset.name AS set_name,
                iset.aura_color,
                es.code AS slot_code,
                ii.public_id AS item_public_id,
                id.code AS definition_code,
                isp.sort_order
            FROM player_equipment pe
            INNER JOIN equipment_slots es ON es.id = pe.equipment_slot_id
            INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_set_pieces isp ON isp.item_definition_id = id.id
            INNER JOIN item_sets iset ON iset.id = isp.item_set_id
            WHERE pe.player_id = :player_id
                AND iset.status = :status
            ORDER BY iset.code ASC, isp.sort_order ASC, es.sort_order ASC');
        $stmt->execute([
            'player_id' => $playerId,
            'status' => 'active',
        ]);

        $sets = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (string) $row['set_code'];
            $sets[$code] ??= [
                'set_code' => $code,
                'set_name' => (string) $row['set_name'],
                'aura_color' => (string) $row['aura_color'],
                'slots' => [],
            ];
            $sets[$code]['slots'][] = [
                'slot_code' => (string) $row['slot_code'],
                'item_public_id' => (string) $row['item_public_id'],
                'definition_code' => (string) $row['definition_code'],
            ];
        }

        return array_values(array_filter($sets, fn (array $set): bool => count($set['slots']) >= 2));
    }

    private function activeSetBonuses(int $playerId): array
    {
        if (!$this->tableExists('item_sets')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT
                iset.code AS set_code,
                iset.name AS set_name,
                iset.aura_color,
                COUNT(DISTINCT pe.item_instance_id) AS equipped_pieces
            FROM item_sets iset
            INNER JOIN item_set_pieces isp ON isp.item_set_id = iset.id
            INNER JOIN item_definitions id ON id.id = isp.item_definition_id
            INNER JOIN item_instances ii ON ii.item_definition_id = id.id
            INNER JOIN player_equipment pe ON pe.item_instance_id = ii.id AND pe.player_id = :player_id
            WHERE iset.status = :status
            GROUP BY iset.id, iset.code, iset.name, iset.aura_color');
        $stmt->execute([
            'player_id' => $playerId,
            'status' => 'active',
        ]);

        $bonuses = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $setRow) {
            $bonusRows = $this->setBonusRows((string) $setRow['set_code'], (int) $setRow['equipped_pieces']);
            if ($bonusRows === []) {
                continue;
            }

            $bonuses[] = [
                'set_code' => (string) $setRow['set_code'],
                'set_name' => (string) $setRow['set_name'],
                'aura_color' => (string) $setRow['aura_color'],
                'equipped_pieces' => (int) $setRow['equipped_pieces'],
                'bonuses' => $bonusRows,
            ];
        }

        return $bonuses;
    }

    private function setBonusRows(string $setCode, int $equippedPieces): array
    {
        $stmt = $this->pdo()->prepare('SELECT
                isb.required_pieces,
                isb.description,
                ipd.code,
                ipd.name,
                ipd.unit,
                COALESCE(isb.integer_value, isb.numeric_value, 0) AS value
            FROM item_set_bonuses isb
            INNER JOIN item_sets iset ON iset.id = isb.item_set_id
            INNER JOIN item_property_definitions ipd ON ipd.id = isb.property_definition_id
            WHERE iset.code = :set_code
                AND isb.required_pieces <= :equipped_pieces
            ORDER BY isb.required_pieces ASC, ipd.name ASC');
        $stmt->execute([
            'set_code' => $setCode,
            'equipped_pieces' => $equippedPieces,
        ]);

        return array_map(fn (array $row): array => [
            'required_pieces' => (int) $row['required_pieces'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'unit' => $row['unit'] !== null ? (string) $row['unit'] : null,
            'value' => (float) $row['value'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function setBonusStatRows(int $playerId): array
    {
        $rows = [];
        foreach ($this->activeSetBonuses($playerId) as $set) {
            foreach ($set['bonuses'] as $bonus) {
                $rows[] = [
                    'code' => (string) $bonus['code'],
                    'name' => (string) $bonus['name'],
                    'unit' => $bonus['unit'],
                    'value' => (float) $bonus['value'],
                ];
            }
        }

        return $rows;
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
                'category_code' => $row['category_code'] !== null ? (string) $row['category_code'] : null,
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
            'properties' => $this->propertiesForItem((int) $row['item_instance_id']),
            'affixes' => $this->affixesForItem((int) $row['item_instance_id']),
            'sockets' => $this->socketsForItem((int) $row['item_instance_id']),
        ];

        if (!empty($row['linked_container_public_id'])) {
            $mapped['linked_container'] = $this->mapLinkedContainerRow($row);
        }

        $mapped['power'] = (new ItemPowerService())->forItem($mapped);

        return $mapped;
    }

    private function mapEquipmentItem(array $row): array
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
                'base_config' => $this->parseBaseConfig($row['base_config'] ?? null),
                'category_code' => $row['category_code'] !== null ? (string) $row['category_code'] : null,
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
            'equipped' => true,
            'properties' => $this->propertiesForItem((int) $row['item_instance_id']),
            'affixes' => $this->affixesForItem((int) $row['item_instance_id']),
            'sockets' => $this->socketsForItem((int) $row['item_instance_id']),
        ];

        if (!empty($row['linked_container_public_id'])) {
            $mapped['linked_container'] = $this->mapLinkedContainerRow($row);
        }

        $mapped['power'] = (new ItemPowerService())->forItem($mapped);

        return $mapped;
    }

    private function mapLinkedContainerRow(array $row): array
    {
        $columns = max(1, (int) ($row['linked_container_grid_columns'] ?? 1));
        $rows = max(1, (int) ($row['linked_container_grid_rows'] ?? 1));

        return [
            'public_id' => (string) $row['linked_container_public_id'],
            'definition_code' => (string) ($row['linked_container_definition_code'] ?? ''),
            'name' => (string) ($row['linked_container_name'] ?? ''),
            'grid' => [
                'columns' => $columns,
                'rows' => $rows,
            ],
            'item_count' => (int) ($row['linked_container_item_count'] ?? 0),
            'capacity_cells' => $columns * $rows,
        ];
    }

    private function propertiesForItem(int $itemInstanceId): array
    {
        if (!$this->tableExists('item_instance_properties')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT
                ipd.code,
                ipd.name,
                ipd.value_type,
                ipd.unit,
                iip.numeric_value,
                iip.integer_value,
                iip.text_value,
                iip.source
            FROM item_instance_properties iip
            INNER JOIN item_property_definitions ipd ON ipd.id = iip.property_definition_id
            WHERE iip.item_instance_id = :item_instance_id
            ORDER BY ipd.name ASC, iip.source ASC');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return array_map(fn (array $row): array => [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'value_type' => (string) $row['value_type'],
            'unit' => $row['unit'] !== null ? (string) $row['unit'] : null,
            'value' => $row['integer_value'] !== null
                ? (int) $row['integer_value']
                : ($row['numeric_value'] !== null ? (float) $row['numeric_value'] : ($row['text_value'] !== null ? (string) $row['text_value'] : null)),
            'source' => (string) $row['source'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function affixesForItem(int $itemInstanceId): array
    {
        if (!$this->tableExists('item_instance_affixes')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT
                iad.code,
                iad.name,
                iad.affix_type,
                ipd.code AS property_code,
                ipd.name AS property_name,
                ipd.unit,
                iia.rolled_value,
                iia.source
            FROM item_instance_affixes iia
            INNER JOIN item_affix_definitions iad ON iad.id = iia.affix_definition_id
            INNER JOIN item_property_definitions ipd ON ipd.id = iad.property_definition_id
            WHERE iia.item_instance_id = :item_instance_id
            ORDER BY iad.affix_type ASC, iad.name ASC');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return array_map(fn (array $row): array => [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'type' => (string) $row['affix_type'],
            'property_code' => (string) $row['property_code'],
            'property_name' => (string) $row['property_name'],
            'unit' => $row['unit'] !== null ? (string) $row['unit'] : null,
            'value' => (float) $row['rolled_value'],
            'source' => (string) $row['source'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function socketsForItem(int $itemInstanceId): array
    {
        if (!$this->tableExists('item_instance_sockets')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT
                iis.id,
                iis.socket_index,
                iis.socket_type,
                iis.status,
                gem.public_id AS gem_public_id,
                gem_def.code AS gem_definition_code,
                gem_def.name AS gem_name
            FROM item_instance_sockets iis
            LEFT JOIN item_socketed_gems isg ON isg.socket_id = iis.id
            LEFT JOIN item_instances gem ON gem.id = isg.gem_item_instance_id
            LEFT JOIN item_definitions gem_def ON gem_def.id = gem.item_definition_id
            WHERE iis.item_instance_id = :item_instance_id
            ORDER BY iis.socket_index ASC');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return array_map(fn (array $row): array => [
            'index' => (int) $row['socket_index'],
            'type' => (string) $row['socket_type'],
            'status' => (string) $row['status'],
            'gem' => $row['gem_public_id'] !== null ? [
                'public_id' => (string) $row['gem_public_id'],
                'definition_code' => (string) $row['gem_definition_code'],
                'name' => (string) $row['gem_name'],
            ] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function tableExists(string $table): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return $stmt->fetchColumn() !== false;
        }

        return $this->pdo()->query('SHOW TABLES LIKE ' . $this->pdo()->quote($table))->fetchColumn() !== false;
    }

    private function parseBaseConfig(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
