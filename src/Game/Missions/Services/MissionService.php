<?php

namespace App\Game\Missions\Services;

use App\Game\Seasons\Services\SeasonUnlockService;
use App\Support\DB;
use PDO;

class MissionService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?SeasonUnlockService $seasons = null
    ) {
        $this->seasons ??= new SeasonUnlockService($this->pdo);
    }

    /** @return list<array<string, mixed>> */
    public function listForPlayer(int $playerId, int $limit = 8): array
    {
        if (!$this->tableExists('mission_definitions')) {
            return [];
        }

        $stmt = $this->pdo()->query("SELECT * FROM mission_definitions WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $out = [];

        foreach ($rows as $row) {
            $mapped = $this->mapMission($playerId, $row);
            $out[] = $mapped;
            if (count($out) >= max(1, $limit) && ($mapped['status'] ?? '') !== 'completed') {
                // keep collecting completed for journal; limit only active tracker below
            }
        }

        return $out;
    }

    /** Tracker curto para HUD (ativas primeiro). */
    /** @return list<array<string, mixed>> */
    public function trackerForPlayer(int $playerId, int $limit = 3): array
    {
        $missions = $this->listForPlayer($playerId, 40);
        $active = array_values(array_filter(
            $missions,
            static fn (array $m): bool => ($m['status'] ?? '') !== 'completed'
        ));

        return array_slice($active, 0, max(1, $limit));
    }

    /**
     * Avalia e sincroniza progresso (idempotente).
     *
     * @return array<string, mixed>
     */
    public function syncMission(int $playerId, string $missionCode): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM mission_definitions WHERE code = :code AND status = :status LIMIT 1');
        $stmt->execute(['code' => $missionCode, 'status' => 'active']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Mission not found.');
        }

        $mapped = $this->mapMission($playerId, $row);
        $this->persistProgress($playerId, $missionCode, $mapped);

        if (($mapped['status'] ?? '') === 'completed') {
            $this->seasons->completeMission($playerId, $missionCode);
        }

        return $mapped;
    }

    public function syncAll(int $playerId): void
    {
        if (!$this->tableExists('mission_definitions')) {
            return;
        }

        $stmt = $this->pdo()->query("SELECT code FROM mission_definitions WHERE status = 'active'");
        $codes = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($codes as $code) {
            try {
                $this->syncMission($playerId, (string) $code);
            } catch (\Throwable) {
                // ignora missao individual quebrada
            }
        }
    }

    /**
     * Incrementa contador de kills para missões do tipo kills.
     */
    public function recordKill(int $playerId, int $amount = 1): void
    {
        if (!$this->tableExists('player_mission_progress')) {
            return;
        }

        $amount = max(1, $amount);
        foreach ($this->listForPlayer($playerId, 40) as $mission) {
            if (($mission['status'] ?? '') === 'completed') {
                continue;
            }
            $hasKillObjective = false;
            foreach ((array) ($mission['objectives'] ?? []) as $objective) {
                if (($objective['type'] ?? '') === 'kills') {
                    $hasKillObjective = true;
                    break;
                }
            }
            if (!$hasKillObjective) {
                continue;
            }

            $code = (string) ($mission['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $this->bumpProgressValue($playerId, $code, $amount);
            $this->syncMission($playerId, $code);
        }
    }

    /** @param array<string, mixed> $row */
    /** @return array<string, mixed> */
    private function mapMission(int $playerId, array $row): array
    {
        $code = (string) ($row['code'] ?? '');
        $objectives = $this->parseJson($row['objectives_json'] ?? null);
        $rewards = $this->parseJson($row['rewards_json'] ?? null);
        $progressRow = $this->progressRow($playerId, $code);
        $storedStatus = (string) ($progressRow['status'] ?? 'active');
        $storedValue = (int) ($progressRow['progress_value'] ?? 0);

        $objectiveStates = [];
        $allMet = true;
        $requiredTotal = 0;
        $currentTotal = 0;

        foreach ($objectives as $objective) {
            if (!is_array($objective)) {
                continue;
            }
            $type = (string) ($objective['type'] ?? '');
            $required = max(1, (int) ($objective['count'] ?? 1));
            $current = 0;

            if ($type === 'expedition_complete') {
                $biome = (string) ($objective['biome_code'] ?? '');
                $current = $this->completedExpeditions($playerId, $biome !== '' ? $biome : null);
            } elseif ($type === 'item_owned') {
                $current = $this->ownedItemQuantity($playerId, (string) ($objective['item_definition_code'] ?? ''));
            } elseif ($type === 'kills') {
                $current = max($storedValue, 0);
            } elseif ($type === 'craft_recipe') {
                $current = $this->craftCount($playerId, (string) ($objective['recipe_code'] ?? ''));
            }

            $met = $current >= $required;
            if (!$met) {
                $allMet = false;
            }
            $requiredTotal += $required;
            $currentTotal += min($current, $required);
            $objectiveStates[] = [
                'type' => $type,
                'required' => $required,
                'current' => $current,
                'met' => $met,
                'detail' => $objective,
            ];
        }

        $status = $allMet || $storedStatus === 'completed' ? 'completed' : 'active';
        $rewardsClaimed = !empty($progressRow['rewards_claimed_at']);

        return [
            'code' => $code,
            'name' => (string) ($row['name'] ?? $code),
            'summary' => (string) ($row['summary'] ?? ''),
            'mission_type' => (string) ($row['mission_type'] ?? 'main'),
            'season_code' => $row['season_code'] ?? null,
            'status' => $status,
            'objectives' => $objectiveStates,
            'rewards' => $rewards,
            'progress_ratio' => $requiredTotal > 0 ? round(min(1, $currentTotal / $requiredTotal), 4) : 0.0,
            'rewards_claimed' => $rewardsClaimed,
            'can_claim' => $status === 'completed' && !$rewardsClaimed && $rewards !== [],
        ];
    }

    /**
     * Reivindica recompensas de uma missao concluida.
     *
     * @return array<string, mixed>
     */
    public function claimRewards(int $playerId, string $missionCode): array
    {
        $mission = $this->syncMission($playerId, $missionCode);
        if (($mission['status'] ?? '') !== 'completed') {
            throw new \RuntimeException('Mission is not completed yet.');
        }
        if (($mission['rewards_claimed'] ?? false) === true) {
            throw new \RuntimeException('Mission rewards already claimed.');
        }

        $granted = [];
        foreach ((array) ($mission['rewards'] ?? []) as $reward) {
            if (!is_array($reward)) {
                continue;
            }
            $type = (string) ($reward['type'] ?? '');
            if ($type === 'item') {
                $code = (string) ($reward['item_definition_code'] ?? '');
                $qty = max(1, (int) ($reward['quantity'] ?? 1));
                if ($code === '') {
                    continue;
                }
                $grant = (new \App\Game\Inventory\Services\InventoryAutoPlacementService($this->pdo))->grantAndPlace(
                    \App\Game\Inventory\DTO\GrantItemRequest::fromArray($playerId, [
                        'item_definition_code' => $code,
                        'quantity' => $qty,
                    ])
                );
                $granted[] = ['type' => 'item', 'item_definition_code' => $code, 'quantity' => $qty, 'grant' => $grant];
            } elseif ($type === 'gold') {
                $amount = max(1, (int) ($reward['amount'] ?? 0));
                if ($amount <= 0) {
                    continue;
                }
                (new \App\Game\Market\Services\PlayerCurrencyService($this->pdo))->credit(
                    $playerId,
                    'gold',
                    $amount,
                    'MISSION_REWARD',
                    'mission',
                    $missionCode
                );
                $granted[] = ['type' => 'gold', 'amount' => $amount];
            } elseif ($type === 'unlock_hint') {
                $granted[] = [
                    'type' => 'unlock_hint',
                    'biome_code' => (string) ($reward['biome_code'] ?? ''),
                    'message' => 'Progresso de unlock atualizado.',
                ];
            }
        }

        if ($this->tableExists('player_mission_progress')) {
            $this->pdo()->prepare('UPDATE player_mission_progress
                SET rewards_claimed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE player_id = :player_id AND mission_code = :mission_code')
                ->execute([
                    'player_id' => $playerId,
                    'mission_code' => $missionCode,
                ]);
        }

        $mission = $this->syncMission($playerId, $missionCode);

        return [
            'mission' => $mission,
            'granted' => $granted,
        ];
    }

    /** @param array<string, mixed> $mapped */
    private function persistProgress(int $playerId, string $missionCode, array $mapped): void
    {
        if (!$this->tableExists('player_mission_progress')) {
            return;
        }

        $required = 1;
        $current = 0;
        foreach ((array) ($mapped['objectives'] ?? []) as $objective) {
            $required = max($required, (int) ($objective['required'] ?? 1));
            $current = max($current, (int) ($objective['current'] ?? 0));
        }

        $status = (string) ($mapped['status'] ?? 'active');
        $existing = $this->pdo()->prepare('SELECT id FROM player_mission_progress WHERE player_id = :player_id AND mission_code = :mission_code LIMIT 1');
        $existing->execute(['player_id' => $playerId, 'mission_code' => $missionCode]);
        $id = (int) $existing->fetchColumn();

        if ($id > 0) {
            $this->pdo()->prepare('UPDATE player_mission_progress
                SET status = :status,
                    progress_value = :progress_value,
                    required_value = :required_value,
                    completed_at = CASE WHEN :status_completed = \'completed\' THEN COALESCE(completed_at, CURRENT_TIMESTAMP) ELSE completed_at END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id')->execute([
                'id' => $id,
                'status' => $status,
                'progress_value' => $current,
                'required_value' => $required,
                'status_completed' => $status,
            ]);

            return;
        }

        $this->pdo()->prepare('INSERT INTO player_mission_progress
            (player_id, mission_code, status, progress_value, required_value, completed_at)
            VALUES (:player_id, :mission_code, :status, :progress_value, :required_value, :completed_at)')
            ->execute([
                'player_id' => $playerId,
                'mission_code' => $missionCode,
                'status' => $status,
                'progress_value' => $current,
                'required_value' => $required,
                'completed_at' => $status === 'completed' ? date('Y-m-d H:i:s') : null,
            ]);
    }

    private function bumpProgressValue(int $playerId, string $missionCode, int $amount): void
    {
        $row = $this->progressRow($playerId, $missionCode);
        if ($row === []) {
            $this->pdo()->prepare("INSERT INTO player_mission_progress
                (player_id, mission_code, status, progress_value, required_value)
                VALUES (:player_id, :mission_code, 'active', :progress_value, 1)")
                ->execute([
                    'player_id' => $playerId,
                    'mission_code' => $missionCode,
                    'progress_value' => $amount,
                ]);

            return;
        }

        $this->pdo()->prepare('UPDATE player_mission_progress
            SET progress_value = progress_value + :amount, updated_at = CURRENT_TIMESTAMP
            WHERE player_id = :player_id AND mission_code = :mission_code')
            ->execute([
                'amount' => $amount,
                'player_id' => $playerId,
                'mission_code' => $missionCode,
            ]);
    }

    /** @return array<string, mixed> */
    private function progressRow(int $playerId, string $missionCode): array
    {
        if (!$this->tableExists('player_mission_progress')) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT * FROM player_mission_progress WHERE player_id = :player_id AND mission_code = :mission_code LIMIT 1');
        $stmt->execute(['player_id' => $playerId, 'mission_code' => $missionCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function completedExpeditions(int $playerId, ?string $biomeCode): int
    {
        if (!$this->tableExists('expedition_instances')) {
            return 0;
        }

        if ($biomeCode === null || $biomeCode === '') {
            $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM expedition_instances WHERE player_id = :player_id AND status = 'completed'");
            $stmt->execute(['player_id' => $playerId]);

            return (int) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM expedition_instances
            WHERE player_id = :player_id AND status = 'completed'
              AND metadata_json LIKE :biome_like");
        $stmt->execute([
            'player_id' => $playerId,
            'biome_like' => '%"biome_code":"' . $biomeCode . '"%',
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function ownedItemQuantity(int $playerId, string $definitionCode): int
    {
        if ($definitionCode === '' || !$this->tableExists('item_instances')) {
            return 0;
        }

        $stmt = $this->pdo()->prepare('SELECT COALESCE(SUM(ii.quantity), 0)
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE ii.owner_player_id = :player_id AND id.code = :code');
        $stmt->execute(['player_id' => $playerId, 'code' => $definitionCode]);

        return (int) $stmt->fetchColumn();
    }

    private function craftCount(int $playerId, string $recipeCode): int
    {
        if ($recipeCode === '' || !$this->tableExists('player_craft_log')) {
            // Sem log de craft ainda: progresso 0 (objetivo fica pronto quando o log existir).
            return 0;
        }

        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM player_craft_log
            WHERE player_id = :player_id AND recipe_code = :recipe_code');
        $stmt->execute(['player_id' => $playerId, 'recipe_code' => $recipeCode]);

        return (int) $stmt->fetchColumn();
    }

    private function parseJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
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
