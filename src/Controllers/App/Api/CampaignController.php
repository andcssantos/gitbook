<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Campaign\Services\CampaignStageCombatService;
use App\Game\Campaign\Services\CampaignStageLootService;
use App\Game\Campaign\Services\CampaignStageRunService;
use App\Game\Campaign\Services\CampaignPotionService;
use App\Game\Campaign\Services\CampaignVillageService;
use App\Game\Campaign\Services\CampaignWorldService;
use App\Game\Player\Services\PlayerResolver;
use App\Http\Request;
use App\Validation\ValidationException;

class CampaignController extends Controller
{
    public function showWorld(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $worldCode = (string) ($params['worldCode'] ?? 'mundo_1_bosque');
            if ($worldCode === '' || $worldCode === '1') {
                $worldCode = 'mundo_1_bosque';
            }

            $world = (new CampaignWorldService())->worldForPlayer((int) $player['id'], $worldCode);
            if ($world === null) {
                $this->fail('Campaign world not found. Rode migrate + seed 017.', 404);
                return;
            }

            $this->success(['world' => $world], 'Campaign world.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function activeStage(): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $runs = new CampaignStageRunService();
            $run = $runs->activeForPlayer((int) $player['id']);
            if ($run === null) {
                $run = $runs->pendingLootForPlayer((int) $player['id']);
            }
            $this->success(['run' => $run], $run ? 'Active campaign stage.' : 'No active campaign stage.');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function startStage(): void
    {
        try {
            $payload = $this->validate(Request::json(), [
                'node_code' => 'required|string|max:60',
            ]);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $run = (new CampaignStageRunService())->start((int) $player['id'], (string) $payload['node_code']);
            $this->success(['run' => $run], 'Campaign stage started.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function tickStage(): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new CampaignStageCombatService())->tick((int) $player['id']);
            $this->success($result, 'Campaign stage tick.');
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function leaveStage(): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new CampaignStageRunService())->leave((int) $player['id']);
            $this->success($result, 'Left campaign stage.');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function lootState(): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new CampaignStageLootService())->state((int) $player['id']);
            $this->success($result, 'Campaign stage loot state.');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function lootCommit(): void
    {
        try {
            $payload = $this->validate(Request::json(), [
                'take_staging_ids' => 'array',
                'abandon_public_ids' => 'array',
                'take_placements' => 'array',
            ]);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $ids = array_values(array_filter(array_map('strval', (array) ($payload['take_staging_ids'] ?? [])), static fn ($id) => $id !== ''));
            $abandon = array_values(array_filter(array_map('strval', (array) ($payload['abandon_public_ids'] ?? [])), static fn ($id) => $id !== ''));
            $placements = array_values(array_filter((array) ($payload['take_placements'] ?? []), 'is_array'));
            $result = (new CampaignStageLootService())->commit((int) $player['id'], $ids, $abandon, $placements);
            $this->success($result, 'Campaign loot committed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\App\Game\Inventory\InventoryException $e) {
            $this->fail($e->getMessage(), 422, ['code' => $e->errorCode()]);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function usePotion(): void
    {
        try {
            $payload = $this->validate(Request::json(), [
                'slot_code' => 'string|max:40',
            ]);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $playerId = (int) $player['id'];
            $runs = new CampaignStageRunService();
            $run = $runs->activeForPlayer($playerId);
            if ($run === null) {
                throw new \RuntimeException('Nenhuma fase ativa.');
            }

            $slot = isset($payload['slot_code']) ? trim((string) $payload['slot_code']) : null;
            if ($slot === '') {
                $slot = null;
            }

            $result = (new CampaignPotionService())->useSlot(
                $playerId,
                (int) $run['current_hp'],
                (int) $run['max_hp'],
                $slot
            );

            $pdo = \App\Support\DB::pdo();
            $pdo->prepare('UPDATE campaign_stage_runs SET current_hp = :hp, updated_at = CURRENT_TIMESTAMP WHERE public_id = :public_id AND player_id = :player_id')
                ->execute([
                    'hp' => (int) $result['hp'],
                    'public_id' => $run['public_id'],
                    'player_id' => $playerId,
                ]);

            $fresh = $runs->activeForPlayer($playerId);
            $this->success([
                'run' => $fresh,
                'events' => $result['events'],
                'potions' => $result['potions'],
            ], 'Pocao usada.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function villageInteract(): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $payload = Request::json();
            $nodeCode = trim((string) ($payload['node_code'] ?? ''));
            $hotspot = trim((string) ($payload['hotspot_code'] ?? ''));
            if ($nodeCode === '' || $hotspot === '') {
                $this->fail('node_code e hotspot_code sao obrigatorios.', 422);
                return;
            }

            $result = (new CampaignVillageService())->interact((int) $player['id'], $nodeCode, $hotspot);
            $world = (new CampaignWorldService())->worldForPlayer((int) $player['id'], 'mundo_1_bosque');
            $this->success([
                'interaction' => $result,
                'world' => $world,
            ], $result['message'] ?? 'Investigacao ok.');
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }
}
