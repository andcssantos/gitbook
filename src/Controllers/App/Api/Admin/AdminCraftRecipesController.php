<?php

namespace App\Controllers\App\Api\Admin;

use App\Core\Controller;
use App\Game\Admin\Services\AdminAccessGuard;
use App\Game\Admin\Services\AdminCraftRecipeService;
use App\Game\Inventory\InventoryException;
use App\Http\Request;
use App\Validation\ValidationException;

class AdminCraftRecipesController extends Controller
{
    public function meta(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();
        $this->success((new AdminCraftRecipeService())->meta(), 'Admin craft recipe meta.');
    }

    public function index(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $query = Request::query();
            $result = (new AdminCraftRecipeService())->list([
                'q' => (string) ($query['q'] ?? ''),
                'status' => (string) ($query['status'] ?? ''),
                'workspace' => (string) ($query['workspace'] ?? ''),
                'limit' => (int) ($query['limit'] ?? 50),
                'offset' => (int) ($query['offset'] ?? 0),
            ]);
            $this->success($result, 'Admin craft recipes listed.');
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function show(array $params = []): void
    {
        (new AdminAccessGuard())->assertCanManageContent();

        try {
            $recipe = (new AdminCraftRecipeService())->getByCode((string) ($params['code'] ?? ''));
            $this->success(['recipe' => $recipe], 'Admin craft recipe loaded.');
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
                'workspace' => 'nullable|string|max:40',
                'discovery' => 'nullable|string|max:20',
                'gold_fee' => 'nullable|int|min:0',
                'description' => 'nullable|string|max:4000',
                'status' => 'nullable|string|max:20',
                'sort_order' => 'nullable|int',
                'requirements' => 'nullable|array',
                'outputs' => 'nullable|array',
            ]);

            $recipe = (new AdminCraftRecipeService())->create($payload);
            $this->audit('admin.craft_recipe.create', ['code' => $recipe['code']]);
            $this->success(['recipe' => $recipe], 'Admin craft recipe created.', 201);
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
                'workspace' => 'nullable|string|max:40',
                'discovery' => 'nullable|string|max:20',
                'gold_fee' => 'nullable|int|min:0',
                'description' => 'nullable|string|max:4000',
                'status' => 'nullable|string|max:20',
                'sort_order' => 'nullable|int',
                'requirements' => 'nullable|array',
                'outputs' => 'nullable|array',
            ]);

            $recipe = (new AdminCraftRecipeService())->update($code, $payload);
            $this->audit('admin.craft_recipe.update', ['code' => $recipe['code']]);
            $this->success(['recipe' => $recipe], 'Admin craft recipe updated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }
}
