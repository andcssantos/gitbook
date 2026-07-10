<?php

return new class {
    public function up(PDO $pdo): void
    {
        $stmt = $pdo->prepare('UPDATE equipment_slots SET status = :status WHERE code = :code');
        $stmt->execute([
            'status' => 'active',
            'code' => 'potion_3',
        ]);

        $stmt->execute([
            'status' => 'inactive',
            'code' => 'potion_4',
        ]);
    }

    public function down(PDO $pdo): void
    {
        $stmt = $pdo->prepare('UPDATE equipment_slots SET status = :status WHERE code = :code');
        $stmt->execute([
            'status' => 'inactive',
            'code' => 'potion_3',
        ]);
    }
};
