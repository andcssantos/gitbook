<?php

return new class {
    public function up(PDO $pdo): void
    {
        $existing = $pdo->prepare('SELECT id FROM equipment_slots WHERE code = :code LIMIT 1');
        $existing->execute(['code' => 'earring']);
        if ($existing->fetchColumn() !== false) {
            return;
        }

        $insert = $pdo->prepare('INSERT INTO equipment_slots (code, name, sort_order, status) VALUES (:code, :name, :sort_order, :status)');
        $insert->execute([
            'code' => 'earring',
            'name' => 'Earring',
            'sort_order' => 31,
            'status' => 'active',
        ]);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM equipment_slots WHERE code = 'earring'");
    }
};
