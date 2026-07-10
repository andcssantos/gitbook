<?php

namespace App\Game\Market\Services;

use App\Game\Inventory\Services\InventoryStateService;
use App\Support\DB;
use PDO;

class MarketItemContextService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function forOwnedItem(int $playerId, string $itemPublicId, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT
                ii.id AS item_instance_id,
                ii.public_id AS item_public_id,
                ii.quantity,
                ii.quality_value,
                ii.quality_bucket,
                ii.item_name,
                ii.material_origin_id,
                ii.bind_type,
                ii.state,
                id.code AS definition_code,
                id.name AS definition_name,
                id.tradeable,
                id.is_container,
                id.is_collectible,
                id.is_event_item,
                id.equip_slot_code,
                id.material_family_id AS definition_material_family_id,
                ic.code AS category_code,
                mf.code AS material_family_code,
                mf.name AS material_family_name,
                mo.code AS material_origin_code,
                mo.name AS material_origin_name
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            LEFT JOIN material_families mf ON mf.id = id.material_family_id
            LEFT JOIN material_origins mo ON mo.id = ii.material_origin_id
            WHERE ii.public_id = :public_id AND ii.owner_player_id = :player_id
            LIMIT 1' . ($lock && $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $stmt->execute([
            'public_id' => $itemPublicId,
            'player_id' => $playerId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $mapped = $this->mapFromInventoryState($playerId, (string) $row['item_public_id']);
        if ($mapped === null) {
            $mapped = [
                'public_id' => (string) $row['item_public_id'],
                'definition' => [
                    'code' => (string) $row['definition_code'],
                    'name' => (string) $row['definition_name'],
                    'tradeable' => (bool) $row['tradeable'],
                    'is_container' => (bool) $row['is_container'],
                    'is_collectible' => (bool) $row['is_collectible'],
                    'is_event_item' => (bool) $row['is_event_item'],
                    'equip_slot_code' => $row['equip_slot_code'] !== null ? (string) $row['equip_slot_code'] : null,
                    'category_code' => (string) $row['category_code'],
                    'material_family_code' => $row['material_family_code'] !== null ? (string) $row['material_family_code'] : null,
                    'material_family_name' => $row['material_family_name'] !== null ? (string) $row['material_family_name'] : null,
                ],
                'quantity' => (int) $row['quantity'],
                'quality_value' => $row['quality_value'] !== null ? (float) $row['quality_value'] : null,
                'quality_bucket' => $row['quality_bucket'] !== null ? (string) $row['quality_bucket'] : null,
                'bind_type' => (string) $row['bind_type'],
                'state' => (string) $row['state'],
                'properties' => $this->properties((int) $row['item_instance_id']),
                'affixes' => $this->affixes((int) $row['item_instance_id']),
                'sockets' => $this->sockets((int) $row['item_instance_id']),
            ];
        }

        $mapped['item_instance_id'] = (int) $row['item_instance_id'];
        $mapped['owner_player_id'] = $playerId;
        $mapped['definition_code'] = (string) $row['definition_code'];
        $mapped['category_code'] = (string) $row['category_code'];
        $mapped['definition_material_family_id'] = $row['definition_material_family_id'] !== null ? (int) $row['definition_material_family_id'] : null;
        $mapped['material_family_code'] = $row['material_family_code'] !== null ? (string) $row['material_family_code'] : null;
        $mapped['material_family_name'] = $row['material_family_name'] !== null ? (string) $row['material_family_name'] : null;
        $mapped['material_origin_id'] = $row['material_origin_id'] !== null ? (int) $row['material_origin_id'] : null;
        $mapped['material_origin_code'] = $row['material_origin_code'] !== null ? (string) $row['material_origin_code'] : null;
        $mapped['material_origin_name'] = $row['material_origin_name'] !== null ? (string) $row['material_origin_name'] : null;
        $mapped['tradeable'] = (bool) $row['tradeable'];
        $mapped['is_container'] = (bool) $row['is_container'];
        $mapped['is_collectible'] = (bool) $row['is_collectible'];
        $mapped['is_event_item'] = (bool) $row['is_event_item'];
        $mapped['bind_type'] = (string) $row['bind_type'];
        $mapped['state'] = (string) $row['state'];

        return $mapped;
    }

    public function forListedItem(int $itemInstanceId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT
                ii.id AS item_instance_id,
                ii.public_id AS item_public_id,
                ii.owner_player_id,
                ii.quantity,
                ii.quality_value,
                ii.quality_bucket,
                ii.item_name,
                ii.material_origin_id,
                ii.bind_type,
                ii.state,
                id.code AS definition_code,
                id.name AS definition_name,
                id.tradeable,
                id.is_container,
                id.is_collectible,
                id.is_event_item,
                id.equip_slot_code,
                id.grid_w,
                id.grid_h,
                id.material_family_id AS definition_material_family_id,
                ic.code AS category_code,
                mf.code AS material_family_code,
                mf.name AS material_family_name,
                mo.code AS material_origin_code,
                mo.name AS material_origin_name
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            LEFT JOIN material_families mf ON mf.id = id.material_family_id
            LEFT JOIN material_origins mo ON mo.id = ii.material_origin_id
            WHERE ii.id = :item_instance_id
            LIMIT 1');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $mapped = [
            'public_id' => (string) $row['item_public_id'],
            'item_instance_id' => (int) $row['item_instance_id'],
            'owner_player_id' => (int) $row['owner_player_id'],
            'definition_code' => (string) $row['definition_code'],
            'category_code' => (string) $row['category_code'],
            'definition_material_family_id' => $row['definition_material_family_id'] !== null ? (int) $row['definition_material_family_id'] : null,
            'material_family_code' => $row['material_family_code'] !== null ? (string) $row['material_family_code'] : null,
            'material_family_name' => $row['material_family_name'] !== null ? (string) $row['material_family_name'] : null,
            'material_origin_id' => $row['material_origin_id'] !== null ? (int) $row['material_origin_id'] : null,
            'material_origin_code' => $row['material_origin_code'] !== null ? (string) $row['material_origin_code'] : null,
            'material_origin_name' => $row['material_origin_name'] !== null ? (string) $row['material_origin_name'] : null,
            'definition' => [
                'code' => (string) $row['definition_code'],
                'name' => (string) $row['definition_name'],
                'tradeable' => (bool) $row['tradeable'],
                'is_container' => (bool) $row['is_container'],
                'is_collectible' => (bool) $row['is_collectible'],
                'is_event_item' => (bool) $row['is_event_item'],
                'equip_slot_code' => $row['equip_slot_code'] !== null ? (string) $row['equip_slot_code'] : null,
                'category_code' => (string) $row['category_code'],
                'grid_w' => (int) $row['grid_w'],
                'grid_h' => (int) $row['grid_h'],
            ],
            'quantity' => (int) $row['quantity'],
            'quality_value' => $row['quality_value'] !== null ? (float) $row['quality_value'] : null,
            'quality_bucket' => $row['quality_bucket'] !== null ? (string) $row['quality_bucket'] : null,
            'item_name' => $row['item_name'] !== null ? (string) $row['item_name'] : null,
            'bind_type' => (string) $row['bind_type'],
            'state' => (string) $row['state'],
            'properties' => $this->properties((int) $row['item_instance_id']),
            'affixes' => $this->affixes((int) $row['item_instance_id']),
            'sockets' => $this->sockets((int) $row['item_instance_id']),
        ];

        $quote = (new MarketPriceService($this->pdo()))->quote($mapped);
        $mapped['market_value'] = (int) $quote['market_value'];
        $mapped['npc_value'] = (int) $quote['npc_value'];
        $mapped['suggested_premium'] = (int) ($quote['suggested_premium'] ?? 0);
        $mapped['listing_price_min'] = (int) ($quote['listing_price_min'] ?? 1);
        $mapped['listing_price_max'] = (int) ($quote['listing_price_max'] ?? 1);

        return $mapped;
    }

    private function mapFromInventoryState(int $playerId, string $itemPublicId): ?array
    {
        $state = (new InventoryStateService($this->pdo()))->forPlayer($playerId);

        foreach ($state['containers'] ?? [] as $container) {
            foreach ($container['items'] ?? [] as $item) {
                if ((string) ($item['public_id'] ?? '') === $itemPublicId) {
                    return $item;
                }
            }
        }

        foreach ($state['equipment'] ?? [] as $slot) {
            if (!empty($slot['item']) && (string) $slot['item']['public_id'] === $itemPublicId) {
                return $slot['item'];
            }
        }

        return null;
    }

    private function properties(int $itemInstanceId): array
    {
        $stmt = $this->pdo()->prepare('SELECT ipd.code, ipd.name, ipd.unit, iip.numeric_value, iip.integer_value, iip.text_value, iip.source
            FROM item_instance_properties iip
            INNER JOIN item_property_definitions ipd ON ipd.id = iip.property_definition_id
            WHERE iip.item_instance_id = :item_instance_id');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return array_map(fn (array $row): array => [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'unit' => $row['unit'] !== null ? (string) $row['unit'] : null,
            'value' => $row['integer_value'] !== null
                ? (int) $row['integer_value']
                : ($row['numeric_value'] !== null ? (float) $row['numeric_value'] : ($row['text_value'] !== null ? (string) $row['text_value'] : null)),
            'source' => (string) $row['source'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function affixes(int $itemInstanceId): array
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
                iia.source,
                iad.rarity_weight
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
            'rarity_weight' => (float) ($row['rarity_weight'] ?? 1),
            'source' => (string) $row['source'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function sockets(int $itemInstanceId): array
    {
        if (!$this->tableExists('item_instance_sockets')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT
                iis.id,
                iis.socket_index,
                iis.socket_type,
                iis.status,
                gem.id AS gem_item_instance_id,
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
            'socket_index' => (int) $row['socket_index'],
            'index' => (int) $row['socket_index'],
            'type' => (string) $row['socket_type'],
            'status' => (string) $row['status'],
            'gem_item_instance_id' => $row['gem_item_instance_id'] !== null ? (int) $row['gem_item_instance_id'] : null,
            'gem' => $row['gem_public_id'] !== null ? [
                'public_id' => (string) $row['gem_public_id'],
                'definition_code' => (string) $row['gem_definition_code'],
                'name' => (string) $row['gem_name'],
            ] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return $stmt->fetchColumn() !== false;
        }

        return $this->pdo()->query('SHOW TABLES LIKE ' . $this->pdo()->quote($table))->fetchColumn() !== false;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
