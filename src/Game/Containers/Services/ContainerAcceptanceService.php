<?php

namespace App\Game\Containers\Services;

use App\Game\Containers\Repositories\ContainerAcceptanceRuleRepository;
use PDO;

class ContainerAcceptanceService
{
    public function __construct(
        private ?ContainerAcceptanceRuleRepository $rules = null,
        ?PDO $pdo = null
    ) {
        $this->rules ??= new ContainerAcceptanceRuleRepository($pdo);
    }

    public function canAcceptItem(array $container, array $item): bool
    {
        return $this->rejectionCode($container, $item) === null;
    }

    public function rejectionCode(array $container, array $item): ?string
    {
        $rules = $this->rules->listForContainerDefinition((int) $container['container_definition_id']);
        if ($rules === []) {
            return null;
        }

        foreach ($rules as $rule) {
            if (!$this->matches($rule, $item)) {
                continue;
            }

            if ((int) $rule['allow'] === 1) {
                return null;
            }

            return (string) $rule['rule_type'] === 'CONTAINER_BLOCK'
                ? 'INVENTORY_CONTAINER_ITEM_BLOCKED'
                : 'INVENTORY_CONTAINER_REJECTS_ITEM';
        }

        return 'INVENTORY_CONTAINER_REJECTS_ITEM';
    }

    private function matches(array $rule, array $item): bool
    {
        $reference = (string) ($rule['reference_code'] ?? '');

        return match ((string) $rule['rule_type']) {
            'ACCEPT_ALL' => true,
            'ITEM_CATEGORY' => (string) ($item['category_code'] ?? '') === $reference,
            'ITEM_DEFINITION' => (string) ($item['definition_code'] ?? '') === $reference,
            'MATERIAL_FAMILY' => (string) ($item['material_family_code'] ?? '') === $reference,
            'EQUIP_SLOT' => (string) ($item['equip_slot_code'] ?? '') === $reference,
            'CURRENCY_ONLY' => (string) ($item['category_code'] ?? '') === 'currency',
            'CONSUMABLE_ONLY' => (string) ($item['category_code'] ?? '') === 'consumable',
            'CONTAINER_BLOCK' => (int) ($item['is_container'] ?? 0) === 1,
            'ITEM_TAG' => $this->hasTag($item, $reference),
            default => false,
        };
    }

    private function hasTag(array $item, string $tag): bool
    {
        $baseConfig = $item['base_config'] ?? null;
        if (!is_string($baseConfig) || $baseConfig === '') {
            return false;
        }

        $decoded = json_decode($baseConfig, true);
        if (!is_array($decoded)) {
            return false;
        }

        $tags = $decoded['tags'] ?? [];
        return is_array($tags) && in_array($tag, array_map('strval', $tags), true);
    }
}
