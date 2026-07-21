<?php

namespace App\Game\Expeditions\Services;

use App\Game\Exploration\Services\ExplorationBiomeCatalogService;
use App\Game\Seasons\Services\SeasonUnlockService;
use App\Support\DB;
use PDO;

/**
 * Avalia entry_requirements do bioma (hard/soft) no momento do start.
 */
class ExpeditionEntryRequirementService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?ExplorationBiomeCatalogService $catalog = null,
        private ?ExpeditionRunModifiersService $runModifiers = null,
        private ?SeasonUnlockService $seasons = null
    ) {
        $this->catalog ??= new ExplorationBiomeCatalogService($this->pdo);
        $this->runModifiers ??= new ExpeditionRunModifiersService($this->pdo);
        $this->seasons ??= new SeasonUnlockService($this->pdo);
    }

    /**
     * @return array{
     *   allowed: bool,
     *   mode: string,
     *   met: bool,
     *   requirements: list<array<string, mixed>>,
     *   missing: list<array<string, mixed>>,
     *   soft_penalties: array<string, mixed>
     * }
     */
    public function evaluate(int $playerId, string $biomeCode): array
    {
        $biomeCode = $this->catalog->normalizeBiomeCode($biomeCode);
        $biome = $this->catalog->biome($biomeCode);
        $rules = is_array($biome['entry_requirements'] ?? null) ? $biome['entry_requirements'] : [];

        if ($rules === []) {
            return [
                'allowed' => true,
                'mode' => 'none',
                'met' => true,
                'requirements' => [],
                'missing' => [],
                'soft_penalties' => [],
            ];
        }

        $mode = strtolower((string) ($rules['mode'] ?? 'hard'));
        if (!in_array($mode, ['hard', 'soft'], true)) {
            $mode = 'hard';
        }

        $conditions = array_values(array_filter(
            (array) ($rules['any'] ?? $rules['all'] ?? $rules['conditions'] ?? []),
            static fn (mixed $row): bool => is_array($row)
        ));
        $requireAll = array_key_exists('all', $rules) && !array_key_exists('any', $rules);

        $run = $this->runModifiers->forPlayer($playerId, $biomeCode);
        $stats = (array) ($run['stats_by_code'] ?? []);
        $buffCodes = [];
        foreach ((array) (($run['temporary_buffs']['active_buffs'] ?? []) ?: []) as $buff) {
            if (is_array($buff) && ($buff['code'] ?? '') !== '') {
                $buffCodes[] = (string) $buff['code'];
            }
        }

        $evaluated = [];
        $missing = [];
        foreach ($conditions as $condition) {
            $ok = $this->conditionMet($playerId, $condition, $stats, $buffCodes);
            $row = array_merge($condition, ['met' => $ok]);
            $evaluated[] = $row;
            if (!$ok) {
                $missing[] = $row;
            }
        }

        $met = $conditions === []
            ? true
            : ($requireAll
                ? count($missing) === 0
                : count($missing) < count($conditions));

        $softPenalties = [];
        if (!$met && $mode === 'soft') {
            $softPenalties = [
                'energy_cost_multiplier' => max(1.0, (float) ($rules['energy_cost_multiplier'] ?? 1.5)),
                'hazard_multiplier' => max(1.0, (float) ($rules['hazard_multiplier'] ?? 1.25)),
                'label' => (string) ($rules['soft_label'] ?? 'Desprotegido'),
            ];
        }

        $allowed = $met || $mode === 'soft';

        return [
            'allowed' => $allowed,
            'mode' => $mode,
            'met' => $met,
            'requirements' => $evaluated,
            'missing' => $missing,
            'soft_penalties' => $softPenalties,
            'logic' => $requireAll ? 'all' : 'any',
        ];
    }

    /**
     * @throws \RuntimeException
     * @return array<string, mixed>
     */
    public function assertCanEnter(int $playerId, string $biomeCode): array
    {
        $result = $this->evaluate($playerId, $biomeCode);
        if (($result['allowed'] ?? false) !== true) {
            $labels = array_map(
                static fn (array $row): string => (string) ($row['label'] ?? $row['type'] ?? 'requisito'),
                (array) ($result['missing'] ?? [])
            );
            throw new \RuntimeException(
                'Entry requirements not met: ' . (implode(', ', $labels) ?: 'blocked')
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, float|int> $stats
     * @param list<string> $buffCodes
     */
    private function conditionMet(int $playerId, array $condition, array $stats, array $buffCodes): bool
    {
        $type = (string) ($condition['type'] ?? '');
        return match ($type) {
            'item_owned' => $this->ownedQuantity($playerId, (string) ($condition['item_definition_code'] ?? ''))
                >= max(1, (int) ($condition['quantity'] ?? 1)),
            'item_equipped' => $this->isEquipped($playerId, (string) ($condition['item_definition_code'] ?? '')),
            'gear_stat_min' => (float) ($stats[(string) ($condition['stat_code'] ?? '')] ?? 0)
                >= (float) ($condition['min'] ?? 1),
            'buff_active' => in_array((string) ($condition['buff_code'] ?? ''), $buffCodes, true),
            'craft_recipe' => $this->seasons !== null && method_exists($this->seasons, 'evaluateBiome')
                ? $this->craftCount($playerId, (string) ($condition['recipe_code'] ?? '')) >= max(1, (int) ($condition['count'] ?? 1))
                : false,
            default => false,
        };
    }

    private function ownedQuantity(int $playerId, string $definitionCode): int
    {
        $definitionCode = trim($definitionCode);
        if ($definitionCode === '' || !$this->tableExists('item_instances') || !$this->tableExists('item_definitions')) {
            return 0;
        }

        $stmt = $this->pdo()->prepare('SELECT COALESCE(SUM(ii.quantity), 0)
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE ii.owner_player_id = :player_id AND id.code = :code');
        $stmt->execute(['player_id' => $playerId, 'code' => $definitionCode]);

        return (int) $stmt->fetchColumn();
    }

    private function isEquipped(int $playerId, string $definitionCode): bool
    {
        $definitionCode = trim($definitionCode);
        if ($definitionCode === '' || !$this->tableExists('player_equipment')) {
            return false;
        }

        $stmt = $this->pdo()->prepare('SELECT 1
            FROM player_equipment pe
            INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE pe.player_id = :player_id AND id.code = :code
            LIMIT 1');
        $stmt->execute(['player_id' => $playerId, 'code' => $definitionCode]);

        return (bool) $stmt->fetchColumn();
    }

    private function craftCount(int $playerId, string $recipeCode): int
    {
        $recipeCode = trim($recipeCode);
        if ($recipeCode === '' || !$this->tableExists('player_craft_log')) {
            return 0;
        }

        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM player_craft_log
            WHERE player_id = :player_id AND recipe_code = :recipe_code');
        $stmt->execute(['player_id' => $playerId, 'recipe_code' => $recipeCode]);

        return (int) $stmt->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
