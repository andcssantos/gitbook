<?php

namespace App\Game\Crafting\Services;

use App\Game\Inventory\DTO\GrantItemRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryAutoPlacementService;
use App\Game\Items\Repositories\ItemDefinitionRepository;
use App\Game\Items\Services\ItemSafetyService;
use App\Game\Market\Services\MarketItemContextService;
use App\Game\Market\Services\PlayerCurrencyService;
use App\Support\DB;
use App\Utils\Config;
use PDO;
use Throwable;

class CraftingWorkspaceService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?MarketItemContextService $context = null,
        private ?CraftingCompositionAnalyzer $analyzer = null,
        private ?CraftingConsumptionService $consumption = null,
        private ?CraftingEligibilityService $eligibility = null,
        private ?CraftingRecipeCatalog $catalog = null,
        private ?CraftingBlueprintService $blueprints = null,
        private ?PlayerCurrencyService $currencies = null,
        private ?CraftingEventService $events = null
    ) {
        $this->context ??= new MarketItemContextService($this->pdo);
        $this->analyzer ??= new CraftingCompositionAnalyzer(
            null,
            null,
            new PlayerCurrencyService($this->pdo)
        );
        $this->consumption ??= new CraftingConsumptionService($this->pdo);
        $this->eligibility ??= new CraftingEligibilityService();
        $this->catalog ??= new CraftingRecipeCatalog();
        $this->blueprints ??= new CraftingBlueprintService($this->pdo, $this->catalog);
        $this->currencies ??= new PlayerCurrencyService($this->pdo);
        $this->events ??= new CraftingEventService($this->pdo);
    }

    public function workspaces(): array
    {
        return array_values((array) Config::get('crafting.workspaces', []));
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     */
    public function preview(int $playerId, string $workspace, array $slots): array
    {
        $resolved = $this->resolveSlots($playerId, $slots, false);
        $analysis = $this->analyzer->analyze($workspace, $resolved, $playerId);
        $analysis['predicted_output'] = $this->enrichPredictedOutput($analysis['predicted_output'] ?? []);

        return $analysis + [
            'slots' => $this->presentResolvedSlots($resolved),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     */
    public function execute(int $playerId, string $workspace, array $slots): array
    {
        try {
            return $this->transaction(function () use ($playerId, $workspace, $slots): array {
            $resolved = $this->resolveSlots($playerId, $slots, true);
            $analysis = $this->analyzer->analyze($workspace, $resolved, $playerId);
            $recipeCode = (string) ($analysis['recipe_match']['recipe_code'] ?? '');
            $filled = array_values(array_filter($resolved, fn ($slot): bool => is_array($slot)));
            $event = $this->events->start(
                $playerId,
                $workspace,
                $recipeCode !== '' ? $recipeCode : null,
                $filled,
                $analysis,
                (int) ($analysis['gold_cost'] ?? 0)
            );

            if (!($analysis['can_craft'] ?? false)) {
                $this->events->fail($event, 'CRAFT_INVALID_COMPOSITION', (string) ($analysis['reason'] ?? 'Composicao invalida.'));
                throw new InventoryException('CRAFT_INVALID_COMPOSITION', (string) ($analysis['reason'] ?? 'Composicao invalida.'), 422);
            }

            $recipe = $recipeCode !== '' ? $this->catalog->findByCode($recipeCode) : null;
            $output = $this->selectOutput($workspace, $recipe, $analysis['predicted_output'] ?? [], $resolved);
            $definitionCode = (string) ($output['definition_code'] ?? '');
            if ($definitionCode === '') {
                throw new InventoryException('CRAFT_OUTPUT_UNKNOWN', 'Nao foi possivel determinar o resultado.', 422);
            }

            $goldCost = (int) ($analysis['gold_cost'] ?? 0);
            if ($goldCost > 0) {
                $this->currencies->debit($playerId, 'gold', $goldCost, 'CRAFT_FEE', 'crafting', $recipeCode !== '' ? $recipeCode : $workspace);
            }

            $this->consumption->consume($playerId, $filled, [
                'crafting_event_public_id' => (string) $event['public_id'],
                'recipe_code' => $recipeCode !== '' ? $recipeCode : null,
                'workspace' => $workspace,
            ]);

            $grant = (new InventoryAutoPlacementService($this->pdo))->grantAndPlace(GrantItemRequest::fromArray($playerId, [
                'item_definition_code' => $definitionCode,
                'quantity' => 1,
                'quality_bucket' => (string) ($output['quality_bucket'] ?? 'common'),
            ]));
            $this->events->complete($playerId, $event, $output + [
                'recipe_code' => $recipeCode !== '' ? $recipeCode : null,
                'workspace' => $workspace,
            ], $grant);

            if ($recipeCode !== '') {
                $this->recordCraftLog($playerId, $recipeCode, $workspace, $definitionCode);
            }

            $discovery = $recipeCode !== '' ? $this->blueprints->registerDiscovery($playerId, $recipeCode) : null;

            return [
                'action' => 'CRAFT',
                'workspace' => $workspace,
                'analysis' => $analysis,
                'granted_item' => $grant,
                'discovery' => $discovery,
                'crafting_event' => [
                    'public_id' => (string) $event['public_id'],
                    'seed' => (string) $event['craft_seed'],
                    'status' => 'completed',
                ],
            ];
            });
        } catch (InventoryException $e) {
            $this->recordFailedAttempt($playerId, $workspace, $slots, $e->errorCode(), $e->getMessage());
            throw $e;
        }
    }

    public function shareRecipe(int $playerId, string $recipeCode): void
    {
        $this->blueprints->shareRecipe($playerId, $recipeCode);
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     */
    private function recordFailedAttempt(int $playerId, string $workspace, array $slots, string $code, string $message): void
    {
        try {
            $resolved = $this->resolveSlots($playerId, $slots, false);
            $analysis = $this->analyzer->analyze($workspace, $resolved, $playerId);
            $filled = array_values(array_filter($resolved, fn ($slot): bool => is_array($slot)));
            $recipeCode = (string) ($analysis['recipe_match']['recipe_code'] ?? '');
            $event = $this->events->start(
                $playerId,
                $workspace,
                $recipeCode !== '' ? $recipeCode : null,
                $filled,
                $analysis,
                (int) ($analysis['gold_cost'] ?? 0)
            );
            $this->events->fail($event, $code, $message);
        } catch (Throwable) {
            // Never mask the original crafting failure with audit persistence problems.
        }
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     * @return array<int, array<string, mixed>|null>
     */
    private function resolveSlots(int $playerId, array $slots, bool $lock): array
    {
        $slotCount = (int) Config::get('crafting.slot_count', 6);
        $resolved = array_fill(0, $slotCount, null);
        $allocationKeys = [];

        foreach ($slots as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $index = (int) ($entry['index'] ?? $entry['slot_index'] ?? -1);
            if ($index < 0 || $index >= $slotCount) {
                continue;
            }

            $source = (array) ($entry['source'] ?? $entry);
            $kind = (string) ($source['kind'] ?? '');
            $consumeQty = max(1, (int) ($source['quantity'] ?? 1));

            if ($kind === 'material_stack') {
                $familyCode = (string) ($source['family_code'] ?? '');
                $originCode = (string) ($source['origin_code'] ?? '');
                $allocationKey = "material:{$familyCode}::{$originCode}";
                $allocationKeys[$allocationKey] = ($allocationKeys[$allocationKey] ?? 0) + $consumeQty;
                $resolved[$index] = $this->withSlotIndex($this->resolveMaterialSlot($playerId, $source, $consumeQty, $lock), $index);
                continue;
            }

            if ($kind === 'item_instance') {
                $publicId = (string) ($source['public_id'] ?? '');
                $allocationKey = "item:{$publicId}";
                $allocationKeys[$allocationKey] = ($allocationKeys[$allocationKey] ?? 0) + $consumeQty;
                $resolved[$index] = $this->withSlotIndex($this->resolveItemSlot($playerId, $publicId, $consumeQty, $lock), $index);
            }
        }

        $this->validateAllocations($resolved, $allocationKeys);

        return $resolved;
    }

    private function withSlotIndex(?array $slot, int $index): ?array
    {
        if ($slot === null) {
            return null;
        }

        $slot['slot_index'] = $index;

        return $slot;
    }

    /**
     * @param array<int, array<string, mixed>|null> $resolved
     * @param array<string, int> $allocationKeys
     */
    private function validateAllocations(array $resolved, array $allocationKeys): void
    {
        $available = [];

        foreach ($resolved as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $kind = (string) ($slot['source_kind'] ?? '');
            if ($kind === 'material_stack') {
                $key = 'material:'.($slot['family_code'] ?? '').'::'.($slot['origin_code'] ?? '');
                $available[$key] = (int) ($slot['quantity_available'] ?? 0);
                continue;
            }

            if ($kind === 'item_instance') {
                $key = 'item:'.($slot['public_id'] ?? '');
                $available[$key] = (int) ($slot['quantity_available'] ?? 1);
            }
        }

        foreach ($allocationKeys as $key => $requested) {
            if (!isset($available[$key]) || $requested > $available[$key]) {
                throw new InventoryException('CRAFT_INSUFFICIENT_STACK', 'Quantidade insuficiente para um dos componentes selecionados.', 422);
            }
        }
    }

    private function resolveItemSlot(int $playerId, string $publicId, int $consumeQty, bool $lock): ?array
    {
        if ($publicId === '') {
            return null;
        }

        $item = $this->context->forOwnedItem($playerId, $publicId, $lock);
        if ($item === null) {
            throw new InventoryException('CRAFT_ITEM_NOT_FOUND', 'Item nao encontrado.', 404);
        }

        $isEquipped = $this->isEquipped($playerId, (int) ($item['item_instance_id'] ?? 0));
        $this->eligibility->assertItemEligible($item, $isEquipped);
        $itemInstanceId = (int) ($item['item_instance_id'] ?? 0);
        if ($itemInstanceId > 0) {
            (new ItemSafetyService($this->pdo()))->assertNotLocked($playerId, $itemInstanceId, 'CRAFT');
        }

        $definitionCode = (string) ($item['definition_code'] ?? $item['definition']['code'] ?? '');

        return [
            'source_kind' => 'item_instance',
            'slot_index' => null,
            'public_id' => $publicId,
            'definition_code' => $definitionCode,
            'label' => (string) ($item['item_name'] ?? $item['definition_name'] ?? $item['definition']['name'] ?? $definitionCode ?: 'Item'),
            'icon_url' => '/assets/game/items/'.$definitionCode.'.png',
            'category_code' => (string) ($item['category_code'] ?? $item['definition']['category_code'] ?? 'material'),
            'material_family_code' => (string) ($item['material_family_code'] ?? $item['definition']['material_family_code'] ?? 'unknown'),
            'equip_slot_code' => (string) ($item['equip_slot_code'] ?? $item['definition']['equip_slot_code'] ?? ''),
            'quality_bucket' => (string) ($item['quality_bucket'] ?? 'common'),
            'quantity_available' => max(1, (int) ($item['quantity'] ?? 1)),
            'consume_quantity' => $consumeQty,
            'item' => $item,
        ];
    }

    private function resolveMaterialSlot(int $playerId, array $source, int $consumeQty, bool $lock): ?array
    {
        $familyCode = (string) ($source['family_code'] ?? '');
        $originCode = (string) ($source['origin_code'] ?? '');

        if ($familyCode === '' || $originCode === '') {
            return null;
        }

        $stmt = $this->pdo()->prepare('SELECT
                pms.quantity,
                pms.stash_tab,
                pms.material_family_id,
                pms.material_origin_id,
                mf.code AS family_code,
                mf.name AS family_name,
                mf.description AS family_description,
                mo.code AS origin_code,
                mo.name AS origin_name
            FROM player_material_stacks pms
            INNER JOIN material_families mf ON mf.id = pms.material_family_id
            INNER JOIN material_origins mo ON mo.id = pms.material_origin_id
            WHERE pms.player_id = :player_id
              AND mf.code = :family_code
              AND mo.code = :origin_code
              AND pms.quantity >= :quantity
            LIMIT 1'.($lock && $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $stmt->execute([
            'player_id' => $playerId,
            'family_code' => $familyCode,
            'origin_code' => $originCode,
            'quantity' => $consumeQty,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InventoryException('CRAFT_MATERIAL_NOT_FOUND', 'Material nao encontrado no estoque.', 404);
        }

        return [
            'source_kind' => 'material_stack',
            'family_code' => $familyCode,
            'origin_code' => $originCode,
            'material_family_id' => (int) $row['material_family_id'],
            'material_origin_id' => (int) $row['material_origin_id'],
            'material_family_code' => $familyCode,
            'category_code' => 'material',
            'label' => (string) ($row['family_name'] ?? $familyCode).' ('.(string) ($row['origin_name'] ?? $originCode).')',
            'icon_url' => '/assets/game/materials/'.$familyCode.'.png',
            'quantity_available' => (int) $row['quantity'],
            'consume_quantity' => $consumeQty,
            'stash_tab' => (string) $row['stash_tab'],
            'family_description' => (string) ($row['family_description'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $recipe
     * @param array<string, mixed> $predicted
     * @param array<int, array<string, mixed>|null> $resolvedSlots
     * @return array<string, mixed>
     */
    private function selectOutput(string $workspace, ?array $recipe, array $predicted, array $resolvedSlots): array
    {
        if ($recipe === null) {
            return $predicted;
        }

        $outputs = (array) ($recipe['outputs'] ?? []);
        if ($outputs === []) {
            return $predicted;
        }

        if (count($outputs) === 1) {
            $selected = $outputs[0];
        } else {
            $selected = $this->pickWeightedOutput($outputs);
        }

        $matcher = new CraftingRecipeMatcherService($this->catalog);
        $pool = $matcher->match($workspace, $resolvedSlots)['pool'] ?? [];
        $avgRank = (int) ($pool['average_quality_rank'] ?? 1);

        $quality = (string) ($selected['quality_bucket'] ?? 'common');
        if ($workspace === 'alchemy') {
            $quality = $this->qualityFromRank($quality, $avgRank);
        } elseif ($workspace === 'forge') {
            $quality = (string) (Config::get('crafting.workspaces.forge.forced_quality') ?? 'common');
        }

        return [
            'definition_code' => (string) ($selected['definition_code'] ?? ''),
            'quality_bucket' => $quality,
            'name' => (string) ($selected['name'] ?? ''),
            'description' => (string) ($recipe['description'] ?? ''),
            'rarity_label' => ucfirst($quality),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $outputs
     * @return array<string, mixed>
     */
    private function pickWeightedOutput(array $outputs): array
    {
        $totalWeight = 0;
        foreach ($outputs as $output) {
            $totalWeight += max(1, (int) ($output['weight'] ?? 1));
        }

        $roll = random_int(1, max(1, $totalWeight));
        $cursor = 0;
        foreach ($outputs as $output) {
            $cursor += max(1, (int) ($output['weight'] ?? 1));
            if ($roll <= $cursor) {
                return $output;
            }
        }

        return $outputs[0];
    }

    private function qualityFromRank(string $baseQuality, int $averageRank): string
    {
        $ranks = [
            'common' => 1,
            'uncommon' => 2,
            'magic' => 3,
            'rare' => 4,
            'epic' => 5,
            'legendary' => 6,
        ];

        $rank = max($ranks[$baseQuality] ?? 1, $averageRank);
        if ($rank >= 6) return 'legendary';
        if ($rank >= 5) return 'epic';
        if ($rank >= 4) return 'rare';
        if ($rank >= 3) return 'magic';
        if ($rank >= 2) return 'uncommon';

        return 'common';
    }

    private function isEquipped(int $playerId, int $itemInstanceId): bool
    {
        if ($itemInstanceId <= 0) {
            return false;
        }

        $stmt = $this->pdo()->prepare('SELECT 1 FROM player_equipment WHERE player_id = :player_id AND item_instance_id = :item_instance_id LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'item_instance_id' => $itemInstanceId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<int, array<string, mixed>|null> $resolved
     * @return array<int, array<string, mixed>|null>
     */
    private function presentResolvedSlots(array $resolved): array
    {
        return array_map(function (?array $slot): ?array {
            if ($slot === null) {
                return null;
            }

            return [
                'source_kind' => $slot['source_kind'] ?? null,
                'slot_index' => $slot['slot_index'] ?? null,
                'public_id' => $slot['public_id'] ?? null,
                'family_code' => $slot['family_code'] ?? null,
                'origin_code' => $slot['origin_code'] ?? null,
                'label' => $slot['label'] ?? null,
                'icon_url' => $slot['icon_url'] ?? null,
                'category_code' => $slot['category_code'] ?? null,
                'quality_bucket' => $slot['quality_bucket'] ?? null,
                'quantity_available' => $slot['quantity_available'] ?? null,
                'consume_quantity' => $slot['consume_quantity'] ?? 1,
            ];
        }, $resolved);
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private function enrichPredictedOutput(array $output): array
    {
        $definitionCode = (string) ($output['definition_code'] ?? '');
        if ($definitionCode === '') {
            return $output;
        }

        $definition = (new ItemDefinitionRepository($this->pdo()))->findActiveByCode($definitionCode);
        if ($definition === null) {
            return $output;
        }

        $output['name'] = (string) ($definition['name'] ?? $definitionCode);
        $output['description'] = (string) ($definition['description'] ?? $output['description'] ?? '');
        $output['category_code'] = $this->categoryCodeForDefinition((int) $definition['category_id']);

        return $output;
    }

    private function categoryCodeForDefinition(int $categoryId): string
    {
        if ($categoryId <= 0) {
            return 'material';
        }

        $stmt = $this->pdo()->prepare('SELECT code FROM item_categories WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $categoryId]);

        return (string) ($stmt->fetchColumn() ?: 'material');
    }

    private function recordCraftLog(int $playerId, string $recipeCode, string $workspace, string $outputDefinitionCode): void
    {
        if (!$this->tableExists('player_craft_log')) {
            return;
        }

        $this->pdo()->prepare('INSERT INTO player_craft_log (player_id, recipe_code, workspace, output_definition_code)
            VALUES (:player_id, :recipe_code, :workspace, :output_definition_code)')
            ->execute([
                'player_id' => $playerId,
                'recipe_code' => $recipeCode,
                'workspace' => $workspace,
                'output_definition_code' => $outputDefinitionCode,
            ]);
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

    private function transaction(callable $callback): mixed
    {
        if ($this->pdo instanceof PDO) {
            $started = !$this->pdo->inTransaction();
            if ($started) {
                $this->pdo->beginTransaction();
            }

            try {
                $result = $callback();
                if ($started) {
                    $this->pdo->commit();
                }

                return $result;
            } catch (Throwable $e) {
                if ($started && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        }

        return DB::transaction($callback);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
