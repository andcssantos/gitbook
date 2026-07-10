<?php

return new class {
    public function up(PDO $pdo): void
    {
        foreach ($this->properties() as $property) {
            $existing = $pdo->prepare('SELECT id FROM item_property_definitions WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $property['code']]);
            $id = $existing->fetchColumn();

            if ($id) {
                $stmt = $pdo->prepare('UPDATE item_property_definitions SET name = :name, value_type = :value_type, unit = :unit, min_value = :min_value, max_value = :max_value, market_filterable = :market_filterable, status = :status WHERE id = :id');
                $stmt->execute($property + ['id' => $id]);
                continue;
            }

            $stmt = $pdo->prepare('INSERT INTO item_property_definitions (code, name, value_type, unit, min_value, max_value, market_filterable, status) VALUES (:code, :name, :value_type, :unit, :min_value, :max_value, :market_filterable, :status)');
            $stmt->execute($property);
        }
    }

    public function down(PDO $pdo): void
    {
        $codes = array_map(fn (array $property): string => $pdo->quote($property['code']), $this->properties());
        $pdo->exec('DELETE FROM item_property_definitions WHERE code IN (' . implode(',', $codes) . ')');
    }

    private function properties(): array
    {
        return [
            [
                'code' => 'upgrade_success_rate',
                'name' => 'Chance de melhoria',
                'value_type' => 'numeric',
                'unit' => '%',
                'min_value' => 0,
                'max_value' => 100,
                'market_filterable' => 1,
                'status' => 'active',
            ],
            [
                'code' => 'gem_power',
                'name' => 'Poder da gema',
                'value_type' => 'integer',
                'unit' => null,
                'min_value' => 1,
                'max_value' => 999999,
                'market_filterable' => 1,
                'status' => 'active',
            ],
        ];
    }
};
