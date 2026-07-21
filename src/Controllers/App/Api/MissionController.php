<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Missions\Services\MissionService;
use App\Game\Player\Services\PlayerResolver;
use App\Http\Request;
use App\Validation\ValidationException;

class MissionController extends Controller
{
    public function list(array $params = []): void
    {
        try {
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $service = new MissionService();
            $service->syncAll((int) $player['id']);
            $missions = $service->listForPlayer((int) $player['id'], 40);

            $grouped = [
                'main' => [],
                'side' => [],
                'season' => [],
                'active' => [],
                'completed' => [],
            ];
            foreach ($missions as $mission) {
                $type = (string) ($mission['mission_type'] ?? 'main');
                if (!isset($grouped[$type])) {
                    $grouped[$type] = [];
                }
                $grouped[$type][] = $mission;
                if (($mission['status'] ?? '') === 'completed') {
                    $grouped['completed'][] = $mission;
                } else {
                    $grouped['active'][] = $mission;
                }
            }

            $this->success([
                'missions' => $missions,
                'grouped' => $grouped,
                'tracker' => $service->trackerForPlayer((int) $player['id'], 3),
            ], 'Missions.');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function claim(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'mission_code' => 'required|string|max:120',
            ]);
            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = $this->transaction(fn (): array => (new MissionService())->claimRewards(
                (int) $player['id'],
                (string) $payload['mission_code']
            ));

            $this->success($result, 'Mission rewards claimed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }
}
