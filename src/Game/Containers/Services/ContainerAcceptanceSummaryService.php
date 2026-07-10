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
            return $this->buildSummary(
                label: 'Aceita tudo',
                allowsContainerItems: (bool) ($container['allow_container_items'] ?? false),
                blocksContainers: false,
                badges: [['icon' => '📦', 'label' => 'Todos', 'code' => 'all']],
                tone: 'all',
                tooltip: 'Aceita todos os tipos de item.',
                acceptsAll: true,
                allowedCategories: null
            );
        }

        $rules = $this->rules->listForContainerDefinition($definitionId);
        $blocksContainers = false;
        $hasAcceptAll = false;
        $specificLabels = [];
        $badges = [];

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
                $badge = $this->badgeForRule($rule);
                if ($badge !== null) {
                    $badges[] = $badge;
                    $specificLabels[] = $badge['label'];
                }
            }
        }

        $allowsContainerItems = (bool) ($container['allow_container_items'] ?? false);
        $label = 'Aceita tudo';
        $tone = 'all';
        $tooltip = 'Aceita todos os tipos de item.';

        if ($specificLabels !== [] && !$hasAcceptAll) {
            $label = implode(', ', array_values(array_unique(array_map(fn (array $badge): string => $badge['label'], $badges))));
            $tone = (string) ($badges[0]['code'] ?? 'filtered');
            $tooltip = 'Aceita: ' . implode(', ', array_values(array_unique($specificLabels))) . '.';
        } elseif ($blocksContainers && !$allowsContainerItems) {
            $label = 'Sem containers aninhados';
            $tone = 'restricted';
            $tooltip = 'Nao aceita baus, bags ou outros containers.';
            $badges = [['icon' => '🚫', 'label' => 'Sem containers', 'code' => 'restricted']];
        } elseif ($blocksContainers && $allowsContainerItems) {
            $label = 'Aceita bags (max 2 niveis)';
            $tone = 'containers';
            $tooltip = 'Aceita containers ate 2 niveis de profundidade.';
            $badges = [['icon' => '📦', 'label' => 'Containers', 'code' => 'containers']];
        } elseif ($allowsContainerItems) {
            $label = 'Aceita containers';
            $tone = 'containers';
            $tooltip = 'Aceita containers aninhados.';
            $badges = [['icon' => '📦', 'label' => 'Containers', 'code' => 'containers']];
        } elseif ($hasAcceptAll) {
            $badges = [['icon' => '📦', 'label' => 'Todos', 'code' => 'all']];
        }

        return $this->buildSummary(
            label: $label,
            allowsContainerItems: $allowsContainerItems,
            blocksContainers: $blocksContainers,
            badges: $badges,
            tone: $tone,
            tooltip: $tooltip,
            acceptsAll: $hasAcceptAll,
            allowedCategories: $this->allowedCategories($rules, $hasAcceptAll, $specificLabels)
        );
    }

    private function buildSummary(
        string $label,
        bool $allowsContainerItems,
        bool $blocksContainers,
        array $badges,
        string $tone,
        string $tooltip,
        bool $acceptsAll = true,
        ?array $allowedCategories = null
    ): array {
        return [
            'label' => $label,
            'allows_container_items' => $allowsContainerItems,
            'blocks_containers' => $blocksContainers,
            'badges' => $badges,
            'tone' => $tone,
            'tooltip' => $tooltip,
            'accepts_all' => $acceptsAll,
            'allowed_categories' => $allowedCategories,
        ];
    }

    /** @return list<string>|null */
    private function allowedCategories(array $rules, bool $hasAcceptAll, array $specificLabels): ?array
    {
        if ($hasAcceptAll || $specificLabels === []) {
            return null;
        }

        $categories = [];
        foreach ($rules as $rule) {
            if ((int) ($rule['allow'] ?? 0) !== 1) {
                continue;
            }

            $ruleType = (string) ($rule['rule_type'] ?? '');
            $reference = (string) ($rule['reference_code'] ?? '');

            if ($ruleType === 'ITEM_CATEGORY' && $reference !== '') {
                $categories[] = $reference;
            }

            if ($ruleType === 'CONSUMABLE_ONLY') {
                $categories[] = 'consumable';
            }

            if ($ruleType === 'CURRENCY_ONLY') {
                $categories[] = 'currency';
            }
        }

        $categories = array_values(array_unique($categories));

        return $categories === [] ? null : $categories;
    }

    private function badgeForRule(array $rule): ?array
    {
        $reference = (string) ($rule['reference_code'] ?? '');

        return match ((string) ($rule['rule_type'] ?? '')) {
            'ITEM_CATEGORY' => match ($reference) {
                'material' => ['icon' => '🪨', 'label' => 'Materiais', 'code' => 'material'],
                'currency' => ['icon' => '🪙', 'label' => 'Moedas', 'code' => 'currency'],
                'consumable' => ['icon' => '🧪', 'label' => 'Consumiveis', 'code' => 'consumable'],
                'tool' => ['icon' => '🔧', 'label' => 'Ferramentas', 'code' => 'tool'],
                'weapon' => ['icon' => '⚔', 'label' => 'Equipamentos', 'code' => 'equipment'],
                'armor' => ['icon' => '🛡', 'label' => 'Equipamentos', 'code' => 'equipment'],
                default => $reference !== '' ? ['icon' => '📄', 'label' => ucfirst($reference), 'code' => $reference] : null,
            },
            'CONSUMABLE_ONLY' => ['icon' => '🧪', 'label' => 'Consumiveis', 'code' => 'consumable'],
            'CURRENCY_ONLY' => ['icon' => '🪙', 'label' => 'Moedas', 'code' => 'currency'],
            'EQUIP_SLOT' => ['icon' => '⚔', 'label' => 'Equipamentos', 'code' => 'equipment'],
            default => null,
        };
    }
}
