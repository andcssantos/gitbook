<?php

namespace App\Game\Campaign\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Equipment\Services\ExpeditionCarryCapacityService;
use App\Game\Inventory\DTO\GrantItemRequest;
use App\Game\Inventory\Services\InventoryAutoPlacementService;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Player\Services\PlayerAttributeService;
use App\Game\Player\Services\PlayerVitalsService;
use App\Support\DB;
use PDO;

class CampaignStageLootService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?CampaignStageRunService $runs = null,
        private ?InventoryStateService $inventory = null,
        private ?InventoryAutoPlacementService $grant = null,
        private ?ExpeditionCarryCapacityService $carryCapacity = null,
        private ?ItemInstanceRepository $items = null,
        private ?ContainerRepository $containers = null,
        private ?PlayerAttributeService $attributes = null,
        private ?CampaignProgressService $progress = null,
        private ?PlayerVitalsService $vitals = null
    ) {
        $this->pdo ??= DB::pdo();
        $this->runs ??= new CampaignStageRunService($this->pdo);
        $this->inventory ??= new InventoryStateService($this->pdo);
        $this->grant ??= new InventoryAutoPlacementService($this->pdo);
        $this->carryCapacity ??= new ExpeditionCarryCapacityService($this->pdo);
        $this->items ??= new ItemInstanceRepository($this->pdo);
        $this->containers ??= new ContainerRepository($this->pdo);
        $this->attributes ??= new PlayerAttributeService($this->pdo);
        $this->progress ??= new CampaignProgressService($this->pdo);
        $this->vitals ??= new PlayerVitalsService($this->pdo);
    }

    /** @return array<string, mixed> */
    public function state(int $playerId): array
    {
        $run = $this->runs->pendingLootForPlayer($playerId);
        if ($run === null) {
            return ['run' => null, 'staging_loot' => [], 'expedition_carry' => $this->mapCarry($playerId), 'scoreboard' => null];
        }

        $combat = is_array($run['combat'] ?? null) ? $run['combat'] : [];
        $staging = array_values(array_filter((array) ($combat['staging_loot'] ?? []), 'is_array'));

        return [
            'run' => $run,
            'staging_loot' => $staging,
            'expedition_carry' => $this->mapCarry($playerId),
            'scoreboard' => null,
        ];
    }

    /**
     * @param list<string> $takeStagingIds
     * @param list<string> $abandonPublicIds
     * @param list<array<string, mixed>> $takePlacements
     * @return array<string, mixed>
     */
    public function commit(int $playerId, array $takeStagingIds, array $abandonPublicIds = [], array $takePlacements = []): array
    {
        $run = $this->runs->pendingLootForPlayer($playerId);
        if ($run === null) {
            throw new \RuntimeException('Nenhum loot pendente para reivindicar.');
        }

        $combat = is_array($run['combat'] ?? null) ? $run['combat'] : [];
        if (!empty($combat['loot_committed'])) {
            // Idempotente: evita 422 em reenvio / estado stuck awaiting_loot.
            if ((string) ($run['status'] ?? '') === 'awaiting_loot') {
                $this->pdo()->prepare("UPDATE campaign_stage_runs SET
                    status = 'cleared',
                    ended_at = COALESCE(ended_at, CURRENT_TIMESTAMP),
                    updated_at = CURRENT_TIMESTAMP
                    WHERE public_id = :public_id AND player_id = :player_id
                ")->execute([
                    'public_id' => $run['public_id'],
                    'player_id' => $playerId,
                ]);
            }

            $scoreboard = is_array($combat['scoreboard'] ?? null) ? $combat['scoreboard'] : [
                'node_code' => (string) ($run['node_code'] ?? ''),
                'node_label' => (string) ($run['node_label'] ?? ''),
                'items_taken' => [],
                'items_left' => [],
                'failed' => [],
            ];

            return [
                'run' => null,
                'scoreboard' => $scoreboard,
                'expedition_carry' => $this->mapCarry($playerId),
            ];
        }

        $staging = array_values(array_filter((array) ($combat['staging_loot'] ?? []), 'is_array'));
        $stagingById = [];
        foreach ($staging as $item) {
            $sid = (string) ($item['staging_id'] ?? '');
            if ($sid !== '') {
                $stagingById[$sid] = $item;
            }
        }

        $placements = $this->normalizeTakePlacements($takePlacements, $takeStagingIds, $stagingById);
        $takeSet = [];
        foreach ($placements as $placement) {
            foreach ($placement['staging_ids'] as $sid) {
                $takeSet[$sid] = true;
            }
        }

        $taken = [];
        $left = [];
        $failed = [];
        $abandoned = [];

        foreach (array_values(array_unique(array_map('strval', $abandonPublicIds))) as $publicId) {
            if ($publicId === '') {
                continue;
            }
            try {
                $removed = $this->abandonCarryItem($playerId, $publicId);
                if ($removed !== null) {
                    $abandoned[] = $removed;
                }
            } catch (\Throwable $e) {
                $failed[] = [
                    'public_id' => $publicId,
                    'message' => $e->getMessage() ?: 'Nao foi possivel abandonar o item.',
                ];
            }
        }

        $this->carryCapacity->ensureBaselineForPlayer($playerId);

        foreach ($placements as $placement) {
            $ids = $placement['staging_ids'];
            $parts = [];
            $qty = 0;
            $code = '';
            $name = '';
            foreach ($ids as $sid) {
                $item = $stagingById[$sid] ?? null;
                if ($item === null) {
                    continue;
                }
                $parts[] = $item;
                $qty += max(1, (int) ($item['quantity'] ?? 1));
                $code = (string) ($item['definition_code'] ?? $code);
                $name = (string) ($item['name'] ?? $name);
            }
            if ($parts === [] || $code === '' || $qty < 1) {
                continue;
            }

            try {
                $this->grant->grantAtExact(
                    new GrantItemRequest($playerId, $code, $qty, null, null, null, true),
                    'expedition_carry',
                    (int) $placement['grid_x'],
                    (int) $placement['grid_y'],
                    max(1, (int) $placement['grid_w']),
                    max(1, (int) $placement['grid_h'])
                );
                $taken[] = [
                    'staging_ids' => $ids,
                    'definition_code' => $code,
                    'name' => $name !== '' ? $name : $code,
                    'quantity' => $qty,
                    'grid_x' => (int) $placement['grid_x'],
                    'grid_y' => (int) $placement['grid_y'],
                    'grid_w' => max(1, (int) $placement['grid_w']),
                    'grid_h' => max(1, (int) $placement['grid_h']),
                    'rotated' => (bool) $placement['rotated'],
                ];
            } catch (\Throwable $e) {
                foreach ($parts as $item) {
                    $left[] = $item;
                }
                $failed[] = [
                    'staging_ids' => $ids,
                    'message' => $e->getMessage() ?: 'Sem espaco na expedition bag.',
                ];
            }
        }

        foreach ($staging as $item) {
            $id = (string) ($item['staging_id'] ?? '');
            if ($id === '' || isset($takeSet[$id])) {
                continue;
            }
            $left[] = $item;
        }

        $seenItemCodes = [];
        foreach ($staging as $item) {
            $code = (string) ($item['definition_code'] ?? '');
            if ($code !== '') {
                $seenItemCodes[] = $code;
            }
        }
        foreach ($taken as $item) {
            $code = (string) ($item['definition_code'] ?? '');
            if ($code !== '') {
                $seenItemCodes[] = $code;
            }
        }
        $this->progress->discover($playerId, (int) ($run['node_id'] ?? 0), [], $seenItemCodes);

        $totals = is_array($combat['totals'] ?? null) ? $combat['totals'] : [];
        $durationMs = (int) ($combat['duration_ms'] ?? 0);
        $bestClearMs = $combat['best_clear_ms'] ?? null;
        $exploration = $this->explorationSnapshot($playerId);

        $scoreboard = [
            'node_code' => (string) ($run['node_code'] ?? ''),
            'node_label' => (string) ($run['node_label'] ?? ''),
            'duration_ms' => $durationMs,
            'duration_label' => $this->formatDuration($durationMs),
            'best_clear_ms' => $bestClearMs !== null ? (int) $bestClearMs : null,
            'best_clear_label' => $bestClearMs !== null ? $this->formatDuration((int) $bestClearMs) : null,
            'is_best' => (bool) ($combat['is_best'] ?? false),
            'gold' => (int) ($totals['gold'] ?? 0),
            'exploration_xp' => (int) ($totals['exploration_xp'] ?? 0),
            'exploration' => $exploration,
            'kills' => (int) ($totals['kills'] ?? 0),
            'items_taken' => $taken,
            'items_left' => $left,
            'items_abandoned' => $abandoned,
            'failed' => $failed,
        ];

        $combat['staging_loot'] = [];
        $combat['loot_committed'] = true;
        $combat['scoreboard'] = $scoreboard;

        $this->pdo()->prepare("UPDATE campaign_stage_runs SET
            status = 'cleared',
            combat_json = :combat,
            updated_at = CURRENT_TIMESTAMP
            WHERE public_id = :public_id AND player_id = :player_id
        ")->execute([
            'combat' => json_encode($combat, JSON_THROW_ON_ERROR),
            'public_id' => $run['public_id'],
            'player_id' => $playerId,
        ]);

        return [
            'run' => null,
            'scoreboard' => $scoreboard,
            'expedition_carry' => $this->mapCarry($playerId),
        ];
    }

    /** @return array<string, mixed>|null */
    private function abandonCarryItem(int $playerId, string $publicId): ?array
    {
        $locked = $this->items->findByPublicIdAndOwner($publicId, $playerId, true);
        if ($locked === null) {
            return null;
        }

        $carry = $this->containers->findInstanceForPlayer($playerId, 'expedition_carry');
        if ($carry === null) {
            throw new \RuntimeException('Expedition bag nao encontrada.');
        }

        $itemId = (int) ($locked['id'] ?? 0);
        $placement = $this->containers->findPlacementByItemId($itemId, true);
        if ($placement === null || (int) ($placement['container_instance_id'] ?? 0) !== (int) $carry['id']) {
            throw new \RuntimeException('Item nao esta na expedition bag.');
        }

        $snapshot = [
            'public_id' => $publicId,
            'name' => (string) ($locked['name'] ?? $locked['definition_code'] ?? 'item'),
            'definition_code' => (string) ($locked['definition_code'] ?? ''),
            'quantity' => (int) ($locked['quantity'] ?? 1),
        ];

        $this->containers->deletePlacementByItemId($itemId);
        $this->items->deleteById($itemId);

        return $snapshot;
    }

    /**
     * @param list<array<string, mixed>> $takePlacements
     * @param list<string> $takeStagingIds
     * @param array<string, array<string, mixed>> $stagingById
     * @return list<array{staging_ids:list<string>,grid_x:int,grid_y:int,grid_w:int,grid_h:int,rotated:bool}>
     */
    private function normalizeTakePlacements(array $takePlacements, array $takeStagingIds, array $stagingById): array
    {
        $out = [];
        foreach ($takePlacements as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ids = [];
            if (isset($row['staging_ids']) && is_array($row['staging_ids'])) {
                $ids = array_values(array_filter(array_map('strval', $row['staging_ids'])));
            } elseif (!empty($row['staging_id'])) {
                $ids = [(string) $row['staging_id']];
            }
            $ids = array_values(array_filter($ids, static fn (string $id): bool => isset($stagingById[$id])));
            if ($ids === []) {
                continue;
            }
            $sample = $stagingById[$ids[0]];
            // Ignora tentativa de grant em cima de item permanente (merge UI indevido).
            if (!empty($row['public_id'])) {
                continue;
            }
            $out[] = [
                'staging_ids' => $ids,
                'grid_x' => (int) ($row['grid_x'] ?? 0),
                'grid_y' => (int) ($row['grid_y'] ?? 0),
                'grid_w' => max(1, (int) ($row['grid_w'] ?? $sample['grid_w'] ?? 1)),
                'grid_h' => max(1, (int) ($row['grid_h'] ?? $sample['grid_h'] ?? 1)),
                'rotated' => (bool) ($row['rotated'] ?? false),
            ];
        }

        if ($out !== []) {
            return $this->dedupePlacementRects($out);
        }

        // Fallback legado: so IDs, auto-place no canto livre via grantAtExact em varredura.
        $x = 0;
        $y = 0;
        foreach (array_values(array_unique(array_map('strval', $takeStagingIds))) as $id) {
            if ($id === '' || !isset($stagingById[$id])) {
                continue;
            }
            $item = $stagingById[$id];
            $w = max(1, (int) ($item['grid_w'] ?? 1));
            $h = max(1, (int) ($item['grid_h'] ?? 1));
            $out[] = [
                'staging_ids' => [$id],
                'grid_x' => $x,
                'grid_y' => $y,
                'grid_w' => $w,
                'grid_h' => $h,
                'rotated' => false,
            ];
            $x += $w;
            if ($x >= 6) {
                $x = 0;
                $y += $h;
            }
        }

        return $this->dedupePlacementRects($out);
    }

    /**
     * Mantem a primeira colocacao quando ha overlap no mesmo batch.
     *
     * @param list<array{staging_ids:list<string>,grid_x:int,grid_y:int,grid_w:int,grid_h:int,rotated:bool}> $placements
     * @return list<array{staging_ids:list<string>,grid_x:int,grid_y:int,grid_w:int,grid_h:int,rotated:bool}>
     */
    private function dedupePlacementRects(array $placements): array
    {
        $occupied = [];
        $out = [];

        foreach ($placements as $placement) {
            $x = (int) $placement['grid_x'];
            $y = (int) $placement['grid_y'];
            $w = max(1, (int) $placement['grid_w']);
            $h = max(1, (int) $placement['grid_h']);
            $hits = false;
            for ($yy = $y; $yy < $y + $h; $yy++) {
                for ($xx = $x; $xx < $x + $w; $xx++) {
                    $key = $xx . ',' . $yy;
                    if (isset($occupied[$key])) {
                        $hits = true;
                        break 2;
                    }
                }
            }
            if ($hits) {
                continue;
            }
            for ($yy = $y; $yy < $y + $h; $yy++) {
                for ($xx = $x; $xx < $x + $w; $xx++) {
                    $occupied[$xx . ',' . $yy] = true;
                }
            }
            $out[] = $placement;
        }

        return $out;
    }

    /** @return array{level:int,xp:int,xp_next:int} */
    private function explorationSnapshot(int $playerId): array
    {
        try {
            $this->attributes->ensureDefaults($playerId);
            foreach ($this->attributes->listForPlayer($playerId) as $row) {
                if ((string) ($row['code'] ?? '') !== 'exploration') {
                    continue;
                }

                return [
                    'level' => (int) ($row['level'] ?? 1),
                    'xp' => (int) ($row['xp'] ?? 0),
                    'xp_next' => (int) ($row['xp_next'] ?? $row['xp_to_next'] ?? 0),
                ];
            }
        } catch (\Throwable) {
        }

        return ['level' => 1, 'xp' => 0, 'xp_next' => 0];
    }

    /** @return array<string, mixed> */
    private function mapCarry(int $playerId): array
    {
        try {
            $this->carryCapacity->ensureBaselineForPlayer($playerId);
            $state = $this->inventory->forPlayer($playerId);
            foreach ((array) ($state['containers'] ?? []) as $container) {
                if ((string) ($container['definition_code'] ?? '') !== 'expedition_carry') {
                    continue;
                }

                $cols = max(1, (int) ($container['grid']['columns'] ?? ExpeditionCarryCapacityService::BASELINE_COLUMNS));
                $rows = max(1, (int) ($container['grid']['rows'] ?? ExpeditionCarryCapacityService::BASELINE_ROWS));
                $penalties = $this->vitals->campaignSoftPenalties($playerId);
                $hungerLocked = min($cols - 1, max(0, (int) ($penalties['carry_locked_cols'] ?? 0)));
                $usableCols = max(1, $cols - $hungerLocked);

                $items = [];
                $occupied = 0;
                foreach ((array) ($container['items'] ?? []) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $placement = is_array($item['placement'] ?? null) ? $item['placement'] : [];
                    $w = max(1, (int) ($placement['grid_w'] ?? $item['definition']['grid_w'] ?? 1));
                    $h = max(1, (int) ($placement['grid_h'] ?? $item['definition']['grid_h'] ?? 1));
                    $occupied += $w * $h;
                    $items[] = [
                        'public_id' => (string) ($item['public_id'] ?? ''),
                        'name' => (string) ($item['item_name'] ?? $item['definition']['name'] ?? $item['definition']['code'] ?? 'item'),
                        'definition_code' => (string) ($item['definition']['code'] ?? ''),
                        'quantity' => (int) ($item['quantity'] ?? 1),
                        'grid_w' => $w,
                        'grid_h' => $h,
                        'grid_x' => (int) ($placement['grid_x'] ?? 0),
                        'grid_y' => (int) ($placement['grid_y'] ?? 0),
                        'rotated' => (bool) ($placement['rotated'] ?? false),
                    ];
                }

                return [
                    'public_id' => (string) ($container['public_id'] ?? ''),
                    'name' => (string) ($container['name'] ?? 'Expedition Carry'),
                    'grid_columns' => $usableCols,
                    'grid_rows' => $rows,
                    'capacity_cells' => $usableCols * $rows,
                    'occupied_cells' => $occupied,
                    'items' => $items,
                    'hunger_locked_cols' => $hungerLocked,
                    'full_grid_columns' => $cols,
                    'vital_notes' => array_values((array) ($penalties['notes'] ?? [])),
                ];
            }
        } catch (\Throwable) {
        }

        $penalties = $this->vitals->campaignSoftPenalties($playerId);
        $hungerLocked = (int) ($penalties['carry_locked_cols'] ?? 0);
        $cols = ExpeditionCarryCapacityService::BASELINE_COLUMNS;
        $usable = max(1, $cols - min($cols - 1, $hungerLocked));

        return [
            'public_id' => '',
            'name' => 'Bolsos',
            'grid_columns' => $usable,
            'grid_rows' => ExpeditionCarryCapacityService::BASELINE_ROWS,
            'capacity_cells' => $usable * ExpeditionCarryCapacityService::BASELINE_ROWS,
            'occupied_cells' => 0,
            'items' => [],
            'hunger_locked_cols' => min($cols - 1, $hungerLocked),
            'full_grid_columns' => $cols,
            'vital_notes' => array_values((array) ($penalties['notes'] ?? [])),
        ];
    }

    private function formatDuration(int $ms): string
    {
        $totalSec = max(0, (int) floor($ms / 1000));
        $min = (int) floor($totalSec / 60);
        $sec = $totalSec % 60;

        return sprintf('%d:%02d', $min, $sec);
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= DB::pdo();
    }
}
