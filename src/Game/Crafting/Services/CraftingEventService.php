<?php

namespace App\Game\Crafting\Services;

use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Game\Items\Services\ItemSafetyService;
use App\Support\DB;
use App\Support\PublicId;
use PDO;

class CraftingEventService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $filledSlots
     * @param array<string, mixed> $analysis
     * @return array{id:int, public_id:string, craft_seed:string}
     */
    public function start(int $playerId, string $workspace, ?string $recipeCode, array $filledSlots, array $analysis, int $goldCost): array
    {
        $publicId = PublicId::uuid();
        $craftSeed = bin2hex(random_bytes(16));

        if (!$this->tableExists('crafting_events') || !$this->tableExists('crafting_event_inputs')) {
            return [
                'id' => 0,
                'public_id' => $publicId,
                'craft_seed' => $craftSeed,
            ];
        }

        $stmt = $this->pdo()->prepare('INSERT INTO crafting_events (
            public_id, player_id, workspace, recipe_code, recipe_version, status, craft_seed,
            calculation_version, gold_cost, currency_code, metadata_json
        ) VALUES (
            :public_id, :player_id, :workspace, :recipe_code, :recipe_version, :status, :craft_seed,
            :calculation_version, :gold_cost, :currency_code, :metadata_json
        )');
        $stmt->execute([
            'public_id' => $publicId,
            'player_id' => $playerId,
            'workspace' => $workspace,
            'recipe_code' => $recipeCode,
            'recipe_version' => 'config',
            'status' => 'started',
            'craft_seed' => $craftSeed,
            'calculation_version' => 'crafting-v1',
            'gold_cost' => $goldCost,
            'currency_code' => 'gold',
            'metadata_json' => json_encode([
                'compatibility_label' => $analysis['recipe_match']['compatibility_label'] ?? null,
                'synergy_level' => $analysis['synergy_level'] ?? null,
                'synergy_label' => $analysis['synergy_label'] ?? null,
                'filled_slots' => count($filledSlots),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        $eventId = (int) $this->pdo()->lastInsertId();
        $this->recordInputs($eventId, $filledSlots);

        return [
            'id' => $eventId,
            'public_id' => $publicId,
            'craft_seed' => $craftSeed,
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $output
     * @param array<string, mixed> $grantResult
     */
    public function complete(int $playerId, array $event, array $output, array $grantResult): void
    {
        $eventId = (int) $event['id'];
        if ($eventId <= 0 || !$this->tableExists('crafting_events') || !$this->tableExists('crafting_event_outputs')) {
            return;
        }

        $outputPublicId = (string) ($grantResult['target_item_public_id'] ?? $grantResult['item_public_id'] ?? '');
        $item = $outputPublicId !== ''
            ? (new ItemInstanceRepository($this->pdo()))->findByPublicIdAndOwner($outputPublicId, $playerId, true)
            : null;

        $itemInstanceId = is_array($item) ? (int) $item['id'] : null;
        if ($itemInstanceId !== null && $itemInstanceId > 0) {
            $this->pdo()->prepare('UPDATE item_instances
                SET crafted_by_player_id = :player_id,
                    crafting_event_id = :crafting_event_id,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id')->execute([
                    'id' => $itemInstanceId,
                    'player_id' => $playerId,
                    'crafting_event_id' => $eventId,
                ]);
        }

        $stmt = $this->pdo()->prepare('INSERT INTO crafting_event_outputs (
            crafting_event_id, item_instance_id, item_public_id, definition_code, quality_bucket, roll_json, snapshot_json
        ) VALUES (
            :crafting_event_id, :item_instance_id, :item_public_id, :definition_code, :quality_bucket, :roll_json, :snapshot_json
        )');
        $stmt->execute([
            'crafting_event_id' => $eventId,
            'item_instance_id' => $itemInstanceId,
            'item_public_id' => $outputPublicId !== '' ? $outputPublicId : null,
            'definition_code' => (string) ($output['definition_code'] ?? ''),
            'quality_bucket' => $output['quality_bucket'] ?? null,
            'roll_json' => json_encode([
                'mode' => 'config-output',
                'craft_seed' => (string) ($event['craft_seed'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'snapshot_json' => json_encode([
                'output' => $output,
                'grant_result' => $grantResult,
                'item' => $item,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        $this->pdo()->prepare("UPDATE crafting_events
            SET status = 'completed', completed_at = CURRENT_TIMESTAMP
            WHERE id = :id")->execute(['id' => $eventId]);

        if (is_array($item)) {
            (new ItemSafetyService($this->pdo()))->record($item, $playerId, 'crafted_created', [
                'crafting_event_public_id' => (string) $event['public_id'],
                'recipe_code' => $output['recipe_code'] ?? null,
                'workspace' => $output['workspace'] ?? null,
                'definition_code' => $output['definition_code'] ?? null,
                'quality_bucket' => $output['quality_bucket'] ?? null,
            ], (string) $event['public_id']);
        }
    }

    public function fail(array $event, string $code, string $message): void
    {
        if ((int) ($event['id'] ?? 0) <= 0 || !$this->tableExists('crafting_events')) {
            return;
        }

        $this->pdo()->prepare("UPDATE crafting_events
            SET status = 'failed',
                failure_code = :failure_code,
                failure_message = :failure_message,
                completed_at = CURRENT_TIMESTAMP
            WHERE id = :id")->execute([
                'id' => (int) $event['id'],
                'failure_code' => $code,
                'failure_message' => mb_substr($message, 0, 255),
            ]);
    }

    /**
     * @param array<int, array<string, mixed>> $filledSlots
     */
    private function recordInputs(int $eventId, array $filledSlots): void
    {
        if ($eventId <= 0 || !$this->tableExists('crafting_event_inputs')) {
            return;
        }

        $stmt = $this->pdo()->prepare('INSERT INTO crafting_event_inputs (
            crafting_event_id, slot_index, source_kind, item_instance_id, item_public_id,
            material_family_code, material_origin_code, quantity, quality_bucket, snapshot_json
        ) VALUES (
            :crafting_event_id, :slot_index, :source_kind, :item_instance_id, :item_public_id,
            :material_family_code, :material_origin_code, :quantity, :quality_bucket, :snapshot_json
        )');

        foreach ($filledSlots as $slot) {
            $stmt->execute([
                'crafting_event_id' => $eventId,
                'slot_index' => (int) ($slot['slot_index'] ?? 0),
                'source_kind' => (string) ($slot['source_kind'] ?? ''),
                'item_instance_id' => isset($slot['item']['item_instance_id']) ? (int) $slot['item']['item_instance_id'] : ((int) ($slot['item']['id'] ?? 0) ?: null),
                'item_public_id' => $slot['public_id'] ?? null,
                'material_family_code' => $slot['family_code'] ?? $slot['material_family_code'] ?? null,
                'material_origin_code' => $slot['origin_code'] ?? null,
                'quantity' => max(1, (int) ($slot['consume_quantity'] ?? 1)),
                'quality_bucket' => $slot['quality_bucket'] ?? null,
                'snapshot_json' => json_encode($this->safeSlotSnapshot($slot), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private function safeSlotSnapshot(array $slot): array
    {
        unset($slot['item']['base_config']);

        return $slot;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }
}
