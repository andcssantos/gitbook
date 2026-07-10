<?php

namespace App\Game\Crafting\Services;

use App\Utils\Config;

class CraftingRecipeCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function recipesForWorkspace(string $workspace): array
    {
        $workspace = strtolower(trim($workspace));
        $recipes = (array) Config::get('crafting.recipes', []);

        return array_values(array_filter($recipes, fn (array $recipe): bool => strtolower((string) ($recipe['workspace'] ?? '')) === $workspace));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_values((array) Config::get('crafting.recipes', []));
    }

    public function findByCode(string $code): ?array
    {
        foreach ($this->all() as $recipe) {
            if ((string) ($recipe['code'] ?? '') === $code) {
                return $recipe;
            }
        }

        return null;
    }
}
