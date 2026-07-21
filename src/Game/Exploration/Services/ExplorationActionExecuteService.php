<?php

namespace App\Game\Exploration\Services;

use App\Game\Exploration\ExplorationException;
use App\Game\Exploration\Support\ExplorationToolSupport;
use App\Game\Expeditions\Services\ExpeditionRunModifiersService;
use App\Game\Inventory\DTO\GrantItemRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryAutoPlacementService;
use App\Game\Player\Services\PlayerAttributeService;
use App\Game\Tools\Services\ToolDurabilityService;
use App\Game\Tools\Services\ToolMasteryService;
use App\Support\DB;
use PDO;

class ExplorationActionExecuteService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?InvestigableWorldService $world = null,
        private ?ExplorationToolSupport $tools = null,
        private ?ToolMasteryService $toolMastery = null,
        private ?PlayerAttributeService $attributes = null,
        private ?InventoryAutoPlacementService $inventoryGrant = null,
        private ?ExplorationLootRollService $lootRoll = null,
        private ?ExplorationRespawnService $respawn = null,
        private ?ExplorationExpeditionGateService $expeditionGate = null,
        private ?ExplorationContainerRiskService $containerRisk = null,
        private ?ExplorationPlayerModifiersService $modifiers = null,
        private ?ToolDurabilityService $toolDurability = null,
        private ?ExpeditionRunModifiersService $runModifiers = null
    ) {
        $this->world ??= new InvestigableWorldService($this->pdo);
        $this->tools ??= new ExplorationToolSupport($this->pdo);
        $this->toolMastery ??= new ToolMasteryService($this->pdo);
        $this->attributes ??= new PlayerAttributeService($this->pdo);
        $this->inventoryGrant ??= new InventoryAutoPlacementService($this->pdo);
        $this->lootRoll ??= new ExplorationLootRollService();
        $this->respawn ??= new ExplorationRespawnService($this->pdo);
        $this->expeditionGate ??= new ExplorationExpeditionGateService($this->pdo);
        $this->containerRisk ??= new ExplorationContainerRiskService();
        $this->modifiers ??= new ExplorationPlayerModifiersService($this->pdo);
        $this->toolDurability ??= new ToolDurabilityService($this->pdo);
        $this->runModifiers ??= new ExpeditionRunModifiersService($this->pdo);
    }

    public function execute(int $playerId, string $instancePublicId, string $actionCode, string $toolItemPublicId): array
    {
        $actionCode = strtolower(trim($actionCode));
        if ($actionCode === '' || $actionCode === 'analyze_magnifier') {
            throw new ExplorationException('EXPLORATION_ACTION_NOT_AVAILABLE', 'Use the analyze endpoint for magnifier actions.', 422, [
                'action_code' => $actionCode,
            ]);
        }

        $instance = $this->world->findOwnedInstance($playerId, $instancePublicId);
        if ($instance === null) {
            throw new ExplorationException('EXPLORATION_OBJECT_NOT_FOUND', 'Exploration object was not found.', 404, [
                'object_public_id' => $instancePublicId,
            ]);
        }

        $biomeCode = (string) ($instance['biome_code'] ?? '');
        $this->expeditionGate->assertCanExplore($playerId, $biomeCode);
        $this->world->assertObjectDiscovered($playerId, $instance);

        if ((string) ($instance['state'] ?? 'active') !== 'active') {
            throw new ExplorationException('EXPLORATION_OBJECT_UNAVAILABLE', 'Exploration object is not available.', 422, [
                'object_public_id' => $instancePublicId,
                'state' => (string) ($instance['state'] ?? ''),
            ]);
        }

        $definitionId = (int) $instance['definition_id'];
        $action = $this->findAction($definitionId, $actionCode);
        if ($action === null) {
            throw new ExplorationException('EXPLORATION_ACTION_NOT_AVAILABLE', 'This action is not available for the object.', 422, [
                'action_code' => $actionCode,
            ]);
        }

        $revealTier = max(0, (int) $instance['reveal_tier']);
        $minRevealTier = max(0, (int) $action['min_reveal_tier']);
        if ($revealTier < $minRevealTier) {
            throw new ExplorationException('EXPLORATION_ANALYSIS_REQUIRED', 'Analyze this object before using tools on it.', 422, [
                'reveal_tier' => $revealTier,
                'required_reveal_tier' => $minRevealTier,
            ]);
        }

        $requiredToolType = (string) ($action['required_tool_type'] ?? '');
        if ($requiredToolType === '') {
            throw new ExplorationException('EXPLORATION_ACTION_NOT_AVAILABLE', 'This action has no tool requirement configured.', 422);
        }

        $toolItem = $this->tools->resolveOwnedTool($playerId, $toolItemPublicId, $requiredToolType);
        $actionConfig = $this->parseJson($action['config_json'] ?? null);
        $lootTable = array_values($actionConfig['loot'] ?? []);
        if ($lootTable === []) {
            throw new ExplorationException('EXPLORATION_ACTION_NOT_AVAILABLE', 'This action has no loot configured.', 422);
        }

        $expeditionActive = $this->expeditionGate->hasActiveExpeditionInBiome($playerId, $biomeCode);
        $runMods = $this->runModifiers->forPlayer($playerId, $biomeCode);
        $lootMods = [
            'item_rarity_bonus' => (float) ($runMods['item_rarity_bonus'] ?? 0),
            'chest_find_chance' => (float) ($runMods['chest_find_chance'] ?? 0),
        ];
        $baseRolls = max(1, (int) ($actionConfig['rolls'] ?? 1));
        // Baús/containers: chest_find aumenta rolls base.
        if (str_contains(strtolower($actionCode), 'chest') || str_contains(strtolower($actionCode), 'lock') || str_contains(strtolower((string) ($action['required_tool_type'] ?? '')), 'lock')) {
            if ($lootMods['chest_find_chance'] >= 0.2) {
                $baseRolls += 1;
            }
        }
        $rolledLoot = $this->lootRoll->roll(
            $lootTable,
            $revealTier,
            $expeditionActive,
            $baseRolls,
            null,
            (float) ($runMods['expedition_loot_bonus'] ?? 0),
            $lootMods['item_rarity_bonus'],
            $lootMods['chest_find_chance']
        );

        if ($rolledLoot === []) {
            throw new ExplorationException('EXPLORATION_ACTION_NOT_AVAILABLE', 'Loot roll produced no rewards for the current reveal tier.', 422);
        }

        $lockpickingLevel = $this->attributeLevel($playerId, 'lockpicking');
        $riskOutcome = $this->containerRisk->resolve(
            $actionCode,
            $actionConfig,
            $lockpickingLevel,
            (float) ($runMods['trap_chance_reduction'] ?? 0)
        );
        if (($riskOutcome['trap_triggered'] ?? false) && ($riskOutcome['failed'] ?? false)) {
            $this->recordEvent(
                $playerId,
                (int) $instance['id'],
                $actionCode,
                (int) $toolItem['id'],
                $revealTier,
                $revealTier,
                [
                    'action_code' => $actionCode,
                    'state_after' => 'active',
                    'trap' => $riskOutcome,
                    'loot' => [],
                ]
            );

            throw new ExplorationException('EXPLORATION_TRAP_FAILED', (string) ($riskOutcome['message'] ?? 'A trap prevented this action.'), 422, [
                'trap_type' => $riskOutcome['trap_type'] ?? null,
                'action_code' => $actionCode,
            ]);
        }

        $rolledLoot = $this->containerRisk->applyLootPenalty($rolledLoot, $riskOutcome);
        if ($rolledLoot === []) {
            throw new ExplorationException('EXPLORATION_TRAP_FAILED', (string) ($riskOutcome['message'] ?? 'A trap destroyed the contents.'), 422, [
                'trap_type' => $riskOutcome['trap_type'] ?? null,
                'action_code' => $actionCode,
            ]);
        }

        $toolXp = max(0, (int) $action['xp_tool']);
        $attributeXp = max(0, (int) $action['xp_attribute']);
        if ($riskOutcome['trap_triggered'] ?? false) {
            $attributeXp = max(0, $attributeXp - (int) ($riskOutcome['xp_penalty'] ?? 0));
        }
        $attributeCode = (string) ($action['attribute_code'] ?? 'exploration');
        $explorationXp = (int) round($attributeXp * 0.5);

        $grantedLoot = [];
        foreach ($rolledLoot as $lootEntry) {
            $definitionCode = (string) ($lootEntry['item_definition_code'] ?? '');
            $quantity = max(1, (int) ($lootEntry['quantity'] ?? 1));
            if ($definitionCode === '') {
                continue;
            }

            try {
                $grant = $this->inventoryGrant->grantAndPlace(new GrantItemRequest(
                    $playerId,
                    $definitionCode,
                    $quantity,
                    null,
                    null,
                    null,
                    $expeditionActive ? null : false
                ));
            } catch (InventoryException $e) {
                throw new ExplorationException('EXPLORATION_LOOT_FAILED', $e->getMessage(), $e->status(), $e->errors());
            }

            $grantedLoot[] = [
                'item_definition_code' => $definitionCode,
                'quantity' => $quantity,
                'item_public_id' => $grant['item_public_id'] ?? null,
                'container_public_id' => $grant['container_public_id'] ?? null,
                'placed_in_expedition_carry' => strtolower((string) ($grant['container_definition_code'] ?? '')) === 'expedition_carry',
            ];
        }

        $toolWearAmount = max(1, (int) ($actionConfig['tool_wear'] ?? 1));
        $toolWear = $this->toolDurability->wear($playerId, (int) $toolItem['id'], $toolWearAmount);

        $toolResult = $this->toolMastery->grantXp(
            $playerId,
            (int) $toolItem['id'],
            $toolXp,
            'exploration',
            (string) $instance['public_id'],
            $actionCode,
            [
                'definition_code' => (string) ($instance['definition_code'] ?? ''),
                'loot_count' => count($grantedLoot),
            ]
        );

        $attributeResult = $attributeXp > 0
            ? $this->attributes->grantXp(
                $playerId,
                $attributeCode,
                $attributeXp,
                'exploration',
                (string) $instance['public_id'],
                $actionCode,
                ['definition_code' => (string) ($instance['definition_code'] ?? '')]
            )
            : ['updated' => false];

        $explorationResult = $explorationXp > 0
            ? $this->attributes->grantXp(
                $playerId,
                'exploration',
                $explorationXp,
                'exploration',
                (string) $instance['public_id'],
                $actionCode,
                ['definition_code' => (string) ($instance['definition_code'] ?? '')]
            )
            : ['updated' => false];

        $depleteOnSuccess = ($actionConfig['deplete_on_success'] ?? true) !== false;
        $stateAfter = (string) ($instance['state'] ?? 'active');
        $respawnMinutes = max(1, (int) ($actionConfig['respawn_minutes'] ?? $this->expeditionGate->biomeRules($biomeCode)['default_respawn_minutes'] ?? 15));
        if ($depleteOnSuccess) {
            $this->respawn->scheduleRespawn((int) $instance['id'], $playerId, $respawnMinutes);
            $stateAfter = 'depleted';
        }

        $outcome = [
            'action_code' => $actionCode,
            'action_label' => isset($actionConfig['action_label']) && is_string($actionConfig['action_label']) ? $actionConfig['action_label'] : null,
            'success_message' => isset($actionConfig['success_message']) && is_string($actionConfig['success_message']) ? $actionConfig['success_message'] : null,
            'detail_text' => isset($actionConfig['detail_text']) && is_string($actionConfig['detail_text']) ? $actionConfig['detail_text'] : null,
            'definition_code' => (string) ($instance['definition_code'] ?? ''),
            'object_name' => (string) ($instance['definition_name'] ?? ''),
            'state_after' => $stateAfter,
            'respawn_minutes' => $depleteOnSuccess ? $respawnMinutes : null,
            'expedition_bonus' => $expeditionActive,
            'loot' => $grantedLoot,
            'trap' => ($riskOutcome['trap_triggered'] ?? false) ? $riskOutcome : null,
            'tool_wear' => $toolWear,
            'rewards' => [
                'tool_mastery' => $toolResult,
                'attribute' => $attributeResult,
                'exploration' => $explorationResult,
            ],
        ];

        $this->recordEvent(
            $playerId,
            (int) $instance['id'],
            $actionCode,
            (int) $toolItem['id'],
            $revealTier,
            $revealTier,
            $outcome
        );

        return [
            'object' => [
                'public_id' => (string) $instance['public_id'],
                'definition_code' => (string) ($instance['definition_code'] ?? ''),
                'name' => (string) ($instance['definition_name'] ?? ''),
                'kind' => (string) ($instance['kind'] ?? ''),
                'reveal_tier' => $revealTier,
                'state' => $stateAfter,
            ],
            'action' => $outcome,
            'tool' => [
                'public_id' => (string) $toolItem['public_id'],
                'definition_code' => (string) ($toolItem['definition_code'] ?? ''),
                'tool_type' => (string) ($toolItem['tool_type'] ?? $requiredToolType),
                'durability' => [
                    'current' => $toolWear['current'] ?? ($toolItem['current_durability'] !== null ? (int) $toolItem['current_durability'] : null),
                    'max' => $toolWear['max'] ?? ($toolItem['max_durability'] !== null ? (int) $toolItem['max_durability'] : null),
                    'broken' => (bool) ($toolWear['broken'] ?? false),
                ],
            ],
        ];
    }

    private function attributeLevel(int $playerId, string $attributeCode): int
    {
        foreach ((new PlayerAttributeService($this->pdo))->listForPlayer($playerId) as $attribute) {
            if (($attribute['code'] ?? null) === $attributeCode) {
                return max(1, (int) ($attribute['level'] ?? 1));
            }
        }

        return 1;
    }

    private function findAction(int $definitionId, string $actionCode): ?array
    {
        if (!$this->tableExists('investigable_actions')) {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT *
            FROM investigable_actions
            WHERE definition_id = :definition_id AND action_code = :action_code AND is_active = 1
            LIMIT 1');
        $stmt->execute([
            'definition_id' => $definitionId,
            'action_code' => $actionCode,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function recordEvent(
        int $playerId,
        int $instanceId,
        string $actionCode,
        int $toolItemInstanceId,
        int $revealTierBefore,
        int $revealTierAfter,
        array $outcome
    ): void {
        if (!$this->tableExists('exploration_interaction_events')) {
            return;
        }

        $this->pdo()->prepare('INSERT INTO exploration_interaction_events (
            player_id, instance_id, action_code, tool_item_instance_id,
            reveal_tier_before, reveal_tier_after, outcome_json
        ) VALUES (
            :player_id, :instance_id, :action_code, :tool_item_instance_id,
            :reveal_tier_before, :reveal_tier_after, :outcome_json
        )')->execute([
            'player_id' => $playerId,
            'instance_id' => $instanceId,
            'action_code' => $actionCode,
            'tool_item_instance_id' => $toolItemInstanceId,
            'reveal_tier_before' => $revealTierBefore,
            'reveal_tier_after' => $revealTierAfter,
            'outcome_json' => json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    private function parseJson(mixed $value): array
    {
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
