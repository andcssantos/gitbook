<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Expeditions\Services\ExpeditionCompletionService;
use App\Game\Expeditions\Services\ExpeditionLifecycleService;
use App\Game\Player\Services\PlayerResolver;
use App\Http\Request;
use App\Validation\ValidationException;

class ExpeditionController extends Controller
{
    public function active(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new ExpeditionLifecycleService())->activeForPlayer((int) $player['id']);

            $this->success($result, 'Expedition status.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        }
    }

    public function start(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'biome_code' => 'required|string|max:60',
                'duration_minutes' => 'nullable|int|min:2|max:240',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();

            $result = $this->transaction(fn (): array => (new ExpeditionLifecycleService())->start(
                (int) $player['id'],
                (string) $payload['biome_code'],
                isset($payload['duration_minutes']) ? (int) $payload['duration_minutes'] : null
            ));

            $this->success($result, 'Expedition started.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        }
    }

    public function complete(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();

            $result = $this->transaction(fn (): array => (new ExpeditionCompletionService())->claim((int) $player['id']));

            $this->success($result, 'Expedition rewards claimed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        }
    }
}
