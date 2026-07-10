<?php

use App\Game\Containers\Services\PhysicalContainerLinkService;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Services\ItemStatRangeService;

return function (PDO $pdo): void {
    $account = $pdo->prepare('SELECT id FROM accounts WHERE email = :email LIMIT 1');
    $account->execute(['email' => 'local@evolvaxe.test']);
    $accountId = $account->fetchColumn();
    if (!$accountId) {
        return;
    }

    $player = $pdo->prepare('SELECT id FROM players WHERE account_id = :account_id AND name = :name LIMIT 1');
    $player->execute([
        'account_id' => $accountId,
        'name' => 'LocalHero',
    ]);
    $playerId = (int) $player->fetchColumn();
    if ($playerId <= 0) {
        return;
    }

    (new \App\Game\Inventory\Services\StarterInventoryService($pdo))->ensureForPlayer($playerId, false);

    $mainContainerId = (int) $pdo->query(
        "SELECT ci.id FROM container_instances ci
         INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id
         WHERE ci.owner_player_id = {$playerId} AND cd.code = 'main_inventory_level_1' AND ci.status = 'active'
         LIMIT 1"
    )->fetchColumn();
    if ($mainContainerId <= 0) {
        return;
    }

    $categoryId = fn (string $code): int => (int) $pdo->query('SELECT id FROM item_categories WHERE code = ' . $pdo->quote($code) . ' LIMIT 1')->fetchColumn();
    $familyId = fn (string $code): int => (int) $pdo->query('SELECT id FROM material_families WHERE code = ' . $pdo->quote($code) . ' LIMIT 1')->fetchColumn();
    $propertyId = fn (string $code): int => (int) $pdo->query('SELECT id FROM item_property_definitions WHERE code = ' . $pdo->quote($code) . ' LIMIT 1')->fetchColumn();

    $upsertContainerDefinition = function (string $code, string $name, string $type, int $columns, int $rows, int $allowNested) use ($pdo): int {
        $existing = $pdo->prepare('SELECT id FROM container_definitions WHERE code = :code LIMIT 1');
        $existing->execute(['code' => $code]);
        $id = $existing->fetchColumn();

        $payload = [
            'name' => $name,
            'container_type' => $type,
            'grid_columns' => $columns,
            'grid_rows' => $rows,
            'allow_container_items' => $allowNested,
            'status' => 'active',
        ];

        if ($id) {
            $stmt = $pdo->prepare('UPDATE container_definitions SET name = :name, container_type = :container_type, grid_columns = :grid_columns, grid_rows = :grid_rows, allow_container_items = :allow_container_items, status = :status WHERE id = :id');
            $stmt->execute($payload + ['id' => $id]);

            return (int) $id;
        }

        $stmt = $pdo->prepare('INSERT INTO container_definitions (code, name, container_type, grid_columns, grid_rows, allow_container_items, status) VALUES (:code, :name, :container_type, :grid_columns, :grid_rows, :allow_container_items, :status)');
        $stmt->execute(['code' => $code] + $payload);

        return (int) $pdo->lastInsertId();
    };

    $upsertDefinition = function (string $code, array $data) use ($pdo): int {
        $existing = $pdo->prepare('SELECT id FROM item_definitions WHERE code = :code LIMIT 1');
        $existing->execute(['code' => $code]);
        $id = $existing->fetchColumn();

        if ($id) {
            $stmt = $pdo->prepare('UPDATE item_definitions SET
                name = :name,
                description = :description,
                category_id = :category_id,
                material_family_id = :material_family_id,
                stackable = :stackable,
                max_stack = :max_stack,
                grid_w = :grid_w,
                grid_h = :grid_h,
                equip_slot_code = :equip_slot_code,
                is_container = :is_container,
                tradeable = :tradeable,
                base_config = :base_config,
                status = :status
                WHERE id = :id');
            $stmt->execute($data + ['id' => $id]);

            return (int) $id;
        }

        $stmt = $pdo->prepare('INSERT INTO item_definitions (
            code, name, description, category_id, material_family_id, stackable, max_stack, grid_w, grid_h,
            equip_slot_code, is_container, tradeable, base_config, status
        ) VALUES (
            :code, :name, :description, :category_id, :material_family_id, :stackable, :max_stack, :grid_w, :grid_h,
            :equip_slot_code, :is_container, :tradeable, :base_config, :status
        )');
        $stmt->execute($data + ['code' => $code]);

        return (int) $pdo->lastInsertId();
    };

    $upsertContainerDefinition('adventure_bag_4x4', 'Adventure Bag', 'BACKPACK', 4, 4, 0);
    $upsertContainerDefinition('iron_storage_chest', 'Iron Chest', 'CHEST', 8, 6, 1);

    $definitions = [
        'epic_travel_bag' => [
            'name' => 'Mochila de Aventureiro 4x4',
            'description' => 'Bag maior para testes de capacidade aninhada.',
            'category_id' => $categoryId('container'),
            'material_family_id' => $familyId('leather'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 2,
            'grid_h' => 2,
            'equip_slot_code' => null,
            'is_container' => 1,
            'tradeable' => 1,
            'base_config' => json_encode(['container_definition' => 'adventure_bag_4x4'], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ],
        'epic_iron_chest' => [
            'name' => 'Bau de Ferro 8x6',
            'description' => 'Segundo bau fisico para testes de organizacao.',
            'category_id' => $categoryId('container'),
            'material_family_id' => $familyId('metal'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 2,
            'grid_h' => 2,
            'equip_slot_code' => null,
            'is_container' => 1,
            'tradeable' => 1,
            'base_config' => json_encode(['container_definition' => 'iron_storage_chest'], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ],
        'epic_test_pet_wolf' => [
            'name' => 'Lobo Filhote',
            'description' => 'Pet incomum para testes de slot pet.',
            'category_id' => $categoryId('armor'),
            'material_family_id' => $familyId('leather'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 1,
            'grid_h' => 1,
            'equip_slot_code' => 'pet',
            'is_container' => 0,
            'tradeable' => 1,
            'base_config' => json_encode(['showcase' => true], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ],
        'epic_common_dagger' => [
            'name' => 'Adaga Enferrujada',
            'description' => 'Arma comum para comparacao de raridade.',
            'category_id' => $categoryId('weapon'),
            'material_family_id' => $familyId('metal'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 1,
            'grid_h' => 2,
            'equip_slot_code' => 'weapon',
            'is_container' => 0,
            'tradeable' => 1,
            'base_config' => json_encode(['showcase' => true], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ],
        'epic_uncommon_mace' => [
            'name' => 'Clava Reforçada',
            'description' => 'Arma incomum para testes de bless.',
            'category_id' => $categoryId('weapon'),
            'material_family_id' => $familyId('wood'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 1,
            'grid_h' => 3,
            'equip_slot_code' => 'weapon',
            'is_container' => 0,
            'tradeable' => 1,
            'base_config' => json_encode(['showcase' => true], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ],
        'epic_rare_spear' => [
            'name' => 'Lanca Flamejante',
            'description' => 'Arma rara vertical para testes de grid.',
            'category_id' => $categoryId('weapon'),
            'material_family_id' => $familyId('metal'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 1,
            'grid_h' => 4,
            'equip_slot_code' => 'weapon',
            'is_container' => 0,
            'tradeable' => 1,
            'base_config' => json_encode(['showcase' => true], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ],
        'epic_common_cloth_hood' => [
            'name' => 'Capuz Simples',
            'description' => 'Elmo comum leve.',
            'category_id' => $categoryId('armor'),
            'material_family_id' => $familyId('leather'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 2,
            'grid_h' => 2,
            'equip_slot_code' => 'helmet',
            'is_container' => 0,
            'tradeable' => 1,
            'base_config' => json_encode(['showcase' => true], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ],
        'epic_uncommon_chain_vest' => [
            'name' => 'Colete de Malha',
            'description' => 'Peitoral incomum.',
            'category_id' => $categoryId('armor'),
            'material_family_id' => $familyId('metal'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 2,
            'grid_h' => 3,
            'equip_slot_code' => 'chest',
            'is_container' => 0,
            'tradeable' => 1,
            'base_config' => json_encode(['showcase' => true], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ],
        'gem_topaz_agility' => [
            'name' => 'Topazio Veloz',
            'description' => 'Gema que favorece agilidade.',
            'category_id' => $categoryId('material'),
            'material_family_id' => $familyId('essence'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 1,
            'grid_h' => 1,
            'equip_slot_code' => null,
            'is_container' => 0,
            'tradeable' => 1,
            'base_config' => json_encode(['gem_effect' => ['property' => 'agility', 'value' => 6]], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ],
        'gem_amethyst_strength' => [
            'name' => 'Ametista Poderosa',
            'description' => 'Gema que favorece forca.',
            'category_id' => $categoryId('material'),
            'material_family_id' => $familyId('essence'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 1,
            'grid_h' => 1,
            'equip_slot_code' => null,
            'is_container' => 0,
            'tradeable' => 1,
            'base_config' => json_encode(['gem_effect' => ['property' => 'strength', 'value' => 8]], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ],
        'gem_onyx_defense' => [
            'name' => 'Onix Guardiao',
            'description' => 'Gema que favorece defesa.',
            'category_id' => $categoryId('material'),
            'material_family_id' => $familyId('essence'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 1,
            'grid_h' => 1,
            'equip_slot_code' => null,
            'is_container' => 0,
            'tradeable' => 1,
            'base_config' => json_encode(['gem_effect' => ['property' => 'defense', 'value' => 7]], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ],
    ];

    foreach ($definitions as $code => $data) {
        $upsertDefinition($code, $data);
    }

    $items = new ItemInstanceRepository($pdo);
    $linker = new PhysicalContainerLinkService($pdo);
    $statRanges = new ItemStatRangeService();

    $upsertPlacedItem = function (
        string $definitionCode,
        string $publicId,
        int $containerId,
        int $gridX,
        int $gridY,
        int $gridW,
        int $gridH,
        string $qualityBucket = 'common',
        float $qualityValue = 42.0,
        int $upgradeLevel = 0,
        array $extraProperties = []
    ) use ($pdo, $items, $playerId, $propertyId, $statRanges): array {
        $definition = $pdo->prepare('SELECT * FROM item_definitions WHERE code = :code LIMIT 1');
        $definition->execute(['code' => $definitionCode]);
        $definitionRow = $definition->fetch(PDO::FETCH_ASSOC);
        if (!$definitionRow) {
            throw new RuntimeException("Item definition not found: {$definitionCode}");
        }

        $existing = $pdo->prepare('SELECT id FROM item_instances WHERE public_id = :public_id LIMIT 1');
        $existing->execute(['public_id' => $publicId]);
        $itemId = $existing->fetchColumn();

        if ($itemId) {
            $pdo->prepare('UPDATE item_instances SET item_definition_id = :item_definition_id, owner_player_id = :owner_player_id, quantity = 1, quality_value = :quality_value, quality_bucket = :quality_bucket, state = :state WHERE id = :id')
                ->execute([
                    'item_definition_id' => (int) $definitionRow['id'],
                    'owner_player_id' => $playerId,
                    'quality_value' => $qualityValue,
                    'quality_bucket' => $qualityBucket,
                    'state' => 'available',
                    'id' => $itemId,
                ]);
            $itemId = (int) $itemId;
        } else {
            $itemId = $items->create([
                'public_id' => $publicId,
                'item_definition_id' => (int) $definitionRow['id'],
                'owner_player_id' => $playerId,
                'quantity' => 1,
                'quality_value' => $qualityValue,
                'quality_bucket' => $qualityBucket,
                'item_name' => (string) $definitionRow['name'],
            ]);
        }

        $pdo->prepare('DELETE FROM container_items WHERE item_instance_id = :item_instance_id')->execute([
            'item_instance_id' => $itemId,
        ]);

        $pdo->prepare('INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h, rotated, locked) VALUES (:container_instance_id, :item_instance_id, :grid_x, :grid_y, :grid_w, :grid_h, 0, 0)')
            ->execute([
                'container_instance_id' => $containerId,
                'item_instance_id' => $itemId,
                'grid_x' => $gridX,
                'grid_y' => $gridY,
                'grid_w' => $gridW,
                'grid_h' => $gridH,
            ]);

        $pdo->prepare('DELETE FROM item_instance_properties WHERE item_instance_id = :item_instance_id')->execute([
            'item_instance_id' => $itemId,
        ]);

        $itemPayload = [
            'quality_value' => $qualityValue,
            'quality_bucket' => $qualityBucket,
            'properties' => [],
        ];

        if ($upgradeLevel > 0) {
            $pdo->prepare('INSERT INTO item_instance_properties (item_instance_id, property_definition_id, numeric_value, integer_value, text_value, source) VALUES (:item_instance_id, :property_definition_id, NULL, :integer_value, NULL, :source)')
                ->execute([
                    'item_instance_id' => $itemId,
                    'property_definition_id' => $propertyId('upgrade_level'),
                    'integer_value' => $upgradeLevel,
                    'source' => 'upgrade',
                ]);
            $itemPayload['properties'][] = ['code' => 'upgrade_level', 'value' => $upgradeLevel];
        }

        foreach ($extraProperties as [$code, $value]) {
            $itemPayload['properties'][] = ['code' => $code, 'value' => $value];
            $range = $statRanges->rangeForItem($itemPayload, $code);
            $clamped = max($range['min'], min($range['max'], (int) $value));
            $pdo->prepare('INSERT INTO item_instance_properties (item_instance_id, property_definition_id, numeric_value, integer_value, text_value, source) VALUES (:item_instance_id, :property_definition_id, NULL, :integer_value, NULL, :source)')
                ->execute([
                    'item_instance_id' => $itemId,
                    'property_definition_id' => $propertyId($code),
                    'integer_value' => $clamped,
                    'source' => 'base',
                ]);
        }

        $item = $items->findByPublicIdAndOwner($publicId, $playerId, true);
        if ($item === null) {
            throw new RuntimeException("Item instance not found after upsert: {$publicId}");
        }

        return $item;
    };

    $placements = [
        ['epic_common_dagger', '00000000-0000-4005-8000-000000000001', 0, 3, 1, 2, 'common', 40.0, 0, [['strength', 7], ['agility', 5]]],
        ['epic_uncommon_mace', '00000000-0000-4005-8000-000000000002', 1, 3, 1, 3, 'uncommon', 48.0, 3, [['strength', 11], ['attack_power', 9]]],
        ['epic_rare_spear', '00000000-0000-4005-8000-000000000003', 2, 3, 1, 4, 'rare', 68.0, 7, [['strength', 18], ['agility', 14]]],
        ['epic_common_cloth_hood', '00000000-0000-4005-8000-000000000004', 4, 3, 2, 2, 'common', 41.0, 0, [['armor', 8], ['energy', 6]]],
        ['epic_uncommon_chain_vest', '00000000-0000-4005-8000-000000000005', 6, 3, 2, 3, 'uncommon', 50.0, 2, [['armor', 14], ['defense', 10]]],
        ['epic_test_pet_wolf', '00000000-0000-4005-8000-000000000006', 8, 3, 1, 1, 'uncommon', 47.0, 0, [['vitality', 9]]],
        ['gem_topaz_agility', '00000000-0000-4005-8000-000000000007', 9, 3, 1, 1, 'magic', 64.0, 0, []],
        ['gem_amethyst_strength', '00000000-0000-4005-8000-000000000008', 10, 3, 1, 1, 'rare', 72.0, 0, []],
        ['gem_onyx_defense', '00000000-0000-4005-8000-000000000009', 11, 3, 1, 1, 'rare', 73.0, 0, []],
        ['epic_travel_bag', '00000000-0000-4005-8000-000000000010', 0, 4, 2, 2, 'uncommon', 46.0, 0, []],
        ['epic_iron_chest', '00000000-0000-4005-8000-000000000011', 2, 4, 2, 2, 'rare', 66.0, 0, []],
    ];

    foreach ($placements as [$code, $publicId, $x, $y, $w, $h, $bucket, $quality, $upgrade, $props]) {
        $item = $upsertPlacedItem($code, $publicId, $mainContainerId, $x, $y, $w, $h, $bucket, $quality, $upgrade, $props);
        if (in_array($code, ['epic_travel_bag', 'epic_iron_chest'], true)) {
            $linker->ensureForItem($playerId, $item, $code === 'epic_travel_bag' ? 120 : 130);
        }
    }

    $emberAxe = $pdo->prepare('SELECT ii.id FROM item_instances ii INNER JOIN item_definitions id ON id.id = ii.item_definition_id WHERE ii.owner_player_id = :player_id AND id.code = :code LIMIT 1');
    $emberAxe->execute(['player_id' => $playerId, 'code' => 'showcase_ember_axe']);
    $emberAxeId = (int) $emberAxe->fetchColumn();
    if ($emberAxeId > 0) {
        $pdo->prepare('DELETE FROM item_instance_properties WHERE item_instance_id = :item_instance_id AND property_definition_id IN (SELECT id FROM item_property_definitions WHERE code IN (\'strength\', \'agility\'))')
            ->execute(['item_instance_id' => $emberAxeId]);

        $itemPayload = [
            'quality_value' => 74.0,
            'quality_bucket' => 'rare',
            'properties' => [['code' => 'upgrade_level', 'value' => 5]],
        ];
        foreach ([
            ['strength', $statRanges->allowedCapAtUpgradeLevel($itemPayload, 'strength', 5)],
            ['agility', $statRanges->allowedCapAtUpgradeLevel($itemPayload, 'agility', 5)],
        ] as [$code, $value]) {
            $pdo->prepare('INSERT INTO item_instance_properties (item_instance_id, property_definition_id, numeric_value, integer_value, text_value, source) VALUES (:item_instance_id, :property_definition_id, NULL, :integer_value, NULL, :source)')
                ->execute([
                    'item_instance_id' => $emberAxeId,
                    'property_definition_id' => $propertyId($code),
                    'integer_value' => $value,
                    'source' => 'base',
                ]);
        }
    }
};
