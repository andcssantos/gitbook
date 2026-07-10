<?php

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec("UPDATE container_definitions SET grid_columns = 12 WHERE code = 'main_inventory_level_1'");

        $definitionId = $pdo->query("SELECT id FROM container_definitions WHERE code = 'main_inventory_level_1' LIMIT 1")->fetchColumn();
        if ($definitionId !== false) {
            $stmt = $pdo->prepare('UPDATE container_instances SET grid_columns = 12 WHERE container_definition_id = :definition_id');
            $stmt->execute(['definition_id' => (int) $definitionId]);
        }

        $pdo->exec("UPDATE container_definitions SET allow_container_items = 1 WHERE code = 'wooden_chest'");

        $chestDefinitionId = $pdo->query("SELECT id FROM container_definitions WHERE code = 'wooden_chest' LIMIT 1")->fetchColumn();
        if ($chestDefinitionId !== false) {
            $stmt = $pdo->prepare('DELETE FROM container_acceptance_rules WHERE container_definition_id = :definition_id AND rule_type = :rule_type');
            $stmt->execute([
                'definition_id' => (int) $chestDefinitionId,
                'rule_type' => 'CONTAINER_BLOCK',
            ]);
        }
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("UPDATE container_definitions SET grid_columns = 8 WHERE code = 'main_inventory_level_1'");

        $definitionId = $pdo->query("SELECT id FROM container_definitions WHERE code = 'main_inventory_level_1' LIMIT 1")->fetchColumn();
        if ($definitionId !== false) {
            $stmt = $pdo->prepare('UPDATE container_instances SET grid_columns = 8 WHERE container_definition_id = :definition_id');
            $stmt->execute(['definition_id' => (int) $definitionId]);
        }

        $pdo->exec("UPDATE container_definitions SET allow_container_items = 0 WHERE code = 'wooden_chest'");

        $chestDefinitionId = $pdo->query("SELECT id FROM container_definitions WHERE code = 'wooden_chest' LIMIT 1")->fetchColumn();
        if ($chestDefinitionId !== false) {
            $existing = $pdo->prepare('SELECT id FROM container_acceptance_rules WHERE container_definition_id = :definition_id AND rule_type = :rule_type LIMIT 1');
            $existing->execute([
                'definition_id' => (int) $chestDefinitionId,
                'rule_type' => 'CONTAINER_BLOCK',
            ]);

            if ($existing->fetchColumn() === false) {
                $insert = $pdo->prepare('INSERT INTO container_acceptance_rules (container_definition_id, rule_type, reference_code, allow, priority) VALUES (:definition_id, :rule_type, :reference_code, :allow, :priority)');
                $insert->execute([
                    'definition_id' => (int) $chestDefinitionId,
                    'rule_type' => 'CONTAINER_BLOCK',
                    'reference_code' => '',
                    'allow' => 0,
                    'priority' => 10,
                ]);
            }
        }
    }
};
