<?php

return new class {
    public function up(PDO $pdo): void
    {
        $existing = $pdo->prepare('SELECT id FROM item_action_definitions WHERE code = :code LIMIT 1');
        $existing->execute(['code' => 'UNEQUIP']);
        $actionId = $existing->fetchColumn();

        if ($actionId === false) {
            $insert = $pdo->prepare('INSERT INTO item_action_definitions (code, name, description, requires_confirmation, is_destructive, status) VALUES (:code, :name, :description, :requires_confirmation, :is_destructive, :status)');
            $insert->execute([
                'code' => 'UNEQUIP',
                'name' => 'Desequipar',
                'description' => 'Move o item equipado de volta para o inventario.',
                'requires_confirmation' => 0,
                'is_destructive' => 0,
                'status' => 'active',
            ]);
            $actionId = $pdo->lastInsertId();
        }

        $rule = $pdo->prepare('SELECT id FROM item_action_rules WHERE action_definition_id = :action_definition_id AND rule_type = :rule_type AND reference_code = :reference_code LIMIT 1');
        $rule->execute([
            'action_definition_id' => (int) $actionId,
            'rule_type' => 'IS_EQUIPPED',
            'reference_code' => '',
        ]);
        $ruleId = $rule->fetchColumn();

        if ($ruleId !== false) {
            $update = $pdo->prepare('UPDATE item_action_rules SET enabled = 1, priority = 10 WHERE id = :id');
            $update->execute(['id' => (int) $ruleId]);
            return;
        }

        $insertRule = $pdo->prepare('INSERT INTO item_action_rules (action_definition_id, rule_type, reference_code, enabled, priority) VALUES (:action_definition_id, :rule_type, :reference_code, :enabled, :priority)');
        $insertRule->execute([
            'action_definition_id' => (int) $actionId,
            'rule_type' => 'IS_EQUIPPED',
            'reference_code' => '',
            'enabled' => 1,
            'priority' => 10,
        ]);
    }

    public function down(PDO $pdo): void
    {
        $stmt = $pdo->prepare('SELECT id FROM item_action_definitions WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => 'UNEQUIP']);
        $actionId = $stmt->fetchColumn();
        if ($actionId === false) {
            return;
        }

        $deleteRules = $pdo->prepare('DELETE FROM item_action_rules WHERE action_definition_id = :action_definition_id');
        $deleteRules->execute(['action_definition_id' => (int) $actionId]);

        $deleteAction = $pdo->prepare('DELETE FROM item_action_definitions WHERE id = :id');
        $deleteAction->execute(['id' => (int) $actionId]);
    }
};
