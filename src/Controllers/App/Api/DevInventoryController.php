<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Inventory\DTO\GrantItemRequest;
use App\Game\Inventory\InventoryException;
use App\Game\Inventory\Services\InventoryAutoPlacementService;
use App\Game\Player\Services\PlayerResolver;
use App\Http\HttpException;
use App\Http\Request;
use App\Utils\Config;
use App\Validation\ValidationException;

class DevInventoryController extends Controller
{
    public function grantItem(array $params = []): void
    {
        $this->assertDevEnvironment();

        try {
            $payload = $this->validate(Request::body(), [
                'item_definition_code' => 'required|string|max:80',
                'quantity' => 'required|int|min:1',
                'quality_bucket' => 'nullable|string|max:40',
                'quality_value' => 'nullable|numeric',
                'material_origin_code' => 'nullable|string|max:80',
            ]);

            $player = (new PlayerResolver())->requireCurrentPlayer();
            $result = (new InventoryAutoPlacementService())->grantAndPlace(GrantItemRequest::fromArray((int) $player['id'], $payload));

            $this->success($result, 'Inventory item granted and auto-placed.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (InventoryException $e) {
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    private function assertDevEnvironment(): void
    {
        $env = (string) Config::get('app.env', 'production');
        if ($env === 'production') {
            throw new HttpException('Not found.', 404);
        }
    }
}
