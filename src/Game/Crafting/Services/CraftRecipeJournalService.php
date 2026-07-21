<?php

namespace App\Game\Crafting\Services;

use App\Support\DB;
use PDO;

class CraftRecipeJournalService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function listKnownForPlayer(int $playerId): array
    {
        $stmt = $this->pdo()->prepare("SELECT r.code, r.name, r.workspace_code, r.discovery, r.gold_fee, r.description, r.sort_order,
                d.visibility, d.discovered_at, f.player_id AS first_discoverer_player_id
            FROM craft_recipes r
            LEFT JOIN crafting_recipe_discoveries d ON d.recipe_code = r.code AND d.player_id = :player_id
            LEFT JOIN crafting_recipe_first_discoveries f ON f.recipe_code = r.code
            WHERE r.status = 'active' AND (r.discovery = 'public' OR d.id IS NOT NULL)
            ORDER BY r.workspace_code ASC, r.sort_order ASC, r.name ASC");
        $stmt->execute(['player_id' => $playerId]);

        $recipes = array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'workspace_code' => (string) $row['workspace_code'],
            'discovery' => (string) $row['discovery'],
            'gold_fee' => (int) $row['gold_fee'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'visibility' => $row['discovery'] === 'public' ? 'public' : (string) ($row['visibility'] ?? 'private'),
            'discovered_at' => $row['discovered_at'] !== null ? (string) $row['discovered_at'] : null,
            'can_share' => (int) ($row['first_discoverer_player_id'] ?? 0) === $playerId
                && (string) ($row['visibility'] ?? '') !== 'shared',
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return ['recipes' => $recipes];
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
