<?php

namespace App\Game\Crafting\Services;

use App\Game\Crafting\Repositories\CraftRecipeCatalogRepository;
use App\Utils\Config;
use PDO;

class CraftingRecipeCatalog
{
    private CraftRecipeCatalogRepository $repository;
    private bool $preferDatabase;

    public function __construct(?PDO $pdo = null, ?CraftRecipeCatalogRepository $repository = null)
    {
        $this->repository = $repository ?? new CraftRecipeCatalogRepository($pdo);
        $this->preferDatabase = $this->repository->hasTables() && $this->repository->countRecipes() > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recipesForWorkspace(string $workspace): array
    {
        $workspace = strtolower(trim($workspace));

        return array_values(array_filter(
            $this->all(),
            fn (array $recipe): bool => strtolower((string) ($recipe['workspace'] ?? '')) === $workspace
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->preferDatabase) {
            return $this->repository->allActive();
        }

        return array_values((array) Config::get('crafting.recipes', []));
    }

    public function findByCode(string $code): ?array
    {
        if ($this->preferDatabase) {
            $fromDb = $this->repository->findByCode($code);
            if ($fromDb !== null) {
                return $fromDb;
            }
        }

        foreach (array_values((array) Config::get('crafting.recipes', [])) as $recipe) {
            if (!is_array($recipe)) {
                continue;
            }
            if ((string) ($recipe['code'] ?? '') === $code) {
                return $recipe;
            }
        }

        return null;
    }
}
