<?php

namespace App\Game\Crafting\Services;

use App\Support\DB;
use PDO;

class CraftingBlueprintService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?CraftingRecipeCatalog $catalog = null
    ) {
        $this->catalog ??= new CraftingRecipeCatalog();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function registerDiscovery(int $playerId, string $recipeCode): ?array
    {
        if ($recipeCode === '' || !$this->tablesExist()) {
            return null;
        }

        $recipe = $this->catalog->findByCode($recipeCode);
        if ($recipe === null || (string) ($recipe['discovery'] ?? 'public') === 'public') {
            return null;
        }

        $this->upsertPlayerDiscovery($playerId, $recipeCode, 'private');

        $isFirstOnServer = $this->markFirstDiscoveryIfNeeded($playerId, $recipeCode);

        return [
            'recipe_code' => $recipeCode,
            'recipe_name' => (string) ($recipe['name'] ?? $recipeCode),
            'is_first_on_server' => $isFirstOnServer,
            'visibility' => 'private',
            'can_share' => $isFirstOnServer,
        ];
    }

    public function shareRecipe(int $playerId, string $recipeCode): void
    {
        if (!$this->tablesExist()) {
            return;
        }

        $stmt = $this->pdo()->prepare("UPDATE crafting_recipe_discoveries SET visibility = 'shared'
            WHERE player_id = :player_id AND recipe_code = :recipe_code");
        $stmt->execute([
            'player_id' => $playerId,
            'recipe_code' => $recipeCode,
        ]);
    }

    private function upsertPlayerDiscovery(int $playerId, string $recipeCode, string $visibility): void
    {
        if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo()->prepare('INSERT INTO crafting_recipe_discoveries (recipe_code, player_id, visibility)
                VALUES (:recipe_code, :player_id, :visibility)
                ON CONFLICT(recipe_code, player_id) DO NOTHING');
        } else {
            $stmt = $this->pdo()->prepare('INSERT IGNORE INTO crafting_recipe_discoveries (recipe_code, player_id, visibility)
                VALUES (:recipe_code, :player_id, :visibility)');
        }

        $stmt->execute([
            'recipe_code' => $recipeCode,
            'player_id' => $playerId,
            'visibility' => $visibility,
        ]);
    }

    private function markFirstDiscoveryIfNeeded(int $playerId, string $recipeCode): bool
    {
        $existing = $this->pdo()->prepare('SELECT player_id FROM crafting_recipe_first_discoveries WHERE recipe_code = :recipe_code LIMIT 1');
        $existing->execute(['recipe_code' => $recipeCode]);
        if ($existing->fetchColumn() !== false) {
            return false;
        }

        if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo()->prepare('INSERT INTO crafting_recipe_first_discoveries (recipe_code, player_id)
                VALUES (:recipe_code, :player_id)
                ON CONFLICT(recipe_code) DO NOTHING');
        } else {
            $stmt = $this->pdo()->prepare('INSERT IGNORE INTO crafting_recipe_first_discoveries (recipe_code, player_id)
                VALUES (:recipe_code, :player_id)');
        }

        $stmt->execute([
            'recipe_code' => $recipeCode,
            'player_id' => $playerId,
        ]);

        return (int) $this->pdo()->lastInsertId() > 0 || $stmt->rowCount() > 0;
    }

    private function tablesExist(): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'crafting_recipe_discoveries' LIMIT 1");
            $stmt->execute();

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => 'crafting_recipe_discoveries']);

        return (bool) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
