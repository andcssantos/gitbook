<?php

use App\Game\Containers\Services\PhysicalContainerLinkService;
use App\Game\Items\Repositories\ItemInstanceRepository;

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

    $upsertContainerDefinition('pouch_bag_2x2', 'Small Pouch', 'BACKPACK', 2, 2, 0);
    $upsertContainerDefinition('wooden_chest', 'Wooden Chest', 'CHEST', 10, 8, 1);

    $upsertDefinition('wooden_storage_chest', [
        'name' => 'Bau de Madeira Grande',
        'description' => 'Bau fisico 10x8 para testes de organizacao.',
        'category_id' => $categoryId('container'),
        'material_family_id' => $familyId('wood'),
        'stackable' => 0,
        'max_stack' => 1,
        'grid_w' => 2,
        'grid_h' => 2,
        'equip_slot_code' => null,
        'is_container' => 1,
        'tradeable' => 1,
        'base_config' => json_encode(['container_definition' => 'wooden_chest'], JSON_THROW_ON_ERROR),
        'status' => 'active',
    ]);

    $upsertDefinition('small_pouch_bag', [
        'name' => 'Bag Menor 2x2',
        'description' => 'Bag pequena para aninhar dentro de baus.',
        'category_id' => $categoryId('container'),
        'material_family_id' => $familyId('leather'),
        'stackable' => 0,
        'max_stack' => 1,
        'grid_w' => 1,
        'grid_h' => 1,
        'equip_slot_code' => null,
        'is_container' => 1,
        'tradeable' => 1,
        'base_config' => json_encode(['container_definition' => 'pouch_bag_2x2'], JSON_THROW_ON_ERROR),
        'status' => 'active',
    ]);

    foreach ([
        ['jewel_blessing_minor', 'Joia da Bencao Menor', 'magic', 62.0, 85],
        ['jewel_soul_minor', 'Joia da Alma Menor', 'rare', 72.0, 62],
        ['jewel_chaos_minor', 'Joia do Caos Menor', 'epic', 84.0, 38],
        ['jewel_reroll_minor', 'Joia de Rerrolagem Menor', 'rare', 70.0, 55],
        ['gem_ruby_attack', 'Rubi Marcial', 'rare', 75.0, null],
        ['gem_emerald_vitality', 'Esmeralda Vital', 'rare', 76.0, null],
        ['gem_sapphire_guard', 'Safira Guardia', 'magic', 64.0, null],
    ] as [$code, $name, $bucket, $quality, $upgradeRate]) {
        $upsertDefinition($code, [
            'name' => $name,
            'description' => "{$name} para testes de organizacao.",
            'category_id' => $categoryId('material'),
            'material_family_id' => $familyId('essence'),
            'stackable' => 0,
            'max_stack' => 1,
            'grid_w' => 1,
            'grid_h' => 1,
            'equip_slot_code' => null,
            'is_container' => 0,
            'tradeable' => 1,
            'base_config' => json_encode(array_filter([
                'enhancement_type' => str_starts_with($code, 'jewel_') ? 'upgrade_jewel' : null,
                'upgrade_success_rate' => $upgradeRate,
                'gem_effect' => match ($code) {
                    'gem_ruby_attack' => ['property' => 'attack_power', 'value' => 7],
                    'gem_emerald_vitality' => ['property' => 'max_health', 'value' => 22],
                    'gem_sapphire_guard' => ['property' => 'armor', 'value' => 9],
                    default => null,
                },
            ], fn ($value): bool => $value !== null), JSON_THROW_ON_ERROR),
            'status' => 'active',
        ]);
    }

    $items = new ItemInstanceRepository($pdo);
    $linker = new PhysicalContainerLinkService($pdo);

    $upsertPlacedItem = function (
        string $definitionCode,
        string $publicId,
        int $containerId,
        int $gridX,
        int $gridY,
        int $gridW,
        int $gridH,
        string $qualityBucket = 'magic',
        float $qualityValue = 60.0
    ) use ($pdo, $items, $playerId, $upsertDefinition, $categoryId, $familyId): array {
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

        $equipped = $pdo->prepare('SELECT item_instance_id FROM player_equipment WHERE item_instance_id = :item_instance_id LIMIT 1');
        $equipped->execute(['item_instance_id' => $itemId]);
        if ($equipped->fetchColumn() === false) {
            $pdo->prepare('INSERT INTO container_items (container_instance_id, item_instance_id, grid_x, grid_y, grid_w, grid_h, rotated, locked) VALUES (:container_instance_id, :item_instance_id, :grid_x, :grid_y, :grid_w, :grid_h, 0, 0)')
                ->execute([
                    'container_instance_id' => $containerId,
                    'item_instance_id' => $itemId,
                    'grid_x' => $gridX,
                    'grid_y' => $gridY,
                    'grid_w' => $gridW,
                    'grid_h' => $gridH,
                ]);
        }

        $item = $items->findByPublicIdAndOwner($publicId, $playerId, true);
        if ($item === null) {
            throw new RuntimeException("Item instance not found after upsert: {$publicId}");
        }

        return $item;
    };

    $jewelPlacements = [
        ['jewel_blessing_minor', '00000000-0000-4004-8000-000000000001', 2, 0, 'magic', 62.0],
        ['jewel_blessing_minor', '00000000-0000-4004-8000-000000000002', 3, 0, 'magic', 62.0],
        ['jewel_blessing_minor', '00000000-0000-4004-8000-000000000003', 4, 0, 'magic', 62.0],
        ['jewel_soul_minor', '00000000-0000-4004-8000-000000000004', 5, 0, 'rare', 72.0],
        ['jewel_soul_minor', '00000000-0000-4004-8000-000000000005', 6, 0, 'rare', 72.0],
        ['jewel_soul_minor', '00000000-0000-4004-8000-000000000006', 7, 0, 'rare', 72.0],
        ['jewel_chaos_minor', '00000000-0000-4004-8000-000000000007', 8, 0, 'epic', 84.0],
        ['jewel_chaos_minor', '00000000-0000-4004-8000-000000000008', 9, 0, 'epic', 84.0],
        ['jewel_chaos_minor', '00000000-0000-4004-8000-000000000009', 10, 0, 'epic', 84.0],
        ['jewel_reroll_minor', '00000000-0000-4004-8000-00000000000a', 11, 0, 'rare', 70.0],
        ['jewel_reroll_minor', '00000000-0000-4004-8000-00000000000b', 2, 1, 'rare', 70.0],
        ['jewel_reroll_minor', '00000000-0000-4004-8000-00000000000c', 3, 1, 'rare', 70.0],
        ['gem_ruby_attack', '00000000-0000-4004-8000-00000000000d', 4, 1, 'rare', 75.0],
        ['gem_ruby_attack', '00000000-0000-4004-8000-00000000000e', 5, 1, 'rare', 75.0],
        ['gem_emerald_vitality', '00000000-0000-4004-8000-00000000000f', 6, 1, 'rare', 76.0],
        ['gem_emerald_vitality', '00000000-0000-4004-8000-000000000010', 7, 1, 'rare', 76.0],
        ['gem_sapphire_guard', '00000000-0000-4004-8000-000000000011', 8, 1, 'magic', 64.0],
        ['gem_sapphire_guard', '00000000-0000-4004-8000-000000000012', 9, 1, 'magic', 64.0],
    ];

    foreach ($jewelPlacements as [$code, $publicId, $x, $y, $bucket, $quality]) {
        $upsertPlacedItem($code, $publicId, $mainContainerId, $x, $y, 1, 1, $bucket, $quality);
    }

    $chestItem = $upsertPlacedItem('wooden_storage_chest', '00000000-0000-4004-8000-000000000020', $mainContainerId, 0, 2, 2, 2, 'rare', 68.0);
    $linker->ensureForItem($playerId, $chestItem, 90);
    $chestContainerId = (int) $pdo->query(
        'SELECT id FROM container_instances WHERE source_item_instance_id = ' . (int) $chestItem['id'] . " AND status = 'active' LIMIT 1"
    )->fetchColumn();

    if ($chestContainerId > 0) {
        $pouchItem = $upsertPlacedItem('small_pouch_bag', '00000000-0000-4004-8000-000000000021', $chestContainerId, 0, 0, 1, 1, 'common', 42.0);
        $linker->ensureForItem($playerId, $pouchItem, 95);
    }
};
