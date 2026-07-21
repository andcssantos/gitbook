<?php

namespace App\Controllers\App\Api\Admin;

use App\Core\Controller;
use App\Game\Admin\Services\AdminAccessGuard;
use App\Game\Admin\Services\AdminBiomeService;
use App\Game\Inventory\InventoryException;
use App\Http\Request;
use App\Validation\ValidationException;

class AdminBiomesController extends Controller
{
    public function meta(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();
        $this->success((new AdminBiomeService())->meta(), 'Admin biome meta.');
    }

    public function index(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $query = Request::query();
            $result = (new AdminBiomeService())->list([
                'q' => (string) ($query['q'] ?? ''),
                'status' => (string) ($query['status'] ?? ''),
                'limit' => (int) ($query['limit'] ?? 50),
                'offset' => (int) ($query['offset'] ?? 0),
            ]);
            $this->success($result, 'Admin biomes listed.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function show(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $biome = (new AdminBiomeService())->getByCode((string) ($params['code'] ?? ''));
            $this->success(['biome' => $biome], 'Admin biome loaded.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function store(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $payload = $this->validate(Request::body(), [
                'code' => 'required|string|max:80',
                'name' => 'required|string|max:120',
                'summary' => 'nullable|string|max:4000',
                'status' => 'nullable|string|max:20',
                'sort_order' => 'nullable|int',
                'requires_expedition' => 'nullable|boolean',
                'default_duration_minutes' => 'nullable|int|min:1',
                'default_respawn_minutes' => 'nullable|int|min:0',
                'discovery_radius' => 'nullable|numeric',
                'map_width' => 'nullable|numeric',
                'map_height' => 'nullable|numeric',
                'spawn_x' => 'nullable|numeric',
                'spawn_y' => 'nullable|numeric',
                'map_node_x' => 'nullable|int',
                'map_node_y' => 'nullable|int',
                'background_url' => 'nullable|string|max:255',
                'world_art_url' => 'nullable|string|max:255',
                'world_pin_url' => 'nullable|string|max:255',
                'world_structure_url' => 'nullable|string|max:255',
                'monster_spawn_count' => 'nullable|int|min:0',
                'monster_elite_chance' => 'nullable|numeric',
                'monster_rare_chance' => 'nullable|numeric',
                'move_trap_chance' => 'nullable|numeric',
                'move_trap_damage_min' => 'nullable|int|min:0',
                'move_trap_damage_max' => 'nullable|int|min:0',
                'engage_radius' => 'nullable|numeric',
                'kills_to_boss' => 'nullable|int|min:1',
                'heal_on_kill_pct' => 'nullable|numeric',
                'combat_mode' => 'nullable|string|max:30',
                'wave_size' => 'nullable|int|min:1',
                'wave_pause_kills' => 'nullable|int|min:0',
                'season_featured' => 'nullable|boolean',
                'unlock' => 'nullable|array',
                'entry_requirements' => 'nullable|array',
                'landmarks' => 'nullable|array',
                'settings' => 'nullable|array',
                'monsters' => 'nullable|array',
            ]);

            $biome = (new AdminBiomeService())->create($payload);
            $this->audit('admin.biome.create', ['code' => $biome['code']]);
            $this->success(['biome' => $biome], 'Admin biome created.', 201);
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function update(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $code = (string) ($params['code'] ?? '');
            $payload = $this->validate(Request::body(), [
                'name' => 'required|string|max:120',
                'summary' => 'nullable|string|max:4000',
                'status' => 'nullable|string|max:20',
                'sort_order' => 'nullable|int',
                'requires_expedition' => 'nullable|boolean',
                'default_duration_minutes' => 'nullable|int|min:1',
                'default_respawn_minutes' => 'nullable|int|min:0',
                'discovery_radius' => 'nullable|numeric',
                'map_width' => 'nullable|numeric',
                'map_height' => 'nullable|numeric',
                'spawn_x' => 'nullable|numeric',
                'spawn_y' => 'nullable|numeric',
                'map_node_x' => 'nullable|int',
                'map_node_y' => 'nullable|int',
                'background_url' => 'nullable|string|max:255',
                'world_art_url' => 'nullable|string|max:255',
                'world_pin_url' => 'nullable|string|max:255',
                'world_structure_url' => 'nullable|string|max:255',
                'monster_spawn_count' => 'nullable|int|min:0',
                'monster_elite_chance' => 'nullable|numeric',
                'monster_rare_chance' => 'nullable|numeric',
                'move_trap_chance' => 'nullable|numeric',
                'move_trap_damage_min' => 'nullable|int|min:0',
                'move_trap_damage_max' => 'nullable|int|min:0',
                'engage_radius' => 'nullable|numeric',
                'kills_to_boss' => 'nullable|int|min:1',
                'heal_on_kill_pct' => 'nullable|numeric',
                'combat_mode' => 'nullable|string|max:30',
                'wave_size' => 'nullable|int|min:1',
                'wave_pause_kills' => 'nullable|int|min:0',
                'season_featured' => 'nullable|boolean',
                'unlock' => 'nullable|array',
                'entry_requirements' => 'nullable|array',
                'landmarks' => 'nullable|array',
                'settings' => 'nullable|array',
                'monsters' => 'nullable|array',
            ]);

            $biome = (new AdminBiomeService())->update($code, $payload);
            $this->audit('admin.biome.update', ['code' => $biome['code']]);
            $this->success(['biome' => $biome], 'Admin biome updated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }
}
