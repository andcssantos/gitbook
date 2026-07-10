<?php

return new class {
    public function up(PDO $pdo): void
    {
        $this->enableSell($pdo);
        $this->addListMarket($pdo);
    }

    public function down(PDO $pdo): void
    {
        $pdo->prepare("UPDATE item_action_rules SET enabled = 0 WHERE action_definition_id IN (SELECT id FROM item_action_definitions WHERE code = 'SELL')")
            ->execute();
        $pdo->prepare("DELETE FROM item_action_rules WHERE action_definition_id IN (SELECT id FROM item_action_definitions WHERE code = 'LIST_MARKET')")
            ->execute();
        $pdo->prepare("DELETE FROM item_action_definitions WHERE code = 'LIST_MARKET'")->execute();
    }

    private function enableSell(PDO $pdo): void
    {
        $actionId = $this->actionId($pdo, 'SELL');
        if ($actionId <= 0) {
            return;
        }

        $existing = $pdo->prepare('SELECT id FROM item_action_rules WHERE action_definition_id = :action_definition_id AND rule_type = :rule_type AND reference_code = :reference_code LIMIT 1');
        $existing->execute([
            'action_definition_id' => $actionId,
            'rule_type' => 'ALL_ITEMS',
            'reference_code' => '',
        ]);
        $ruleId = $existing->fetchColumn();

        if ($ruleId) {
            $pdo->prepare('UPDATE item_action_rules SET enabled = 1 WHERE id = :id')->execute(['id' => $ruleId]);
            return;
        }

        $pdo->prepare('INSERT INTO item_action_rules (action_definition_id, rule_type, reference_code, enabled, priority) VALUES (:action_definition_id, :rule_type, :reference_code, 1, 100)')
            ->execute([
                'action_definition_id' => $actionId,
                'rule_type' => 'ALL_ITEMS',
                'reference_code' => '',
            ]);
    }

    private function addListMarket(PDO $pdo): void
    {
        $existing = $pdo->prepare('SELECT id FROM item_action_definitions WHERE code = :code LIMIT 1');
        $existing->execute(['code' => 'LIST_MARKET']);
        $actionId = $existing->fetchColumn();

        if (!$actionId) {
            $pdo->prepare('INSERT INTO item_action_definitions (code, name, description, requires_confirmation, is_destructive, status) VALUES (:code, :name, :description, :requires_confirmation, :is_destructive, :status)')
                ->execute([
                    'code' => 'LIST_MARKET',
                    'name' => 'Anunciar no mercado',
                    'description' => 'Lista o item no marketplace P2P por Eter Cristal.',
                    'requires_confirmation' => 1,
                    'is_destructive' => 0,
                    'status' => 'active',
                ]);
            $actionId = (int) $pdo->lastInsertId();
        }

        $rule = $pdo->prepare('SELECT id FROM item_action_rules WHERE action_definition_id = :action_definition_id AND rule_type = :rule_type AND reference_code = :reference_code LIMIT 1');
        $rule->execute([
            'action_definition_id' => $actionId,
            'rule_type' => 'ALL_ITEMS',
            'reference_code' => '',
        ]);

        if (!$rule->fetchColumn()) {
            $pdo->prepare('INSERT INTO item_action_rules (action_definition_id, rule_type, reference_code, enabled, priority) VALUES (:action_definition_id, :rule_type, :reference_code, 1, 100)')
                ->execute([
                    'action_definition_id' => $actionId,
                    'rule_type' => 'ALL_ITEMS',
                    'reference_code' => '',
                ]);
        }
    }

    private function actionId(PDO $pdo, string $code): int
    {
        $stmt = $pdo->prepare('SELECT id FROM item_action_definitions WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);

        return (int) $stmt->fetchColumn();
    }
};
