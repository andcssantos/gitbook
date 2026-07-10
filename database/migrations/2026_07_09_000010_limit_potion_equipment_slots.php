<?php

return new class {
    public function up(PDO $pdo): void
    {
        $stmt = $pdo->prepare('UPDATE equipment_slots SET status = :status WHERE code IN (:potion_3, :potion_4)');
        $stmt->execute([
            'status' => 'inactive',
            'potion_3' => 'potion_3',
            'potion_4' => 'potion_4',
        ]);
    }

    public function down(PDO $pdo): void
    {
        $stmt = $pdo->prepare('UPDATE equipment_slots SET status = :status WHERE code IN (:potion_3, :potion_4)');
        $stmt->execute([
            'status' => 'active',
            'potion_3' => 'potion_3',
            'potion_4' => 'potion_4',
        ]);
    }
};
