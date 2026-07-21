<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Expeditions\Services\ExpeditionArenaCombatService;
use App\Game\Expeditions\Services\ExpeditionArenaService;
use App\Game\Expeditions\Services\ExpeditionPotionUseService;
use App\Game\Inventory\InventoryException;
use App\Game\Player\Services\PlayerResolver;
use App\Http\Request;
use App\Validation\ValidationException;

class ExpeditionArenaController extends Controller
{
    public function state(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::query(), [
                'biome_code' => 'required|string|max:60',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new ExpeditionArenaService())->state(
                (int) $player['id'],
                (string) $payload['biome_code']
            );

            $this->success($result, 'Arena state.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function move(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'biome_code' => 'required|string|max:60',
                'map_x' => 'required|numeric|min:0|max:20',
                'map_y' => 'required|numeric|min:0|max:20',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $service = new ExpeditionArenaService();
            $moveResult = null;

            $this->transaction(function () use ($service, $player, $payload, &$moveResult): void {
                $moveResult = $service->move(
                    (int) $player['id'],
                    (string) $payload['biome_code'],
                    (float) $payload['map_x'],
                    (float) $payload['map_y']
                );
            });

            $result = $service->state((int) $player['id'], (string) $payload['biome_code'], ['mode' => 'lite']);
            $this->success([
                'arena' => $result,
                'move' => $moveResult,
            ], 'Arena position updated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function attack(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'encounter_public_id' => 'required|string|max:80',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $arena = new ExpeditionArenaService();
            $combat = new ExpeditionArenaCombatService();

            $result = $this->transaction(fn (): array => $combat->attack(
                (int) $player['id'],
                (string) $payload['encounter_public_id']
            ));

            $biomeCode = $this->resolveBiomeCode((int) $player['id']);
            $state = $arena->state((int) $player['id'], $biomeCode, ['mode' => 'lite']);

            $this->success([
                'combat' => $result,
                'arena' => $state,
            ], 'Arena attack resolved.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        }
    }

    public function focus(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'encounter_public_id' => 'string|max:80',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $arena = new ExpeditionArenaService();
            $combat = new ExpeditionArenaCombatService();
            $encounterPublicId = isset($payload['encounter_public_id']) && is_string($payload['encounter_public_id'])
                ? trim($payload['encounter_public_id'])
                : null;
            if ($encounterPublicId === '') {
                $encounterPublicId = null;
            }

            $result = $this->transaction(fn (): array => $combat->focus(
                (int) $player['id'],
                $encounterPublicId
            ));

            $biomeCode = $this->resolveBiomeCode((int) $player['id']);
            $state = $arena->state((int) $player['id'], $biomeCode, ['mode' => 'lite']);

            $this->success([
                'focus' => $result,
                'arena' => $state,
            ], 'Arena focus updated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        }
    }

    public function tick(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $arena = new ExpeditionArenaService();
            $combat = new ExpeditionArenaCombatService();

            $result = $this->transaction(fn (): array => $combat->tick((int) $player['id']));

            $biomeCode = $this->resolveBiomeCode((int) $player['id']);
            $state = $arena->state((int) $player['id'], $biomeCode, ['mode' => 'lite']);

            $this->success([
                'combat' => $result,
                'arena' => $state,
            ], 'Arena combat tick resolved.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        }
    }

    public function pickup(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'loot_public_id' => 'required|string|max:80',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $arena = new ExpeditionArenaService();
            $combat = new ExpeditionArenaCombatService();

            $result = $this->transaction(fn (): array => $combat->pickup(
                (int) $player['id'],
                (string) $payload['loot_public_id']
            ));

            $biomeCode = $this->resolveBiomeCode((int) $player['id']);
            $state = $arena->state((int) $player['id'], $biomeCode, ['mode' => 'lite']);

            $this->success([
                'pickup' => $result,
                'arena' => $state,
            ], 'Ground loot collected.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $message = $e->getMessage();
            if (stripos($message, 'definition') !== false) {
                $message = 'Item de loot invalido (definicao nao encontrada). Tente outro drop.';
            } elseif (stripos($message, 'full') !== false || stripos($message, 'space') !== false) {
                $message = 'Carry cheio. Abra o Expedition Carry e liberte espaco.';
            }
            $this->fail($message, 422, [
                'reason_code' => $e->errorCode(),
            ]);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        }
    }

    public function usePotion(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'slot_code' => 'string|max:40',
                'item_public_id' => 'string|max:80',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $arena = new ExpeditionArenaService();
            $potions = new ExpeditionPotionUseService();

            $slotCode = isset($payload['slot_code']) && is_string($payload['slot_code'])
                ? trim($payload['slot_code'])
                : null;
            $itemPublicId = isset($payload['item_public_id']) && is_string($payload['item_public_id'])
                ? trim($payload['item_public_id'])
                : null;
            if ($slotCode === '') {
                $slotCode = null;
            }
            if ($itemPublicId === '') {
                $itemPublicId = null;
            }

            $result = $this->transaction(fn (): array => $potions->useFromBelt(
                (int) $player['id'],
                $slotCode,
                $itemPublicId
            ));

            $biomeCode = $this->resolveBiomeCode((int) $player['id']);
            $state = $arena->state((int) $player['id'], $biomeCode, ['mode' => 'lite']);

            $this->success([
                'use_potion' => $result,
                'arena' => $state,
            ], 'Potion consumed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        }
    }

    private function resolveBiomeCode(int $playerId): string
    {
        $stmt = \App\Support\DB::pdo()->prepare("SELECT metadata_json FROM expedition_instances WHERE player_id = :player_id AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute(['player_id' => $playerId]);
        $metadataJson = $stmt->fetchColumn();
        if (!is_string($metadataJson) || trim($metadataJson) === '') {
            return 'bosque_inicial';
        }

        try {
            $metadata = json_decode($metadataJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'bosque_inicial';
        }

        return (string) ($metadata['biome_code'] ?? 'bosque_inicial');
    }
}
