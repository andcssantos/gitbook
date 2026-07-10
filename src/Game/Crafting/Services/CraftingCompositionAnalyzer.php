<?php

namespace App\Game\Crafting\Services;

use App\Game\Market\Services\PlayerCurrencyService;
use App\Utils\Config;

class CraftingCompositionAnalyzer
{
    public function __construct(
        private ?CraftingRecipeMatcherService $matcher = null,
        private ?CraftingCostService $costs = null,
        private ?PlayerCurrencyService $currencies = null
    ) {
        $this->matcher ??= new CraftingRecipeMatcherService();
        $this->costs ??= new CraftingCostService();
        $this->currencies ??= new PlayerCurrencyService();
    }

    /**
     * @param array<int, array<string, mixed>|null> $resolvedSlots
     */
    public function analyze(string $workspace, array $resolvedSlots, int $playerId = 0): array
    {
        $workspace = $this->normalizeWorkspace($workspace);
        $workspaceConfig = (array) Config::get("crafting.workspaces.{$workspace}", []);
        $filled = $this->filledSlots($resolvedSlots);
        $filledCount = count($filled);
        $synergy = $this->synergyForCount($filledCount);
        $connections = $this->connectionsForFilledSlots($resolvedSlots);
        $recipeMatch = $this->matcher->match($workspace, $resolvedSlots, $playerId);
        $bestRecipe = $recipeMatch['best_match']['recipe'] ?? null;
        $cost = $this->costs->calculate($workspace, $resolvedSlots, is_array($bestRecipe) ? $bestRecipe : null);
        $goldBalance = $playerId > 0 ? $this->currencies->balance($playerId, 'gold') : 0;
        $goldCost = (int) ($cost['gold'] ?? 0);
        $canAfford = $goldBalance >= $goldCost;
        $isCompatible = (bool) ($recipeMatch['is_compatible'] ?? false);
        $minSlots = (int) Config::get('crafting.min_filled_slots', 2);

        $canCraft = $filledCount >= $minSlots && $isCompatible && $canAfford;
        $reason = null;
        if ($filledCount < $minSlots) {
            $reason = 'Preencha pelo menos '.$minSlots.' pontas da estrela para iniciar a transmutacao.';
        } elseif (!$isCompatible) {
            $reason = 'Combinacao incompativel com receitas conhecidas.';
        } elseif (!$canAfford) {
            $reason = 'Ouro insuficiente para concluir a operacao ('.$goldCost.' G necessarios).';
        }

        $predictedOutput = $recipeMatch['predicted_output'] ?? null;
        if (!is_array($predictedOutput)) {
            $predictedOutput = [
                'definition_code' => '',
                'quality_bucket' => 'common',
                'name' => null,
                'description' => 'Nenhuma receita compativel encontrada.',
                'rarity_label' => 'Indefinido',
            ];
        }

        return [
            'workspace' => $workspace,
            'workspace_name' => (string) ($workspaceConfig['name'] ?? ucfirst($workspace)),
            'filled_slots' => $filledCount,
            'slot_count' => (int) Config::get('crafting.slot_count', 6),
            'synergy_level' => (int) ($synergy['level'] ?? 0),
            'synergy_label' => (string) ($synergy['label'] ?? 'Inerte'),
            'aura_color' => (string) ($workspaceConfig['aura_color'] ?? '#55c58a'),
            'connections' => $connections,
            'recipe_match' => [
                'is_compatible' => $isCompatible,
                'compatibility_label' => (string) ($recipeMatch['compatibility_label'] ?? 'Insuficiente'),
                'recipe_code' => $recipeMatch['recipe_code'] ?? null,
                'matches' => $recipeMatch['matches'] ?? [],
                'best_match' => $recipeMatch['best_match'] ?? null,
                'guaranteed_success' => (bool) ($recipeMatch['guaranteed_success'] ?? false),
            ],
            'gold_cost' => $goldCost,
            'gold_balance' => $goldBalance,
            'can_afford' => $canAfford,
            'cost_breakdown' => $cost['breakdown'] ?? [],
            'can_craft' => $canCraft,
            'reason' => $reason,
            'predicted_output' => $predictedOutput,
        ];
    }

    /**
     * @param array<int, array<string, mixed>|null> $resolvedSlots
     * @return array<int, array<string, mixed>>
     */
    private function filledSlots(array $resolvedSlots): array
    {
        $filled = [];
        foreach ($resolvedSlots as $index => $slot) {
            if (is_array($slot)) {
                $filled[(int) $index] = $slot;
            }
        }

        ksort($filled);

        return $filled;
    }

    /**
     * @param array<int, array<string, mixed>|null> $resolvedSlots
     * @return array<int, array{from:int,to:int}>
     */
    private function connectionsForFilledSlots(array $resolvedSlots): array
    {
        $slotCount = (int) Config::get('crafting.slot_count', 6);
        $connections = [];

        for ($index = 0; $index < $slotCount; $index += 1) {
            $next = ($index + 1) % $slotCount;
            if (!is_array($resolvedSlots[$index] ?? null) || !is_array($resolvedSlots[$next] ?? null)) {
                continue;
            }

            $connections[] = ['from' => $index, 'to' => $next];
        }

        return $connections;
    }

    private function normalizeWorkspace(string $workspace): string
    {
        $workspace = strtolower(trim($workspace));

        return array_key_exists($workspace, (array) Config::get('crafting.workspaces', [
            'forge' => [],
            'alchemy' => [],
        ]))
            ? $workspace
            : 'forge';
    }

    private function synergyForCount(int $filledCount): array
    {
        $configured = Config::get("crafting.synergy.{$filledCount}");
        if (is_array($configured)) {
            return $configured;
        }

        return match (true) {
            $filledCount >= 5 => ['level' => 3, 'label' => 'Ascensao'],
            $filledCount >= 3 => ['level' => 2, 'label' => 'Harmonia'],
            $filledCount >= 1 => ['level' => 1, 'label' => 'Faisca'],
            default => ['level' => 0, 'label' => 'Inerte'],
        };
    }
}
