<?php

return new class {
    public function up(PDO $pdo): void
    {
        $this->seedProperties($pdo);
        $this->seedAffixes($pdo);
    }

    public function down(PDO $pdo): void
    {
        foreach ($this->affixCodes() as $code) {
            $pdo->prepare('DELETE FROM item_affix_definitions WHERE code = :code')->execute(['code' => $code]);
        }

        foreach ($this->propertyCodes() as $code) {
            $pdo->prepare('DELETE FROM item_property_definitions WHERE code = :code')->execute(['code' => $code]);
        }
    }

    private function propertyCodes(): array
    {
        return [
            'strength',
            'defense',
            'vitality',
            'critical_damage',
            'attack_speed',
            'dodge_chance',
            'item_rarity_bonus',
            'chest_find_chance',
            'gold_find',
            'experience_gain',
            'expedition_carry_bonus',
            'map_duration_bonus',
        ];
    }

    private function affixCodes(): array
    {
        return [
            'mighty',
            'bulwark',
            'vital_core',
            'brutal_edge',
            'swiftness',
            'evasion',
            'fortune',
            'treasure_sense',
            'greed',
            'insight',
            'packrat',
            'wayfarer',
        ];
    }

    private function seedProperties(PDO $pdo): void
    {
        foreach ($this->newProperties() as $property) {
            $existing = $pdo->prepare('SELECT id FROM item_property_definitions WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $property['code']]);
            $id = $existing->fetchColumn();

            if ($id) {
                $stmt = $pdo->prepare('UPDATE item_property_definitions
                    SET name = :name,
                        value_type = :value_type,
                        unit = :unit,
                        min_value = :min_value,
                        max_value = :max_value,
                        market_filterable = :market_filterable,
                        equipment_scope = :equipment_scope,
                        status = :status
                    WHERE id = :id');
                $stmt->execute($property + ['id' => $id]);
                continue;
            }

            $stmt = $pdo->prepare('INSERT INTO item_property_definitions (
                code, name, value_type, unit, min_value, max_value, market_filterable, equipment_scope, status
            ) VALUES (
                :code, :name, :value_type, :unit, :min_value, :max_value, :market_filterable, :equipment_scope, :status
            )');
            $stmt->execute($property);
        }
    }

    private function seedAffixes(PDO $pdo): void
    {
        $propertyId = fn (string $code): int => (int) $pdo->query('SELECT id FROM item_property_definitions WHERE code = ' . $pdo->quote($code))->fetchColumn();

        foreach ($this->newAffixes() as $affix) {
            $existing = $pdo->prepare('SELECT id FROM item_affix_definitions WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $affix['code']]);
            $id = $existing->fetchColumn();
            $data = [
                'code' => $affix['code'],
                'name' => $affix['name'],
                'affix_type' => $affix['affix_type'],
                'property_definition_id' => $propertyId($affix['property_code']),
                'min_value' => $affix['min_value'],
                'max_value' => $affix['max_value'],
                'rarity_weight' => $affix['rarity_weight'],
                'min_item_level' => $affix['min_item_level'],
                'status' => 'active',
            ];

            if ($id) {
                $stmt = $pdo->prepare('UPDATE item_affix_definitions
                    SET name = :name,
                        affix_type = :affix_type,
                        property_definition_id = :property_definition_id,
                        min_value = :min_value,
                        max_value = :max_value,
                        rarity_weight = :rarity_weight,
                        min_item_level = :min_item_level,
                        status = :status
                    WHERE id = :id');
                $stmt->execute($data + ['id' => $id]);
                continue;
            }

            $stmt = $pdo->prepare('INSERT INTO item_affix_definitions (
                code, name, affix_type, property_definition_id, min_value, max_value, rarity_weight, min_item_level, status
            ) VALUES (
                :code, :name, :affix_type, :property_definition_id, :min_value, :max_value, :rarity_weight, :min_item_level, :status
            )');
            $stmt->execute($data);
        }
    }

    private function newProperties(): array
    {
        return [
            ['code' => 'strength', 'name' => 'Forca', 'value_type' => 'integer', 'unit' => null, 'min_value' => 1, 'max_value' => 9999, 'market_filterable' => 1, 'equipment_scope' => 'shared', 'status' => 'active'],
            ['code' => 'defense', 'name' => 'Defesa', 'value_type' => 'integer', 'unit' => null, 'min_value' => 1, 'max_value' => 9999, 'market_filterable' => 1, 'equipment_scope' => 'shared', 'status' => 'active'],
            ['code' => 'vitality', 'name' => 'Vitalidade', 'value_type' => 'integer', 'unit' => null, 'min_value' => 1, 'max_value' => 9999, 'market_filterable' => 1, 'equipment_scope' => 'shared', 'status' => 'active'],
            ['code' => 'critical_damage', 'name' => 'Dano critico', 'value_type' => 'numeric', 'unit' => '%', 'min_value' => 0, 'max_value' => 500, 'market_filterable' => 1, 'equipment_scope' => 'exclusive_offense', 'status' => 'active'],
            ['code' => 'attack_speed', 'name' => 'Velocidade de ataque', 'value_type' => 'numeric', 'unit' => '%', 'min_value' => 0, 'max_value' => 200, 'market_filterable' => 1, 'equipment_scope' => 'offense', 'status' => 'active'],
            ['code' => 'dodge_chance', 'name' => 'Desvio', 'value_type' => 'numeric', 'unit' => '%', 'min_value' => 0, 'max_value' => 75, 'market_filterable' => 1, 'equipment_scope' => 'exclusive_defense', 'status' => 'active'],
            ['code' => 'item_rarity_bonus', 'name' => 'Chance de drop raro', 'value_type' => 'numeric', 'unit' => '%', 'min_value' => 0, 'max_value' => 200, 'market_filterable' => 1, 'equipment_scope' => 'shared', 'status' => 'active'],
            ['code' => 'chest_find_chance', 'name' => 'Chance de baus', 'value_type' => 'numeric', 'unit' => '%', 'min_value' => 0, 'max_value' => 200, 'market_filterable' => 1, 'equipment_scope' => 'shared', 'status' => 'active'],
            ['code' => 'gold_find', 'name' => 'Gold encontrado', 'value_type' => 'numeric', 'unit' => '%', 'min_value' => 0, 'max_value' => 300, 'market_filterable' => 1, 'equipment_scope' => 'shared', 'status' => 'active'],
            ['code' => 'experience_gain', 'name' => 'Ganho de EXP', 'value_type' => 'numeric', 'unit' => '%', 'min_value' => 0, 'max_value' => 200, 'market_filterable' => 1, 'equipment_scope' => 'shared', 'status' => 'active'],
            ['code' => 'expedition_carry_bonus', 'name' => 'Espaco expedition carry', 'value_type' => 'integer', 'unit' => null, 'min_value' => 0, 'max_value' => 24, 'market_filterable' => 1, 'equipment_scope' => 'shared', 'status' => 'active'],
            ['code' => 'map_duration_bonus', 'name' => 'Tempo de exploracao', 'value_type' => 'numeric', 'unit' => '%', 'min_value' => 0, 'max_value' => 100, 'market_filterable' => 1, 'equipment_scope' => 'shared', 'status' => 'active'],
        ];
    }

    private function newAffixes(): array
    {
        return [
            ['code' => 'mighty', 'name' => 'Poderoso', 'affix_type' => 'prefix', 'property_code' => 'strength', 'min_value' => 4, 'max_value' => 18, 'rarity_weight' => 90, 'min_item_level' => 1],
            ['code' => 'bulwark', 'name' => 'Baluarte', 'affix_type' => 'prefix', 'property_code' => 'defense', 'min_value' => 5, 'max_value' => 22, 'rarity_weight' => 90, 'min_item_level' => 1],
            ['code' => 'vital_core', 'name' => 'do Nucleo Vital', 'affix_type' => 'suffix', 'property_code' => 'vitality', 'min_value' => 6, 'max_value' => 24, 'rarity_weight' => 85, 'min_item_level' => 1],
            ['code' => 'brutal_edge', 'name' => 'da Lâmina Brutal', 'affix_type' => 'suffix', 'property_code' => 'critical_damage', 'min_value' => 8, 'max_value' => 28, 'rarity_weight' => 55, 'min_item_level' => 4],
            ['code' => 'swiftness', 'name' => 'da Rapidez', 'affix_type' => 'suffix', 'property_code' => 'attack_speed', 'min_value' => 3, 'max_value' => 14, 'rarity_weight' => 60, 'min_item_level' => 3],
            ['code' => 'evasion', 'name' => 'da Evasao', 'affix_type' => 'suffix', 'property_code' => 'dodge_chance', 'min_value' => 2, 'max_value' => 9, 'rarity_weight' => 58, 'min_item_level' => 3],
            ['code' => 'fortune', 'name' => 'da Fortuna', 'affix_type' => 'suffix', 'property_code' => 'item_rarity_bonus', 'min_value' => 4, 'max_value' => 18, 'rarity_weight' => 42, 'min_item_level' => 5],
            ['code' => 'treasure_sense', 'name' => 'do Faro de Tesouros', 'affix_type' => 'suffix', 'property_code' => 'chest_find_chance', 'min_value' => 5, 'max_value' => 22, 'rarity_weight' => 40, 'min_item_level' => 5],
            ['code' => 'greed', 'name' => 'da Ganancia', 'affix_type' => 'suffix', 'property_code' => 'gold_find', 'min_value' => 6, 'max_value' => 28, 'rarity_weight' => 48, 'min_item_level' => 4],
            ['code' => 'insight', 'name' => 'do Insight', 'affix_type' => 'suffix', 'property_code' => 'experience_gain', 'min_value' => 4, 'max_value' => 20, 'rarity_weight' => 46, 'min_item_level' => 4],
            ['code' => 'packrat', 'name' => 'do Colecionador', 'affix_type' => 'suffix', 'property_code' => 'expedition_carry_bonus', 'min_value' => 1, 'max_value' => 4, 'rarity_weight' => 35, 'min_item_level' => 6],
            ['code' => 'wayfarer', 'name' => 'do Viajante', 'affix_type' => 'suffix', 'property_code' => 'map_duration_bonus', 'min_value' => 4, 'max_value' => 16, 'rarity_weight' => 38, 'min_item_level' => 5],
        ];
    }
};
