<?php

namespace App\Game\Inventory\Services;

use App\Game\Expeditions\Services\ExpeditionStateService;
use App\Game\Inventory\InventoryException;
use PDO;

class ExpeditionCarryAccessService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function assertMoveAllowed(int $playerId, array $sourceContainer, array $targetContainer): void
    {
        if ($this->sameContainer($sourceContainer, $targetContainer)) {
            return;
        }

        if (!$this->isExpeditionCarry($targetContainer)) {
            return;
        }

        if ($this->hasActiveExpedition($playerId)) {
            return;
        }

        throw new InventoryException(
            'INVENTORY_EXPEDITION_CARRY_DEPOSIT_LOCKED',
            'Expedition Carry only accepts deposits while an expedition is active.',
            422
        );
    }

    public function bypassAcceptanceForMove(int $playerId, array $sourceContainer, array $targetContainer): bool
    {
        return !$this->sameContainer($sourceContainer, $targetContainer)
            && $this->isExpeditionCarry($targetContainer)
            && $this->hasActiveExpedition($playerId);
    }

    public function assertMergeAllowed(int $playerId, array $sourcePlacement, array $targetPlacement): void
    {
        if ($this->sameContainer($sourcePlacement, $targetPlacement)) {
            return;
        }

        if (!$this->isExpeditionCarry($targetPlacement)) {
            return;
        }

        if ($this->hasActiveExpedition($playerId)) {
            return;
        }

        throw new InventoryException(
            'INVENTORY_EXPEDITION_CARRY_DEPOSIT_LOCKED',
            'Expedition Carry only accepts stack deposits while an expedition is active.',
            422
        );
    }

    private function isExpeditionCarry(array $container): bool
    {
        return strtoupper((string) ($container['container_type'] ?? '')) === 'EXPEDITION_CARRY'
            || strtolower((string) ($container['definition_code'] ?? $container['container_definition_code'] ?? '')) === 'expedition_carry';
    }

    private function sameContainer(array $left, array $right): bool
    {
        $leftId = (int) ($left['id'] ?? $left['container_instance_id'] ?? 0);
        $rightId = (int) ($right['id'] ?? $right['container_instance_id'] ?? 0);

        return $leftId > 0 && $leftId === $rightId;
    }

    private function hasActiveExpedition(int $playerId): bool
    {
        if ((new ExpeditionStateService($this->pdo))->hasActiveForPlayer($playerId)) {
            return true;
        }

        // Campanha usa a mesma expedition carry entre fases (active / triagem de loot).
        return $this->hasActiveCampaignCarrySession($playerId);
    }

    private function hasActiveCampaignCarrySession(int $playerId): bool
    {
        if (!$this->tableExists('campaign_stage_runs')) {
            return false;
        }

        $stmt = $this->pdo()->prepare("SELECT 1
            FROM campaign_stage_runs
            WHERE player_id = :player_id
              AND status IN ('active', 'awaiting_loot')
            LIMIT 1");
        $stmt->execute(['player_id' => $playerId]);

        return (bool) $stmt->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        try {
            $driver = (string) $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
                $stmt->execute(['name' => $table]);
                return (bool) $stmt->fetchColumn();
            }

            $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name LIMIT 1');
            $stmt->execute(['name' => $table]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= \App\Support\DB::pdo();
    }
}
