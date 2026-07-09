<?php

return function (PDO $pdo): void {
    $upsert = function (string $table, string $code, array $data) use ($pdo): int {
        $existing = $pdo->prepare("SELECT id FROM {$table} WHERE code = :code LIMIT 1");
        $existing->execute(['code' => $code]);
        $id = $existing->fetchColumn();

        $data = array_merge(['code' => $code], $data);

        if ($id) {
            $sets = [];
            $params = ['id' => $id];
            foreach ($data as $column => $value) {
                if ($column === 'code') {
                    continue;
                }
                $sets[] = "{$column} = :{$column}";
                $params[$column] = $value;
            }

            if ($sets !== []) {
                $stmt = $pdo->prepare("UPDATE {$table} SET " . implode(', ', $sets) . " WHERE id = :id");
                $stmt->execute($params);
            }

            return (int) $id;
        }

        $columns = array_keys($data);
        $placeholders = array_map(fn (string $column): string => ':' . $column, $columns);
        $stmt = $pdo->prepare("INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
        $stmt->execute($data);

        return (int) $pdo->lastInsertId();
    };

    foreach ([
        'material' => 'Material',
        'weapon' => 'Weapon',
        'armor' => 'Armor',
        'tool' => 'Tool',
        'consumable' => 'Consumable',
        'container' => 'Container',
        'currency' => 'Currency',
    ] as $code => $name) {
        $upsert('item_categories', $code, [
            'name' => $name,
            'description' => "{$name} item category.",
            'status' => 'active',
        ]);
    }

    foreach ([
        'wood' => 'Wood',
        'metal' => 'Metal',
        'leather' => 'Leather',
        'stone' => 'Stone',
        'herb' => 'Herb',
        'essence' => 'Essence',
        'currency_metal' => 'Currency Metal',
    ] as $code => $name) {
        $upsert('material_families', $code, [
            'name' => $name,
            'description' => "{$name} material family.",
            'status' => 'active',
        ]);
    }

    foreach ([
        'starter_forest' => 'Starter Forest',
        'rocky_field' => 'Rocky Field',
        'abandoned_mine' => 'Abandoned Mine',
    ] as $code => $name) {
        $upsert('material_origins', $code, [
            'name' => $name,
            'description' => "{$name} material origin.",
            'status' => 'active',
        ]);
    }

    $slotOrder = 10;
    foreach ([
        'weapon' => 'Weapon',
        'helmet' => 'Helmet',
        'chest' => 'Chest',
        'gloves' => 'Gloves',
        'pants' => 'Pants',
        'boots' => 'Boots',
        'ring' => 'Ring',
        'backpack' => 'Backpack',
    ] as $code => $name) {
        $upsert('equipment_slots', $code, [
            'name' => $name,
            'sort_order' => $slotOrder,
            'status' => 'active',
        ]);
        $slotOrder += 10;
    }

    foreach ([
        'main_inventory_level_1' => ['Main Inventory Level 1', 'MAIN_INVENTORY', 8, 5, 0],
        'small_backpack' => ['Small Backpack', 'BACKPACK', 4, 4, 0],
        'medium_backpack' => ['Medium Backpack', 'BACKPACK', 6, 5, 0],
        'wooden_chest' => ['Wooden Chest', 'CHEST', 10, 8, 0],
        'expedition_carry' => ['Expedition Carry', 'EXPEDITION_CARRY', 8, 5, 0],
        'market_escrow' => ['Market Escrow', 'MARKET_ESCROW', 10, 10, 0],
        'market_delivery' => ['Market Delivery', 'MARKET_DELIVERY', 10, 10, 0],
    ] as $code => [$name, $type, $columns, $rows, $allowContainerItems]) {
        $upsert('container_definitions', $code, [
            'name' => $name,
            'container_type' => $type,
            'grid_columns' => $columns,
            'grid_rows' => $rows,
            'allow_container_items' => $allowContainerItems,
            'status' => 'active',
        ]);
    }

    $containerDefinitionId = fn (string $code): int => (int) $pdo->query("SELECT id FROM container_definitions WHERE code = " . $pdo->quote($code))->fetchColumn();
    $upsertAcceptanceRule = function (string $containerCode, string $ruleType, string $referenceCode, bool $allow, int $priority) use ($pdo, $containerDefinitionId): void {
        $definitionId = $containerDefinitionId($containerCode);
        $referenceCode = trim($referenceCode);

        $existing = $pdo->prepare('SELECT id FROM container_acceptance_rules WHERE container_definition_id = :container_definition_id AND rule_type = :rule_type AND reference_code = :reference_code LIMIT 1');
        $existing->execute([
            'container_definition_id' => $definitionId,
            'rule_type' => $ruleType,
            'reference_code' => $referenceCode,
        ]);
        $id = $existing->fetchColumn();

        if ($id) {
            $stmt = $pdo->prepare('UPDATE container_acceptance_rules SET allow = :allow, priority = :priority WHERE id = :id');
            $stmt->execute([
                'id' => $id,
                'allow' => $allow ? 1 : 0,
                'priority' => $priority,
            ]);
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO container_acceptance_rules (container_definition_id, rule_type, reference_code, allow, priority) VALUES (:container_definition_id, :rule_type, :reference_code, :allow, :priority)');
        $stmt->execute([
            'container_definition_id' => $definitionId,
            'rule_type' => $ruleType,
            'reference_code' => $referenceCode,
            'allow' => $allow ? 1 : 0,
            'priority' => $priority,
        ]);
    };

    foreach (['small_backpack', 'medium_backpack', 'wooden_chest', 'market_delivery', 'market_escrow'] as $containerCode) {
        $upsertAcceptanceRule($containerCode, 'CONTAINER_BLOCK', '', false, 10);
        $upsertAcceptanceRule($containerCode, 'ACCEPT_ALL', '', true, 100);
    }

    $upsertAcceptanceRule('main_inventory_level_1', 'ACCEPT_ALL', '', true, 100);

    $upsertAcceptanceRule('expedition_carry', 'CONTAINER_BLOCK', '', false, 10);
    foreach (['material', 'currency', 'tool'] as $categoryCode) {
        $upsertAcceptanceRule('expedition_carry', 'ITEM_CATEGORY', $categoryCode, true, 100);
    }

    $categoryId = fn (string $code): int => (int) $pdo->query("SELECT id FROM item_categories WHERE code = " . $pdo->quote($code))->fetchColumn();
    $familyId = fn (string $code): int => (int) $pdo->query("SELECT id FROM material_families WHERE code = " . $pdo->quote($code))->fetchColumn();

    foreach ([
        'wood' => [
            'Wood',
            'Material gathered from trees.',
            'material',
            'wood',
            1,
            100,
            1,
            2,
            null,
            0,
            1,
            null,
        ],
        'stone' => [
            'Stone',
            'Basic stone material.',
            'material',
            'stone',
            1,
            100,
            1,
            1,
            null,
            0,
            1,
            null,
        ],
        'iron_ingot' => [
            'Iron Ingot',
            'Processed metal ingot.',
            'material',
            'metal',
            1,
            100,
            1,
            1,
            null,
            0,
            1,
            null,
        ],
        'gold_coin' => [
            'Gold Coin',
            'Integer currency unit represented as an item definition for UI and loot references.',
            'currency',
            'currency_metal',
            1,
            1000000,
            1,
            1,
            null,
            0,
            1,
            null,
        ],
        'iron_sword' => [
            'Iron Sword',
            'Basic one-handed metal sword definition.',
            'weapon',
            'metal',
            0,
            1,
            1,
            3,
            'weapon',
            0,
            1,
            json_encode(['durability' => 100], JSON_THROW_ON_ERROR),
        ],
        'stone_pickaxe' => [
            'Stone Pickaxe',
            'Basic mining tool definition.',
            'tool',
            'stone',
            0,
            1,
            2,
            3,
            'weapon',
            0,
            1,
            json_encode(['tool_family' => 'pickaxe', 'durability' => 80], JSON_THROW_ON_ERROR),
        ],
        'small_leather_backpack' => [
            'Small Leather Backpack',
            'Physical backpack item that can expose a small internal container.',
            'container',
            'leather',
            0,
            1,
            2,
            2,
            'backpack',
            1,
            1,
            json_encode(['container_definition' => 'small_backpack'], JSON_THROW_ON_ERROR),
        ],
    ] as $code => [$name, $description, $category, $family, $stackable, $maxStack, $gridW, $gridH, $slot, $isContainer, $tradeable, $baseConfig]) {
        $upsert('item_definitions', $code, [
            'name' => $name,
            'description' => $description,
            'category_id' => $categoryId($category),
            'material_family_id' => $familyId($family),
            'stackable' => $stackable,
            'max_stack' => $maxStack,
            'grid_w' => $gridW,
            'grid_h' => $gridH,
            'equip_slot_code' => $slot,
            'is_container' => $isContainer,
            'tradeable' => $tradeable,
            'base_config' => $baseConfig,
            'status' => 'active',
        ]);
    }
};
