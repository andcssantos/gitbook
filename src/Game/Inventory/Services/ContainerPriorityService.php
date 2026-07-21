<?php

namespace App\Game\Inventory\Services;

use App\Game\Containers\Repositories\ContainerAcceptanceRuleRepository;
use App\Game\Containers\Services\ContainerAcceptanceService;

class ContainerPriorityService
{
    private const EXCLUDED_CONTAINER_TYPES = [
        'MARKET_ESCROW',
        'MARKET_DELIVERY',
        'EXPEDITION_CARRY',
    ];

    public function __construct(
        private ?ContainerAcceptanceService $acceptance = null,
        private ?ContainerAcceptanceRuleRepository $rules = null,
        private bool $preferExpeditionCarry = false
    ) {
        $this->acceptance ??= new ContainerAcceptanceService();
        $this->rules ??= new ContainerAcceptanceRuleRepository();
    }

    public function withPreferExpeditionCarry(bool $prefer): self
    {
        return new self($this->acceptance, $this->rules, $prefer);
    }

    public function isEligibleForAutoPlacement(array $container, array $item): bool
    {
        return $this->placementTier($container, $item) !== null;
    }

    public function compareForPlacement(array $containerA, array $containerB, array $item): int
    {
        $tierA = $this->placementTier($containerA, $item);
        $tierB = $this->placementTier($containerB, $item);

        if ($tierA === null && $tierB === null) {
            return 0;
        }

        if ($tierA === null) {
            return 1;
        }

        if ($tierB === null) {
            return -1;
        }

        $priorityA = $this->tierPriority($tierA);
        $priorityB = $this->tierPriority($tierB);

        if ($priorityA !== $priorityB) {
            return $priorityA <=> $priorityB;
        }

        $sortOrder = ((int) $containerA['sort_order']) <=> ((int) $containerB['sort_order']);
        if ($sortOrder !== 0) {
            return $sortOrder;
        }

        return ((int) $containerA['id']) <=> ((int) $containerB['id']);
    }

    public function compareForMerge(array $containerA, array $containerB): int
    {
        if ($this->preferExpeditionCarry) {
            $carryA = $this->isExpeditionCarryContainer($containerA);
            $carryB = $this->isExpeditionCarryContainer($containerB);
            if ($carryA && !$carryB) {
                return -1;
            }
            if (!$carryA && $carryB) {
                return 1;
            }
        }

        if ($this->isExcludedContainerType((string) $containerA['container_type']) && !$this->isExcludedContainerType((string) $containerB['container_type'])) {
            return 1;
        }

        if (!$this->isExcludedContainerType((string) $containerA['container_type']) && $this->isExcludedContainerType((string) $containerB['container_type'])) {
            return -1;
        }

        $sortOrder = ((int) $containerA['sort_order']) <=> ((int) $containerB['sort_order']);
        if ($sortOrder !== 0) {
            return $sortOrder;
        }

        return ((int) $containerA['id']) <=> ((int) $containerB['id']);
    }

    private function placementTier(array $container, array $item): ?string
    {
        if ($this->isExpeditionCarryContainer($container)) {
            if (!$this->preferExpeditionCarry) {
                return null;
            }

            if (!$this->acceptance->canAcceptItem($container, $item)) {
                return null;
            }

            return 'expedition_carry';
        }

        if ($this->isExcludedContainerType((string) $container['container_type'])) {
            return null;
        }

        if (!$this->acceptance->canAcceptItem($container, $item)) {
            return null;
        }

        if ($this->isSpecializedContainer($container, $item)) {
            return 'specialized';
        }

        if ((string) $container['container_type'] === 'MAIN_INVENTORY') {
            return 'main_inventory';
        }

        if ((string) $container['container_type'] === 'BACKPACK') {
            return $container['source_item_instance_id'] !== null ? 'backpack_equipped' : 'backpack';
        }

        return 'other';
    }

    private function isSpecializedContainer(array $container, array $item): bool
    {
        $rules = $this->rules->listForContainerDefinition((int) $container['container_definition_id']);
        $hasAcceptAll = false;
        $hasSpecificAllowMatch = false;

        foreach ($rules as $rule) {
            if ((int) $rule['allow'] !== 1) {
                continue;
            }

            $ruleType = (string) $rule['rule_type'];
            if ($ruleType === 'ACCEPT_ALL') {
                $hasAcceptAll = true;
                continue;
            }

            if ($ruleType === 'CONTAINER_BLOCK') {
                continue;
            }

            if ($this->ruleMatchesItem($rule, $item)) {
                $hasSpecificAllowMatch = true;
            }
        }

        return $hasSpecificAllowMatch && !$hasAcceptAll;
    }

    private function ruleMatchesItem(array $rule, array $item): bool
    {
        $reference = (string) ($rule['reference_code'] ?? '');

        return match ((string) $rule['rule_type']) {
            'ITEM_CATEGORY' => (string) ($item['category_code'] ?? '') === $reference,
            'ITEM_DEFINITION' => (string) ($item['definition_code'] ?? '') === $reference,
            'MATERIAL_FAMILY' => (string) ($item['material_family_code'] ?? '') === $reference,
            'EQUIP_SLOT' => (string) ($item['equip_slot_code'] ?? '') === $reference,
            'CURRENCY_ONLY' => (string) ($item['category_code'] ?? '') === 'currency',
            'CONSUMABLE_ONLY' => (string) ($item['category_code'] ?? '') === 'consumable',
            default => false,
        };
    }

    private function tierPriority(string $tier): int
    {
        return match ($tier) {
            'expedition_carry' => 5,
            'specialized' => 10,
            'main_inventory' => 20,
            'backpack_equipped' => 30,
            'backpack' => 40,
            default => 50,
        };
    }

    private function isExpeditionCarryContainer(array $container): bool
    {
        return strtoupper((string) ($container['container_type'] ?? '')) === 'EXPEDITION_CARRY'
            || strtolower((string) ($container['definition_code'] ?? $container['container_definition_code'] ?? '')) === 'expedition_carry';
    }

    private function isExcludedContainerType(string $containerType): bool
    {
        return in_array($containerType, self::EXCLUDED_CONTAINER_TYPES, true);
    }
}
