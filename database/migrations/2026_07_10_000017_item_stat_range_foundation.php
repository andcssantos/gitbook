<?php

return new class {
    public function up(PDO $pdo): void
    {
        $this->seedBlessSuccessBonusProperty($pdo);
        $this->seedMasterworkAffix($pdo);
        $this->assignBlessStatScopes($pdo);
    }

    public function down(PDO $pdo): void
    {
        $pdo->prepare('DELETE FROM item_affix_definitions WHERE code = :code')->execute(['code' => 'masterwork']);
        $pdo->prepare('DELETE FROM item_property_definitions WHERE code = :code')->execute(['code' => 'bless_success_bonus']);
    }

    private function seedBlessSuccessBonusProperty(PDO $pdo): void
    {
        $existing = $pdo->prepare('SELECT id FROM item_property_definitions WHERE code = :code LIMIT 1');
        $existing->execute(['code' => 'bless_success_bonus']);
        $id = $existing->fetchColumn();

        $payload = [
            'code' => 'bless_success_bonus',
            'name' => 'Bonus de bencao',
            'value_type' => 'numeric',
            'unit' => '%',
            'min_value' => 0,
            'max_value' => 25,
            'market_filterable' => 0,
            'equipment_scope' => 'shared',
            'status' => 'active',
        ];

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
            $stmt->execute($payload + ['id' => $id]);

            return;
        }

        $stmt = $pdo->prepare('INSERT INTO item_property_definitions (
            code, name, value_type, unit, min_value, max_value, market_filterable, equipment_scope, status
        ) VALUES (
            :code, :name, :value_type, :unit, :min_value, :max_value, :market_filterable, :equipment_scope, :status
        )');
        $stmt->execute($payload);
    }

    private function seedMasterworkAffix(PDO $pdo): void
    {
        $propertyId = (int) $pdo->query("SELECT id FROM item_property_definitions WHERE code = 'bless_success_bonus' LIMIT 1")->fetchColumn();
        if ($propertyId <= 0) {
            return;
        }

        $existing = $pdo->prepare('SELECT id FROM item_affix_definitions WHERE code = :code LIMIT 1');
        $existing->execute(['code' => 'masterwork']);
        $id = $existing->fetchColumn();

        $payload = [
            'code' => 'masterwork',
            'name' => 'da Maestria',
            'affix_type' => 'suffix',
            'property_definition_id' => $propertyId,
            'min_value' => 5,
            'max_value' => 15,
            'rarity_weight' => 35,
            'min_item_level' => 3,
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
            $stmt->execute($payload + ['id' => $id]);

            return;
        }

        $stmt = $pdo->prepare('INSERT INTO item_affix_definitions (
            code, name, affix_type, property_definition_id, min_value, max_value, rarity_weight, min_item_level, status
        ) VALUES (
            :code, :name, :affix_type, :property_definition_id, :min_value, :max_value, :rarity_weight, :min_item_level, :status
        )');
        $stmt->execute($payload);
    }

    private function assignBlessStatScopes(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'item_property_definitions', 'equipment_scope')) {
            return;
        }

        $assignments = [
            'strength' => 'offense',
            'defense' => 'defense',
            'vitality' => 'shared',
        ];

        foreach ($assignments as $code => $scope) {
            $stmt = $pdo->prepare('UPDATE item_property_definitions SET equipment_scope = :scope WHERE code = :code');
            $stmt->execute([
                'code' => $code,
                'scope' => $scope,
            ]);
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute([
            'table' => $table,
            'column' => $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
