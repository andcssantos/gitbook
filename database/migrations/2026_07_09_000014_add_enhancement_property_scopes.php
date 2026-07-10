<?php

return new class {
    public function up(PDO $pdo): void
    {
        $this->addEquipmentScopeColumn($pdo);
        $this->seedProperties($pdo);
        $this->assignScopes($pdo);
        $this->bumpUpgradeLevelMax($pdo);
    }

    public function down(PDO $pdo): void
    {
        if ($this->columnExists($pdo, 'item_property_definitions', 'equipment_scope')) {
            $pdo->exec('ALTER TABLE item_property_definitions DROP COLUMN equipment_scope');
        }

        foreach (['agility', 'energy'] as $code) {
            $pdo->prepare('DELETE FROM item_property_definitions WHERE code = :code')->execute(['code' => $code]);
        }
    }

    private function addEquipmentScopeColumn(PDO $pdo): void
    {
        if ($this->columnExists($pdo, 'item_property_definitions', 'equipment_scope')) {
            return;
        }

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec("ALTER TABLE item_property_definitions ADD COLUMN equipment_scope TEXT NULL DEFAULT 'shared'");
            return;
        }

        $pdo->exec("ALTER TABLE item_property_definitions
            ADD COLUMN equipment_scope VARCHAR(30) NULL DEFAULT 'shared' AFTER market_filterable");
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

    private function assignScopes(PDO $pdo): void
    {
        foreach ($this->scopeAssignments() as $code => $scope) {
            $stmt = $pdo->prepare('UPDATE item_property_definitions SET equipment_scope = :scope WHERE code = :code');
            $stmt->execute([
                'code' => $code,
                'scope' => $scope,
            ]);
        }
    }

    private function bumpUpgradeLevelMax(PDO $pdo): void
    {
        $stmt = $pdo->prepare('UPDATE item_property_definitions SET max_value = 25 WHERE code = :code');
        $stmt->execute(['code' => 'upgrade_level']);
    }

    private function newProperties(): array
    {
        return [
            [
                'code' => 'agility',
                'name' => 'Agilidade',
                'value_type' => 'integer',
                'unit' => null,
                'min_value' => 0,
                'max_value' => 999999,
                'market_filterable' => 1,
                'equipment_scope' => 'shared',
                'status' => 'active',
            ],
            [
                'code' => 'energy',
                'name' => 'Energia',
                'value_type' => 'integer',
                'unit' => null,
                'min_value' => 0,
                'max_value' => 999999,
                'market_filterable' => 1,
                'equipment_scope' => 'shared',
                'status' => 'active',
            ],
        ];
    }

    private function scopeAssignments(): array
    {
        return [
            'attack_power' => 'offense',
            'armor' => 'defense',
            'max_health' => 'shared',
            'agility' => 'shared',
            'energy' => 'shared',
            'critical_chance' => 'offense',
            'fire_damage' => 'exclusive_offense',
            'cold_resistance' => 'exclusive_defense',
            'upgrade_level' => 'shared',
            'upgrade_success_rate' => 'shared',
            'gem_power' => 'shared',
            'socket_count' => 'shared',
        ];
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
