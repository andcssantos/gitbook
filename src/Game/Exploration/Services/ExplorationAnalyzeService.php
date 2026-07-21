<?php

namespace App\Game\Exploration\Services;

use App\Game\Exploration\ExplorationException;
use App\Game\Player\Services\PlayerAttributeService;
use App\Game\Tools\Services\ToolDurabilityService;
use App\Game\Tools\Services\ToolMasteryService;
use App\Support\DB;
use PDO;

class ExplorationAnalyzeService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?InvestigableWorldService $world = null,
        private ?ToolMasteryService $toolMastery = null,
        private ?PlayerAttributeService $attributes = null,
        private ?ExplorationExpeditionGateService $expeditionGate = null,
        private ?ToolDurabilityService $toolDurability = null
    ) {
        $this->world ??= new InvestigableWorldService($this->pdo);
        $this->toolMastery ??= new ToolMasteryService($this->pdo);
        $this->attributes ??= new PlayerAttributeService($this->pdo);
        $this->expeditionGate ??= new ExplorationExpeditionGateService($this->pdo);
        $this->toolDurability ??= new ToolDurabilityService($this->pdo);
    }

    public function analyzeMagnifier(int $playerId, string $instancePublicId, string $toolItemPublicId): array
    {
        $instance = $this->world->findOwnedInstance($playerId, $instancePublicId);
        if ($instance === null) {
            throw new ExplorationException('EXPLORATION_OBJECT_NOT_FOUND', 'Exploration object was not found.', 404, [
                'object_public_id' => $instancePublicId,
            ]);
        }

        $this->expeditionGate->assertCanExplore($playerId, (string) ($instance['biome_code'] ?? ''));
        $this->world->assertObjectDiscovered($playerId, $instance);

        if ((string) ($instance['state'] ?? 'active') !== 'active') {
            throw new ExplorationException('EXPLORATION_OBJECT_UNAVAILABLE', 'Exploration object is not available.', 422, [
                'object_public_id' => $instancePublicId,
                'state' => (string) ($instance['state'] ?? ''),
            ]);
        }

        $definitionId = (int) $instance['definition_id'];
        $action = $this->findAnalyzeAction($definitionId);
        if ($action === null) {
            throw new ExplorationException('EXPLORATION_ACTION_NOT_AVAILABLE', 'Analyze action is not available for this object.', 422, [
                'action_code' => 'analyze_magnifier',
            ]);
        }

        $config = $this->parseJson($instance['config_json'] ?? null);
        $tiers = $config['analyze_tiers'] ?? [];
        $maxTier = count($tiers);
        $actionMaxTier = $action['max_reveal_tier'] !== null ? (int) $action['max_reveal_tier'] : $maxTier;
        $revealTierBefore = max(0, (int) $instance['reveal_tier']);

        if ($maxTier <= 0) {
            throw new ExplorationException('EXPLORATION_ACTION_NOT_AVAILABLE', 'This object has no analysis tiers configured.', 422);
        }

        if ($revealTierBefore >= $actionMaxTier) {
            throw new ExplorationException('EXPLORATION_ALREADY_FULLY_ANALYZED', 'This object was already fully analyzed.', 422, [
                'reveal_tier' => $revealTierBefore,
                'max_tier' => $actionMaxTier,
            ]);
        }

        $toolItem = $this->resolveOwnedTool($playerId, $toolItemPublicId, (string) ($action['required_tool_type'] ?? 'magnifier'));

        $revealTierAfter = $revealTierBefore + 1;
        $tierPayload = $tiers[$revealTierAfter - 1] ?? [];

        $toolXp = max(0, (int) $action['xp_tool']);
        $attributeXp = max(0, (int) $action['xp_attribute']);
        $attributeCode = (string) ($action['attribute_code'] ?? 'investigation');
        $explorationXp = (int) round($attributeXp * 0.65);

        $toolResult = $this->toolMastery->grantXp(
            $playerId,
            (int) $toolItem['id'],
            $toolXp,
            'exploration',
            (string) $instance['public_id'],
            'analyze_magnifier',
            [
                'definition_code' => (string) ($instance['definition_code'] ?? ''),
                'reveal_tier' => $revealTierAfter,
            ]
        );

        $toolWear = $this->toolDurability->wear($playerId, (int) $toolItem['id'], 1);

        $investigationResult = $this->attributes->grantXp(
            $playerId,
            $attributeCode,
            $attributeXp,
            'exploration',
            (string) $instance['public_id'],
            'analyze_magnifier',
            [
                'definition_code' => (string) ($instance['definition_code'] ?? ''),
                'reveal_tier' => $revealTierAfter,
            ]
        );

        $explorationResult = $explorationXp > 0
            ? $this->attributes->grantXp(
                $playerId,
                'exploration',
                $explorationXp,
                'exploration',
                (string) $instance['public_id'],
                'analyze_magnifier',
                [
                    'definition_code' => (string) ($instance['definition_code'] ?? ''),
                    'reveal_tier' => $revealTierAfter,
                ]
            )
            : ['updated' => false];

        $this->pdo()->prepare('UPDATE investigable_instances
            SET reveal_tier = :reveal_tier,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND player_id = :player_id')->execute([
            'id' => (int) $instance['id'],
            'player_id' => $playerId,
            'reveal_tier' => $revealTierAfter,
        ]);

        $outcome = [
            'action_code' => 'analyze_magnifier',
            'definition_code' => (string) ($instance['definition_code'] ?? ''),
            'object_name' => (string) ($instance['definition_name'] ?? ''),
            'reveal_tier_before' => $revealTierBefore,
            'reveal_tier_after' => $revealTierAfter,
            'max_tier' => $maxTier,
            'fully_analyzed' => $revealTierAfter >= $actionMaxTier,
            'tier' => [
                'title' => (string) ($tierPayload['title'] ?? ('Camada ' . $revealTierAfter)),
                'description' => (string) ($tierPayload['description'] ?? ''),
                'hints' => array_values($tierPayload['hints'] ?? []),
                'loot_preview' => array_values($tierPayload['loot_preview'] ?? []),
                'recommended_tool' => is_array($tierPayload['recommended_tool'] ?? null) ? $tierPayload['recommended_tool'] : null,
            ],
            'rewards' => [
                'tool_mastery' => $toolResult,
                'investigation' => $investigationResult,
                'exploration' => $explorationResult,
            ],
            'tool_wear' => $toolWear,
        ];

        $this->recordEvent(
            $playerId,
            (int) $instance['id'],
            'analyze_magnifier',
            (int) $toolItem['id'],
            $revealTierBefore,
            $revealTierAfter,
            $outcome
        );

        return [
            'object' => [
                'public_id' => (string) $instance['public_id'],
                'definition_code' => (string) ($instance['definition_code'] ?? ''),
                'name' => (string) ($instance['definition_name'] ?? ''),
                'kind' => (string) ($instance['kind'] ?? ''),
                'reveal_tier' => $revealTierAfter,
                'max_tier' => $maxTier,
                'fully_analyzed' => $revealTierAfter >= $actionMaxTier,
            ],
            'analysis' => $outcome,
            'tool' => [
                'public_id' => (string) $toolItem['public_id'],
                'definition_code' => (string) ($toolItem['definition_code'] ?? ''),
                'tool_type' => (string) ($toolItem['tool_type'] ?? 'magnifier'),
                'durability' => [
                    'current' => $toolWear['current'] ?? ($toolItem['current_durability'] !== null ? (int) $toolItem['current_durability'] : null),
                    'max' => $toolWear['max'] ?? ($toolItem['max_durability'] !== null ? (int) $toolItem['max_durability'] : null),
                    'broken' => (bool) ($toolWear['broken'] ?? false),
                ],
            ],
        ];
    }

    private function findAnalyzeAction(int $definitionId): ?array
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
            'action_code' => 'analyze_magnifier',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function resolveOwnedTool(int $playerId, string $toolItemPublicId, string $requiredToolType): array
    {
        $toolItemPublicId = trim($toolItemPublicId);
        if ($toolItemPublicId === '') {
            throw new ExplorationException('EXPLORATION_TOOL_REQUIRED', 'A magnifier tool is required for analysis.', 422, [
                'required_tool_type' => $requiredToolType,
            ]);
        }

        $stmt = $this->pdo()->prepare('SELECT ii.*, id.code AS definition_code, id.base_config, ic.code AS category_code
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            INNER JOIN item_categories ic ON ic.id = id.category_id
            WHERE ii.public_id = :public_id AND ii.owner_player_id = :player_id
            LIMIT 1');
        $stmt->execute([
            'public_id' => $toolItemPublicId,
            'player_id' => $playerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new ExplorationException('EXPLORATION_TOOL_NOT_FOUND', 'Tool item was not found in your inventory.', 404, [
                'tool_item_public_id' => $toolItemPublicId,
            ]);
        }

        if (($row['category_code'] ?? null) !== 'tool') {
            throw new ExplorationException('EXPLORATION_TOOL_INVALID', 'Selected item is not a tool.', 422, [
                'tool_item_public_id' => $toolItemPublicId,
            ]);
        }

        $toolType = $this->toolTypeFromItem($row);
        if ($toolType !== $requiredToolType) {
            throw new ExplorationException('EXPLORATION_TOOL_INVALID', 'Selected tool cannot perform this analysis.', 422, [
                'tool_item_public_id' => $toolItemPublicId,
                'tool_type' => $toolType,
                'required_tool_type' => $requiredToolType,
            ]);
        }

        if ($row['current_durability'] !== null && (int) $row['current_durability'] <= 0) {
            throw new ExplorationException('EXPLORATION_TOOL_BROKEN', 'This tool is broken and must be repaired or replaced.', 422, [
                'tool_item_public_id' => $toolItemPublicId,
            ]);
        }

        $row['tool_type'] = $toolType;

        return $row;
    }

    private function toolTypeFromItem(array $item): string
    {
        $config = $this->parseJson($item['base_config'] ?? null);
        $toolType = strtolower(trim((string) ($config['tool_type'] ?? $config['tool_family'] ?? '')));

        return preg_replace('/[^a-z0-9_]/', '', $toolType) ?: '';
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
