<?php

namespace App\Game\Inventory\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Containers\Services\PhysicalContainerLinkService;
use App\Game\Items\Repositories\ItemDefinitionRepository;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Http\HttpException;
use App\Support\DB;
use PDO;
use Throwable;

class StarterInventoryService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function ensureForPlayer(int $playerId, bool $createStarterItems = true): array
    {
        return $this->transaction(function () use ($playerId, $createStarterItems): array {
            $containers = new ContainerRepository($this->pdo());

            $containerIds = $this->ensureRequiredContainers($containers, $playerId);
            $placedItems = $containers->countItems($containerIds['main_inventory_level_1']);

            if ($placedItems > 0 || !$createStarterItems) {
                return [
                    'created' => false,
                    'containers' => $containerIds,
                    'main_container_id' => $containerIds['main_inventory_level_1'],
                    'placed_items' => $placedItems,
                ];
            }

            return $this->createStarterItems($playerId, $containerIds['main_inventory_level_1']);
        });
    }

    private function ensureRequiredContainers(ContainerRepository $containers, int $playerId): array
    {
        $ids = [];
        $sortOrder = 10;
        foreach (['main_inventory_level_1', 'market_delivery', 'expedition_carry'] as $code) {
            $existing = $containers->findInstanceForPlayer($playerId, $code);
            if ($existing !== null) {
                $ids[$code] = (int) $existing['id'];
                $sortOrder += 10;
                continue;
            }

            $definition = $this->required($containers->findDefinition($code), "Container definition not found: {$code}");
            $ids[$code] = $containers->createInstanceFromDefinition($definition, $playerId, [
                'sort_order' => $sortOrder,
            ]);
            $sortOrder += 10;
        }

        return $ids;
    }

    private function createStarterItems(int $playerId, int $mainContainerId): array
    {
        $containers = new ContainerRepository($this->pdo());
        $itemDefinitions = new ItemDefinitionRepository($this->pdo());
        $items = new ItemInstanceRepository($this->pdo());

        $placed = [];
        foreach ($this->starterItems() as $entry) {
            $definition = $this->required($itemDefinitions->findActiveByCode($entry['code']), "Item definition not found: {$entry['code']}");

            $itemId = $items->create([
                'item_definition_id' => (int) $definition['id'],
                'owner_player_id' => $playerId,
                'quantity' => $entry['quantity'],
                'quality_value' => $entry['quality_value'],
                'quality_bucket' => $entry['quality_bucket'],
                'item_name' => $entry['name'] ?? $definition['name'],
                'current_durability' => $entry['durability'],
                'max_durability' => $entry['durability'],
            ]);

            $containers->placeItem([
                'container_instance_id' => $mainContainerId,
                'item_instance_id' => $itemId,
                'grid_x' => $entry['x'],
                'grid_y' => $entry['y'],
                'grid_w' => $entry['w'],
                'grid_h' => $entry['h'],
            ]);

            if ($entry['code'] === 'small_leather_backpack') {
                $backpackItem = $items->findByPublicIdAndOwner((string) $this->publicIdForItem($itemId), $playerId, true);
                if ($backpackItem !== null) {
                    (new PhysicalContainerLinkService($this->pdo))->ensureForItem($playerId, $backpackItem, 40);
                }
            }

            $placed[] = [
                'item_id' => $itemId,
                'code' => $entry['code'],
                'grid' => [
                    'x' => $entry['x'],
                    'y' => $entry['y'],
                    'w' => $entry['w'],
                    'h' => $entry['h'],
                ],
            ];
        }

        return [
            'created' => true,
            'main_container_id' => $mainContainerId,
            'placed_items' => count($placed),
            'items' => $placed,
        ];
    }

    private function starterItems(): array
    {
        return [
            [
                'code' => 'stone_pickaxe',
                'quantity' => 1,
                'quality_value' => 50.000,
                'quality_bucket' => 'common',
                'durability' => 80,
                'x' => 0,
                'y' => 0,
                'w' => 2,
                'h' => 3,
            ],
            [
                'code' => 'wood',
                'quantity' => 10,
                'quality_value' => 40.000,
                'quality_bucket' => 'common',
                'durability' => null,
                'x' => 2,
                'y' => 0,
                'w' => 1,
                'h' => 2,
            ],
            [
                'code' => 'stone',
                'quantity' => 10,
                'quality_value' => 35.000,
                'quality_bucket' => 'common',
                'durability' => null,
                'x' => 3,
                'y' => 0,
                'w' => 1,
                'h' => 1,
            ],
            [
                'code' => 'small_leather_backpack',
                'quantity' => 1,
                'quality_value' => 45.000,
                'quality_bucket' => 'common',
                'durability' => null,
                'x' => 4,
                'y' => 0,
                'w' => 2,
                'h' => 2,
            ],
        ];
    }

    private function required(?array $row, string $message): array
    {
        if ($row === null) {
            throw new HttpException($message, 500);
        }

        return $row;
    }

    private function publicIdForItem(int $itemId): string
    {
        $stmt = $this->pdo()->prepare('SELECT public_id FROM item_instances WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $itemId]);

        return (string) $stmt->fetchColumn();
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
                if ($started && $this->pdo->inTransaction()) {
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

        return DB::transaction(fn (): mixed => $callback());
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
