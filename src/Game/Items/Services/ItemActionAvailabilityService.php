<?php

namespace App\Game\Items\Services;

use App\Game\Items\Repositories\ItemActionDefinitionRepository;
use App\Game\Items\Repositories\ItemActionRuleRepository;
use PDO;

class ItemActionAvailabilityService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ItemActionDefinitionRepository $definitions = null,
        private ?ItemActionRuleRepository $rules = null
    ) {
        $this->definitions ??= new ItemActionDefinitionRepository($this->pdo);
        $this->rules ??= new ItemActionRuleRepository($this->pdo);
    }

    public function listForItem(array $item): array
    {
        $actions = [];

        foreach ($this->definitions->listActive() as $definition) {
            if (!$this->isEnabledForItem($definition, $item)) {
                continue;
            }

            $actions[] = [
                'code' => (string) $definition['code'],
                'name' => (string) $definition['name'],
                'description' => $definition['description'] !== null ? (string) $definition['description'] : null,
                'requires_confirmation' => (bool) $definition['requires_confirmation'],
                'is_destructive' => (bool) $definition['is_destructive'],
            ];
        }

        return $actions;
    }

    public function isExecutable(string $actionCode, array $item): bool
    {
        $definition = $this->definitions->findActiveByCode($actionCode);
        if ($definition === null) {
            return false;
        }

        return $this->isEnabledForItem($definition, $item);
    }

    private function isEnabledForItem(array $definition, array $item): bool
    {
        $rules = $this->rules->listForActionDefinition((int) $definition['id']);
        if ($rules === []) {
            return false;
        }

        foreach ($rules as $rule) {
            if ((int) $rule['enabled'] !== 1) {
                continue;
            }

            if ($this->matches($rule, $item)) {
                return true;
            }
        }

        return false;
    }

    private function matches(array $rule, array $item): bool
    {
        $reference = (string) ($rule['reference_code'] ?? '');

        return match ((string) $rule['rule_type']) {
            'ALL_ITEMS' => true,
            'ITEM_CATEGORY' => (string) ($item['category_code'] ?? '') === $reference,
            'ITEM_DEFINITION' => (string) ($item['definition_code'] ?? '') === $reference,
            'IS_CONTAINER' => (int) ($item['is_container'] ?? 0) === 1,
            default => false,
        };
    }
}
