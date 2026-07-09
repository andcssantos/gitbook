<?php

return new class {
    public function up(PDO $pdo): void
    {
        $definitionId = $pdo->query("SELECT id FROM container_definitions WHERE code = 'main_inventory_level_1' LIMIT 1")->fetchColumn();
        if ($definitionId === false) {
            return;
        }

        $stmt = $pdo->prepare('DELETE FROM container_acceptance_rules WHERE container_definition_id = :container_definition_id AND rule_type = :rule_type AND reference_code = :reference_code');
        $stmt->execute([
            'container_definition_id' => (int) $definitionId,
            'rule_type' => 'CONTAINER_BLOCK',
            'reference_code' => '',
        ]);

        $existing = $pdo->prepare('SELECT id FROM container_acceptance_rules WHERE container_definition_id = :container_definition_id AND rule_type = :rule_type AND reference_code = :reference_code LIMIT 1');
        $existing->execute([
            'container_definition_id' => (int) $definitionId,
            'rule_type' => 'ACCEPT_ALL',
            'reference_code' => '',
        ]);

        if ($existing->fetchColumn() === false) {
            $insert = $pdo->prepare('INSERT INTO container_acceptance_rules (container_definition_id, rule_type, reference_code, allow, priority) VALUES (:container_definition_id, :rule_type, :reference_code, :allow, :priority)');
            $insert->execute([
                'container_definition_id' => (int) $definitionId,
                'rule_type' => 'ACCEPT_ALL',
                'reference_code' => '',
                'allow' => 1,
                'priority' => 100,
            ]);
        }
    }

    public function down(PDO $pdo): void
    {
        $definitionId = $pdo->query("SELECT id FROM container_definitions WHERE code = 'main_inventory_level_1' LIMIT 1")->fetchColumn();
        if ($definitionId === false) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO container_acceptance_rules (container_definition_id, rule_type, reference_code, allow, priority) VALUES (:container_definition_id, :rule_type, :reference_code, :allow, :priority)');
        $stmt->execute([
            'container_definition_id' => (int) $definitionId,
            'rule_type' => 'CONTAINER_BLOCK',
            'reference_code' => '',
            'allow' => 0,
            'priority' => 10,
        ]);
    }
};
