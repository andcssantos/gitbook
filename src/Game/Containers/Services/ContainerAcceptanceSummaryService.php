<?php

namespace App\Game\Containers\Services;

use App\Game\Containers\Repositories\ContainerAcceptanceRuleRepository;
use PDO;

class ContainerAcceptanceSummaryService
{
    public function __construct(
        private ?ContainerAcceptanceRuleRepository $rules = null,
        private ?PDO $pdo = null
    ) {
        $this->rules ??= new ContainerAcceptanceRuleRepository($pdo);
    }

    public function forContainer(array $container): array
    {
        $definitionId = (int) ($container['container_definition_id'] ?? 0);
        if ($definitionId <= 0) {
            return [
                'label' => 'Aceita tudo',
                'allows_container_items' => (bool) ($container['allow_container_items'] ?? false),
                'blocks_containers' => false,
            ];
        }

        $rules = $this->rules->listForContainerDefinition($definitionId);
        $blocksContainers = false;
        $hasAcceptAll = false;
        $specificLabels = [];

        foreach ($rules as $rule) {
            $ruleType = (string) ($rule['rule_type'] ?? '');
            $allows = (int) ($rule['allow'] ?? 0) === 1;

            if ($ruleType === 'CONTAINER_BLOCK' && !$allows) {
                $blocksContainers = true;
                continue;
            }

            if ($ruleType === 'ACCEPT_ALL' && $allows) {
                $hasAcceptAll = true;
                continue;
            }

            if ($allows) {
                $label = $this->labelForRule($rule);
                if ($label !== null) {
                    $specificLabels[] = $label;
                }
            }
        }

        $allowsContainerItems = (bool) ($container['allow_container_items'] ?? false);
        $label = 'Aceita tudo';

        if ($specificLabels !== [] && !$hasAcceptAll) {
            $label = implode(', ', array_values(array_unique($specificLabels)));
        } elseif ($blocksContainers && !$allowsContainerItems) {
            $label = 'Sem containers aninhados';
        } elseif ($blocksContainers && $allowsContainerItems) {
            $label = 'Containers limitados (max 2 niveis)';
        } elseif ($allowsContainerItems) {
            $label = 'Aceita containers';
        }

        return [
            'label' => $label,
            'allows_container_items' => $allowsContainerItems,
            'blocks_containers' => $blocksContainers,
        ];
    }

    private function labelForRule(array $rule): ?string
    {
        $reference = (string) ($rule['reference_code'] ?? '');

        return match ((string) ($rule['rule_type'] ?? '')) {
            'ITEM_CATEGORY' => match ($reference) {
                'material' => 'Materiais',
                'currency' => 'Moedas',
                'consumable' => 'Consumiveis',
                'tool' => 'Ferramentas',
                'weapon' => 'Armas',
                'armor' => 'Armaduras',
                default => $reference !== '' ? ucfirst($reference) : null,
            },
            'CONSUMABLE_ONLY' => 'Consumiveis',
            'CURRENCY_ONLY' => 'Moedas',
            'EQUIP_SLOT' => $reference !== '' ? "Slot {$reference}" : null,
            default => null,
        };
    }
}
