<?php

return new class {
    public function up(PDO $pdo): void
    {
        $slots = [
            ['weapon', 'Weapon Main', 10],
            ['weapon_offhand', 'Weapon Offhand', 15],
            ['shield', 'Shield', 18],
            ['quiver', 'Quiver', 19],
            ['helmet', 'Helmet', 20],
            ['wings', 'Wings', 25],
            ['amulet', 'Amulet', 30],
            ['chest', 'Chest', 40],
            ['gloves', 'Gloves', 50],
            ['pants', 'Pants', 60],
            ['boots', 'Boots', 70],
            ['ring', 'Ring 1', 80],
            ['ring_2', 'Ring 2', 81],
            ['belt', 'Belt', 90],
            ['backpack', 'Backpack', 100],
            ['pet', 'Pet', 110],
            ['potion_1', 'Potion 1', 120],
            ['potion_2', 'Potion 2', 121],
            ['potion_3', 'Potion 3', 122],
            ['potion_4', 'Potion 4', 123],
        ];

        foreach ($slots as [$code, $name, $sortOrder]) {
            $existing = $pdo->prepare('SELECT id FROM equipment_slots WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $code]);
            $id = $existing->fetchColumn();

            if ($id !== false) {
                $update = $pdo->prepare('UPDATE equipment_slots SET name = :name, sort_order = :sort_order, status = :status WHERE id = :id');
                $update->execute([
                    'id' => (int) $id,
                    'name' => $name,
                    'sort_order' => $sortOrder,
                    'status' => 'active',
                ]);
                continue;
            }

            $insert = $pdo->prepare('INSERT INTO equipment_slots (code, name, sort_order, status) VALUES (:code, :name, :sort_order, :status)');
            $insert->execute([
                'code' => $code,
                'name' => $name,
                'sort_order' => $sortOrder,
                'status' => 'active',
            ]);
        }
    }

    public function down(PDO $pdo): void
    {
        $codes = ['weapon_offhand', 'shield', 'quiver', 'wings', 'amulet', 'ring_2', 'belt', 'pet', 'potion_1', 'potion_2', 'potion_3', 'potion_4'];
        $quoted = implode(',', array_map(fn (string $code): string => $pdo->quote($code), $codes));
        $pdo->exec("DELETE FROM equipment_slots WHERE code IN ({$quoted})");
    }
};
