<?php

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
    $playerId = $player->fetchColumn();
    if (!$playerId) {
        return;
    }

    (new \App\Game\Inventory\Services\StarterInventoryService($pdo))->ensureForPlayer((int) $playerId, false);

    $categoryId = function (string $code) use ($pdo): int {
        return (int) $pdo->query('SELECT id FROM item_categories WHERE code = ' . $pdo->quote($code) . ' LIMIT 1')->fetchColumn();
    };
    $familyId = function (string $code) use ($pdo): int {
        return (int) $pdo->query('SELECT id FROM material_families WHERE code = ' . $pdo->quote($code) . ' LIMIT 1')->fetchColumn();
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
            code,
            name,
            description,
            category_id,
            material_family_id,
            stackable,
            max_stack,
            grid_w,
            grid_h,
            equip_slot_code,
            is_container,
            tradeable,
            base_config,
            status
        ) VALUES (
            :code,
            :name,
            :description,
            :category_id,
            :material_family_id,
            :stackable,
            :max_stack,
            :grid_w,
            :grid_h,
            :equip_slot_code,
            :is_container,
            :tradeable,
            :base_config,
            :status
        )');
        $stmt->execute($data + ['code' => $code]);

        return (int) $pdo->lastInsertId();
    };

    $items = [
        ['showcase_health_potion', 'Pocao Rubra', 'Uma pocao simples para testes de item consumivel.', 'consumable', 'herb', 1, 20, 1, 1, 'potion', 0, 'common', 38.0, 8, 0, '00000000-0000-4003-8000-000000000001'],
        ['showcase_iron_cuirass', 'Couraca de Ferro', 'Armadura comum de treino.', 'armor', 'metal', 0, 1, 2, 3, 'chest', 0, 'common', 45.0, 0, 0, '00000000-0000-4003-8000-000000000002'],
        ['showcase_moon_ring', 'Anel Lunar', 'Anel magico com brilho frio.', 'armor', 'essence', 0, 1, 1, 1, 'ring', 0, 'magic', 58.0, 2, 0, '00000000-0000-4003-8000-000000000003'],
        ['showcase_frost_shield', 'Escudo de Geada', 'Escudo raro com runas glaciais.', 'armor', 'metal', 0, 1, 2, 2, 'shield', 0, 'rare', 71.0, 3, 0, '00000000-0000-4003-8000-000000000004'],
        ['showcase_ember_axe', 'Machado da Brasa', 'Machado raro para validar artes largas.', 'weapon', 'metal', 0, 1, 2, 3, 'weapon', 0, 'rare', 74.0, 5, 0, '00000000-0000-4003-8000-000000000005'],
        ['showcase_shadow_boots', 'Botas do Breu', 'Botas epicas silenciosas.', 'armor', 'leather', 0, 1, 2, 2, 'boots', 0, 'epic', 83.0, 0, 3, '00000000-0000-4003-8000-000000000006'],
        ['showcase_storm_staff', 'Cajado da Tempestade', 'Cajado epico para testar item alto.', 'weapon', 'wood', 0, 1, 1, 3, 'weapon', 0, 'epic', 86.0, 2, 3, '00000000-0000-4003-8000-000000000007'],
        ['showcase_drake_helm', 'Elmo do Draco', 'Elmo lendario com metal escurecido.', 'armor', 'metal', 0, 1, 2, 2, 'helmet', 0, 'legendary', 92.0, 4, 3, '00000000-0000-4003-8000-000000000008'],
        ['showcase_sunblade', 'Lamina Solar', 'Arma lendaria para testar brilho laranja.', 'weapon', 'metal', 0, 1, 1, 3, 'weapon', 0, 'legendary', 95.0, 6, 3, '00000000-0000-4003-8000-000000000009'],
        ['showcase_oracle_amulet', 'Amuleto do Oraculo', 'Artefato unico com energia instavel.', 'armor', 'essence', 0, 1, 1, 1, 'amulet', 0, 'divine', 99.0, 8, 3, '00000000-0000-4003-8000-000000000010'],
        ['showcase_dragon_egg', 'Ovo de Dragao', 'Relicario unico usado para testar item grande.', 'material', 'essence', 0, 1, 2, 2, null, 0, 'unique', 100.0, 0, 6, '00000000-0000-4003-8000-000000000011'],
        ['showcase_gold_pouch', 'Bolsa de Moedas', 'Pacote de moeda visual para testes.', 'currency', 'currency_metal', 1, 999, 1, 1, null, 0, 'magic', 60.0, 3, 6, '00000000-0000-4003-8000-000000000012'],
        ['jewel_blessing_minor', 'Joia da Bencao Menor', 'Tenta melhorar um item em +1 com alta chance de sucesso.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'magic', 62.0, 0, 8, '00000000-0000-4003-8000-000000000013'],
        ['jewel_blessing_minor', 'Joia da Bencao Menor', 'Tenta melhorar um item em +1 com alta chance de sucesso.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'magic', 62.0, 3, 8, '00000000-0000-4003-8000-000000000019'],
        ['jewel_blessing_minor', 'Joia da Bencao Menor', 'Tenta melhorar um item em +1 com alta chance de sucesso.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'magic', 62.0, 4, 8, '00000000-0000-4003-8000-00000000001a'],
        ['jewel_blessing_minor', 'Joia da Bencao Menor', 'Tenta melhorar um item em +1 com alta chance de sucesso.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'magic', 62.0, 5, 8, '00000000-0000-4003-8000-00000000001b'],
        ['jewel_blessing_minor', 'Joia da Bencao Menor', 'Tenta melhorar um item em +1 com alta chance de sucesso.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'magic', 62.0, 6, 8, '00000000-0000-4003-8000-00000000001c'],
        ['jewel_soul_minor', 'Joia da Alma Menor', 'Tenta melhorar atributos do item, com risco moderado de falha.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 72.0, 1, 8, '00000000-0000-4003-8000-000000000014'],
        ['jewel_soul_minor', 'Joia da Alma Menor', 'Tenta melhorar atributos do item, com risco moderado de falha.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 72.0, 0, 9, '00000000-0000-4003-8000-00000000001d'],
        ['jewel_soul_minor', 'Joia da Alma Menor', 'Tenta melhorar atributos do item, com risco moderado de falha.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 72.0, 1, 9, '00000000-0000-4003-8000-00000000001e'],
        ['jewel_soul_minor', 'Joia da Alma Menor', 'Tenta melhorar atributos do item, com risco moderado de falha.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 72.0, 2, 9, '00000000-0000-4003-8000-00000000001f'],
        ['jewel_soul_minor', 'Joia da Alma Menor', 'Tenta melhorar atributos do item, com risco moderado de falha.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 72.0, 3, 9, '00000000-0000-4003-8000-000000000020'],
        ['jewel_soul_minor', 'Joia da Alma Menor', 'Tenta melhorar atributos do item, com risco moderado de falha.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 72.0, 4, 9, '00000000-0000-4003-8000-000000000021'],
        ['jewel_chaos_minor', 'Joia do Caos Menor', 'Transforma a raridade de equipamentos comuns em resultados instaveis.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'epic', 84.0, 2, 8, '00000000-0000-4003-8000-000000000015'],
        ['jewel_chaos_minor', 'Joia do Caos Menor', 'Transforma a raridade de equipamentos comuns em resultados instaveis.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'epic', 84.0, 7, 8, '00000000-0000-4003-8000-000000000022'],
        ['jewel_chaos_minor', 'Joia do Caos Menor', 'Transforma a raridade de equipamentos comuns em resultados instaveis.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'epic', 84.0, 8, 8, '00000000-0000-4003-8000-000000000023'],
        ['jewel_chaos_minor', 'Joia do Caos Menor', 'Transforma a raridade de equipamentos comuns em resultados instaveis.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'epic', 84.0, 9, 8, '00000000-0000-4003-8000-000000000024'],
        ['jewel_chaos_minor', 'Joia do Caos Menor', 'Transforma a raridade de equipamentos comuns em resultados instaveis.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'epic', 84.0, 0, 10, '00000000-0000-4003-8000-000000000025'],
        ['jewel_blessing_minor', 'Joia da Bencao Menor', 'Tenta melhorar um item em +1 com alta chance de sucesso.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'magic', 62.0, 1, 10, '00000000-0000-4003-8000-000000000026'],
        ['jewel_blessing_minor', 'Joia da Bencao Menor', 'Tenta melhorar um item em +1 com alta chance de sucesso.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'magic', 62.0, 2, 10, '00000000-0000-4003-8000-000000000027'],
        ['jewel_soul_minor', 'Joia da Alma Menor', 'Tenta melhorar atributos do item, com risco moderado de falha.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 72.0, 3, 10, '00000000-0000-4003-8000-000000000028'],
        ['gem_ruby_attack', 'Rubi Marcial', 'Gema de engaste que favorece poder de ataque.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 75.0, 4, 10, '00000000-0000-4003-8000-000000000029'],
        ['gem_emerald_vitality', 'Esmeralda Vital', 'Gema de engaste que favorece vida maxima.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 76.0, 5, 10, '00000000-0000-4003-8000-00000000002a'],
        ['showcase_common_wings', 'Asas de Linho', 'Asas simples para testes de equipamento comum.', 'armor', 'leather', 0, 1, 2, 2, 'wings', 0, 'common', 42.0, 0, 11, '00000000-0000-4003-8000-00000000002b'],
        ['showcase_common_gloves', 'Luvas de Couro', 'Luvas comuns para testes de melhoria.', 'armor', 'leather', 0, 1, 1, 1, 'gloves', 0, 'common', 40.0, 1, 11, '00000000-0000-4003-8000-00000000002c'],
        ['showcase_common_pants', 'Calca de Linho', 'Calca comum para testes de caos e bless.', 'armor', 'leather', 0, 1, 2, 2, 'pants', 0, 'common', 41.0, 2, 11, '00000000-0000-4003-8000-00000000002d'],
        ['showcase_common_ring', 'Anel de Cobre', 'Anel comum sem atributos para testes.', 'armor', 'metal', 0, 1, 1, 1, 'ring', 0, 'common', 39.0, 3, 11, '00000000-0000-4003-8000-00000000002e'],
        ['showcase_common_earring', 'Brinco de Cobre', 'Brinco comum para testes de acessorio pequeno.', 'armor', 'metal', 0, 1, 1, 1, 'earring', 0, 'common', 39.0, 4, 11, '00000000-0000-4003-8000-00000000002f'],
        ['showcase_common_sword', 'Espada de Treino', 'Espada comum de uma mao para testes iniciais.', 'weapon', 'metal', 0, 1, 1, 3, 'weapon', 0, 'common', 44.0, 5, 11, '00000000-0000-4003-8000-000000000030'],
        ['jewel_reroll_minor', 'Joia de Rerrolagem Menor', 'Substitui um affix aleatorio por outro compativel.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 70.0, 6, 10, '00000000-0000-4003-8000-000000000031'],
        ['jewel_reroll_minor', 'Joia de Rerrolagem Menor', 'Substitui um affix aleatorio por outro compativel.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 70.0, 7, 10, '00000000-0000-4003-8000-000000000032'],
        ['jewel_reroll_minor', 'Joia de Rerrolagem Menor', 'Substitui um affix aleatorio por outro compativel.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 70.0, 0, 11, '00000000-0000-4003-8000-000000000033'],
        ['gem_ruby_attack', 'Rubi Marcial', 'Gema de engaste que favorece poder de ataque.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 75.0, 3, 8, '00000000-0000-4003-8000-000000000016'],
        ['gem_emerald_vitality', 'Esmeralda Vital', 'Gema de engaste que favorece vida maxima.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'rare', 76.0, 4, 8, '00000000-0000-4003-8000-000000000017'],
        ['gem_sapphire_guard', 'Safira Guardiã', 'Gema de engaste que favorece armadura.', 'material', 'essence', 0, 1, 1, 1, null, 0, 'magic', 64.0, 5, 8, '00000000-0000-4003-8000-000000000018'],
    ];

    $container = $pdo->prepare('SELECT ci.id FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE ci.owner_player_id = :player_id AND cd.code = :code AND ci.status = :status LIMIT 1');
    $container->execute([
        'player_id' => $playerId,
        'code' => 'market_delivery',
        'status' => 'active',
    ]);
    $containerId = (int) $container->fetchColumn();
    if ($containerId <= 0) {
        return;
    }

    $tableExists = function (string $table) use ($pdo): bool {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return $stmt->fetchColumn() !== false;
        }

        return $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn() !== false;
    };
    $propertyId = fn (string $code): int => (int) $pdo->query('SELECT id FROM item_property_definitions WHERE code = ' . $pdo->quote($code) . ' LIMIT 1')->fetchColumn();
    $affixId = fn (string $code): int => (int) $pdo->query('SELECT id FROM item_affix_definitions WHERE code = ' . $pdo->quote($code) . ' LIMIT 1')->fetchColumn();

    foreach ($items as [$code, $name, $description, $category, $family, $stackable, $maxStack, $gridW, $gridH, $slot, $isContainer, $qualityBucket, $qualityValue, $gridX, $gridY, $publicId]) {
        $definitionId = $upsertDefinition($code, [
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
            'tradeable' => 1,
            'base_config' => json_encode(array_filter([
                'showcase' => true,
                'hands' => $code === 'showcase_storm_staff' ? 2 : null,
                'allow_dual_wield' => in_array($code, ['showcase_sunblade'], true) ? true : null,
                'offhand_type' => $code === 'showcase_frost_shield' ? 'shield' : null,
                'enhancement_type' => str_starts_with($code, 'jewel_') ? 'upgrade_jewel' : null,
                'upgrade_success_rate' => match ($code) {
                    'jewel_blessing_minor' => 85,
                    'jewel_soul_minor' => 62,
                    'jewel_chaos_minor' => 38,
                    'jewel_reroll_minor' => 55,
                    default => null,
                },
                'bless_property_boost' => $code === 'jewel_blessing_minor' ? ['min_percent' => 3, 'max_percent' => 8] : null,
                'soul_affix_boost' => $code === 'jewel_soul_minor' ? ['min_percent' => 5, 'max_percent' => 15] : null,
                'gem_effect' => match ($code) {
                    'gem_ruby_attack' => ['property' => 'attack_power', 'value' => 7],
                    'gem_emerald_vitality' => ['property' => 'max_health', 'value' => 22],
                    'gem_sapphire_guard' => ['property' => 'armor', 'value' => 9],
                    default => null,
                },
            ], fn ($value): bool => $value !== null), JSON_THROW_ON_ERROR),
            'status' => 'active',
        ]);

        $existingItem = $pdo->prepare('SELECT id FROM item_instances WHERE public_id = :public_id LIMIT 1');
        $existingItem->execute(['public_id' => $publicId]);
        $itemId = $existingItem->fetchColumn();

        if ($itemId) {
            $update = $pdo->prepare('UPDATE item_instances SET
                item_definition_id = :item_definition_id,
                owner_player_id = :owner_player_id,
                quantity = :quantity,
                quality_value = :quality_value,
                quality_bucket = :quality_bucket,
                item_name = :item_name,
                state = :state
                WHERE id = :id');
            $update->execute([
                'item_definition_id' => $definitionId,
                'owner_player_id' => $playerId,
                'quantity' => $stackable ? max(1, min($maxStack, 10)) : 1,
                'quality_value' => $qualityValue,
                'quality_bucket' => $qualityBucket,
                'item_name' => $name,
                'state' => 'available',
                'id' => $itemId,
            ]);
            $itemId = (int) $itemId;
        } else {
            $insert = $pdo->prepare('INSERT INTO item_instances (
                public_id,
                item_definition_id,
                owner_player_id,
                quantity,
                quality_value,
                quality_bucket,
                item_name,
                state
            ) VALUES (
                :public_id,
                :item_definition_id,
                :owner_player_id,
                :quantity,
                :quality_value,
                :quality_bucket,
                :item_name,
                :state
            )');
            $insert->execute([
                'public_id' => $publicId,
                'item_definition_id' => $definitionId,
                'owner_player_id' => $playerId,
                'quantity' => $stackable ? max(1, min($maxStack, 10)) : 1,
                'quality_value' => $qualityValue,
                'quality_bucket' => $qualityBucket,
                'item_name' => $name,
                'state' => 'available',
            ]);
            $itemId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM container_items WHERE item_instance_id = :item_instance_id')->execute([
            'item_instance_id' => $itemId,
        ]);

        $isEquipped = $pdo->prepare('SELECT item_instance_id FROM player_equipment WHERE item_instance_id = :item_instance_id LIMIT 1');
        $isEquipped->execute(['item_instance_id' => $itemId]);
        $equipped = $isEquipped->fetchColumn() !== false;

        if (!$equipped) {
            $place = $pdo->prepare('INSERT INTO container_items (
            container_instance_id,
            item_instance_id,
            grid_x,
            grid_y,
            grid_w,
            grid_h,
            rotated,
            locked
        ) VALUES (
            :container_instance_id,
            :item_instance_id,
            :grid_x,
            :grid_y,
            :grid_w,
            :grid_h,
            0,
            0
        )');
            $place->execute([
                'container_instance_id' => $containerId,
                'item_instance_id' => $itemId,
                'grid_x' => $gridX,
                'grid_y' => $gridY,
                'grid_w' => $gridW,
                'grid_h' => $gridH,
            ]);
        }

        if ($tableExists('item_instance_properties')) {
            $pdo->prepare('DELETE FROM item_instance_properties WHERE item_instance_id = :item_instance_id')->execute([
                'item_instance_id' => $itemId,
            ]);

            $properties = [];
            if (in_array($category, ['weapon', 'tool'], true)) {
                $properties[] = ['attack_power', null, (int) round($qualityValue / 4), null, 'base'];
                $properties[] = ['agility', null, max(1, (int) round($qualityValue / 6)), null, 'base'];
            }
            if ($category === 'armor') {
                $properties[] = ['armor', null, (int) round($qualityValue / 3), null, 'base'];
                $properties[] = ['energy', null, max(1, (int) round($qualityValue / 5)), null, 'base'];
            }
            if (in_array($qualityBucket, ['rare', 'epic', 'legendary', 'unique', 'divine'], true) && in_array($category, ['weapon', 'armor', 'tool'], true)) {
                $properties[] = ['upgrade_level', null, ['rare' => 1, 'epic' => 2, 'legendary' => 3, 'unique' => 4, 'divine' => 5][$qualityBucket] ?? 1, null, 'upgrade'];
            }
            if (str_starts_with($code, 'jewel_')) {
                $properties[] = ['upgrade_success_rate', match ($code) {
                    'jewel_blessing_minor' => 85,
                    'jewel_soul_minor' => 62,
                    'jewel_chaos_minor' => 38,
                    default => 0,
                }, null, null, 'upgrade_jewel'];
            }
            if (str_starts_with($code, 'gem_')) {
                $properties[] = match ($code) {
                    'gem_ruby_attack' => ['attack_power', null, 7, null, 'gem'],
                    'gem_emerald_vitality' => ['max_health', null, 22, null, 'gem'],
                    'gem_sapphire_guard' => ['armor', null, 9, null, 'gem'],
                    default => ['gem_power', null, 1, null, 'gem'],
                };
            }

            foreach ($properties as [$propertyCode, $numericValue, $integerValue, $textValue, $source]) {
                $stmt = $pdo->prepare('INSERT INTO item_instance_properties (item_instance_id, property_definition_id, numeric_value, integer_value, text_value, source) VALUES (:item_instance_id, :property_definition_id, :numeric_value, :integer_value, :text_value, :source)');
                $stmt->execute([
                    'item_instance_id' => $itemId,
                    'property_definition_id' => $propertyId($propertyCode),
                    'numeric_value' => $numericValue,
                    'integer_value' => $integerValue,
                    'text_value' => $textValue,
                    'source' => $source,
                ]);
            }
        }

        if ($tableExists('item_instance_affixes') && in_array($category, ['weapon', 'armor', 'tool'], true) && !str_starts_with($code, 'jewel_') && !str_starts_with($code, 'gem_') && in_array($qualityBucket, ['rare', 'epic', 'legendary', 'unique', 'divine'], true)) {
            $pdo->prepare('DELETE FROM item_instance_affixes WHERE item_instance_id = :item_instance_id')->execute([
                'item_instance_id' => $itemId,
            ]);

            $affixes = match ($qualityBucket) {
                'rare' => [['sharp', 8], ['vitality', 18]],
                'epic' => [['ember', 12], ['precision', 3]],
                'legendary' => [['ember', 16], ['vitality', 38], ['precision', 5]],
                'unique' => [['frostward', 10], ['vitality', 45], ['precision', 6]],
                default => [],
            };

            foreach ($affixes as [$affixCode, $rolledValue]) {
                $stmt = $pdo->prepare('INSERT INTO item_instance_affixes (item_instance_id, affix_definition_id, rolled_value, source) VALUES (:item_instance_id, :affix_definition_id, :rolled_value, :source)');
                $stmt->execute([
                    'item_instance_id' => $itemId,
                    'affix_definition_id' => $affixId($affixCode),
                    'rolled_value' => $rolledValue,
                    'source' => 'showcase',
                ]);
            }
        }

        if ($tableExists('item_instance_sockets')) {
            $pdo->prepare('DELETE FROM item_instance_sockets WHERE item_instance_id = :item_instance_id')->execute([
                'item_instance_id' => $itemId,
            ]);

            $socketCount = match ($qualityBucket) {
                'uncommon', 'magic' => 1,
                'rare', 'legendary' => 2,
                'epic' => 3,
                'divine', 'unique' => 4,
                default => 0,
            };

            if (!in_array($category, ['weapon', 'armor', 'tool'], true)) {
                $socketCount = 0;
            }

            for ($socket = 0; $socket < $socketCount; $socket += 1) {
                $stmt = $pdo->prepare('INSERT INTO item_instance_sockets (item_instance_id, socket_index, socket_type, status) VALUES (:item_instance_id, :socket_index, :socket_type, :status)');
                $stmt->execute([
                    'item_instance_id' => $itemId,
                    'socket_index' => $socket,
                    'socket_type' => 'generic',
                    'status' => 'empty',
                ]);
            }
        }
    }

    if ($tableExists('item_sets')) {
        $set = $pdo->prepare('SELECT id FROM item_sets WHERE code = :code LIMIT 1');
        $set->execute(['code' => 'draco_solar']);
        $setId = $set->fetchColumn();

        if ($setId) {
            $stmt = $pdo->prepare('UPDATE item_sets SET name = :name, description = :description, aura_color = :aura_color, status = :status WHERE id = :id');
            $stmt->execute([
                'id' => $setId,
                'name' => 'Draco Solar',
                'description' => 'Pecas lendarias que reagem quando equipadas juntas.',
                'aura_color' => '#55c58a',
                'status' => 'active',
            ]);
            $setId = (int) $setId;
        } else {
            $stmt = $pdo->prepare('INSERT INTO item_sets (code, name, description, aura_color, status) VALUES (:code, :name, :description, :aura_color, :status)');
            $stmt->execute([
                'code' => 'draco_solar',
                'name' => 'Draco Solar',
                'description' => 'Pecas lendarias que reagem quando equipadas juntas.',
                'aura_color' => '#55c58a',
                'status' => 'active',
            ]);
            $setId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM item_set_pieces WHERE item_set_id = :item_set_id')->execute(['item_set_id' => $setId]);
        $pieceDefinitions = [
            ['showcase_sunblade', 'weapon', 10],
            ['showcase_drake_helm', 'helmet', 20],
            ['showcase_iron_cuirass', 'chest', 30],
            ['showcase_shadow_boots', 'boots', 40],
        ];
        foreach ($pieceDefinitions as [$definitionCode, $pieceKey, $sortOrder]) {
            $stmt = $pdo->prepare('INSERT INTO item_set_pieces (item_set_id, item_definition_id, piece_key, sort_order) VALUES (:item_set_id, :item_definition_id, :piece_key, :sort_order)');
            $stmt->execute([
                'item_set_id' => $setId,
                'item_definition_id' => $pdo->query('SELECT id FROM item_definitions WHERE code = ' . $pdo->quote($definitionCode) . ' LIMIT 1')->fetchColumn(),
                'piece_key' => $pieceKey,
                'sort_order' => $sortOrder,
            ]);
        }

        $pdo->prepare('DELETE FROM item_set_bonuses WHERE item_set_id = :item_set_id')->execute(['item_set_id' => $setId]);
        $bonuses = [
            [2, 'attack_power', null, 8, '2 pecas: +8 Poder de ataque'],
            [3, 'max_health', null, 35, '3 pecas: +35 Vida maxima'],
            [4, 'critical_chance', 4.0, null, '4 pecas: +4% Chance critica'],
        ];
        foreach ($bonuses as [$requiredPieces, $propertyCode, $numericValue, $integerValue, $description]) {
            $stmt = $pdo->prepare('INSERT INTO item_set_bonuses (item_set_id, required_pieces, property_definition_id, numeric_value, integer_value, description) VALUES (:item_set_id, :required_pieces, :property_definition_id, :numeric_value, :integer_value, :description)');
            $stmt->execute([
                'item_set_id' => $setId,
                'required_pieces' => $requiredPieces,
                'property_definition_id' => $propertyId($propertyCode),
                'numeric_value' => $numericValue,
                'integer_value' => $integerValue,
                'description' => $description,
            ]);
        }
    }
};
