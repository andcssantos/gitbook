<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Inventory\Services\InventoryStateService;
use App\Game\Player\Services\PlayerAttributeService;
use App\Game\Player\Services\PlayerConsumableService;
use App\Game\Player\Services\PlayerHudService;
use App\Game\Player\Services\PlayerResolver;
use App\Game\Player\Services\PlayerVitalsService;
use App\Http\Request;
use App\Support\DB;
use App\Validation\ValidationException;

class PlayerController extends Controller
{
    public function hud(array $params = []): void
    {
        $player = (new PlayerResolver())->requireCurrentPlayer();

        $this->success((new PlayerHudService())->forPlayer((int) $player['id']), 'Player HUD.');
    }

    public function allocateAttribute(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'attribute_code' => 'required|string|max:40',
                'points' => 'integer|min:1|max:50',
            ]);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $points = isset($payload['points']) ? (int) $payload['points'] : 1;

            $playerId = (int) $player['id'];
            $result = DB::transaction(static function () use ($playerId, $payload, $points): array {
                return (new PlayerAttributeService())->allocate(
                    $playerId,
                    (string) $payload['attribute_code'],
                    $points
                );
            });

            $this->success($this->attributeMutationPayload($playerId, $result), 'Atributo alocado.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function resetAttributes(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $playerId = (int) $player['id'];

            $result = DB::transaction(static function () use ($playerId): array {
                return (new PlayerAttributeService())->resetAllocated($playerId);
            });

            if (!(bool) ($result['updated'] ?? false)) {
                $this->success($this->attributeMutationPayload($playerId, $result), 'Nenhum ponto alocado para resetar.');
                return;
            }

            $this->success($this->attributeMutationPayload($playerId, $result), 'Pontos resetados.');
        } catch (\App\Game\Inventory\InventoryException $e) {
            $this->fail($e->getMessage(), $e->status() ?: 422);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    /** @param array<string, mixed> $result */
    private function attributeMutationPayload(int $playerId, array $result): array
    {
        InventoryStateService::forgetCombatSnapshot($playerId);
        $snapshot = (new InventoryStateService())->combatSnapshotForPlayer($playerId);
        $power = is_array($snapshot['player_power'] ?? null) ? $snapshot['player_power'] : null;
        $hud = (new PlayerHudService())->forPlayer($playerId, $power);

        // HUD é a fonte de verdade para ATK/DEF/HP/AGI exibidos (inclui vida por defesa).
        if (is_array($hud['power'] ?? null)) {
            $power = [
                ...(is_array($power) ? $power : []),
                ...$hud['power'],
            ];
        }

        return [
            ...$result,
            'player_hud' => $hud,
            'player_power' => $power,
            'character_stats' => $snapshot['character_stats'] ?? [],
        ];
    }

    public function rest(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'duration_minutes' => 'integer|min:1|max:120',
            ]);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $minutes = isset($payload['duration_minutes']) ? (int) $payload['duration_minutes'] : 20;

            $result = DB::transaction(static function () use ($player, $minutes): array {
                return (new PlayerVitalsService())->startRest((int) $player['id'], $minutes);
            });

            $this->success($result, 'Rest started.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function consume(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'item_public_id' => 'required|string|max:64',
            ]);
            $player = (new PlayerResolver())->requireCurrentPlayer();

            $result = DB::transaction(static function () use ($player, $payload): array {
                return (new PlayerConsumableService())->consume((int) $player['id'], (string) $payload['item_public_id']);
            });

            $this->success($result, 'Item consumed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function swapEquipmentSlots(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'from_slot' => 'required|string|max:40',
                'to_slot' => 'required|string|max:40',
            ]);
            $player = (new PlayerResolver())->requireCurrentPlayer();

            $result = (new \App\Game\Equipment\Services\EquipmentService())->swapSlots(
                (int) $player['id'],
                (string) $payload['from_slot'],
                (string) $payload['to_slot']
            );

            $this->success($result, 'Equipment slots swapped.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\App\Game\Inventory\InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }
}
