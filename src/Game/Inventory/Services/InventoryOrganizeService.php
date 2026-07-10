<?php

namespace App\Game\Inventory\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\InventoryException;
use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Support\DB;
use PDO;
use Throwable;

class InventoryOrganizeService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function organize(int $playerId, string $containerPublicId): array
    {
        return $this->transaction(function () use ($playerId, $containerPublicId): array {
            $containers = new ContainerRepository($this->pdo());
            $items = new ItemInstanceRepository($this->pdo());

            $container = $containers->findInstanceByPublicIdForPlayer($containerPublicId, $playerId, true);
            if ($container === null) {
                if ($containers->findInstanceByPublicId($containerPublicId) !== null) {
                    throw new InventoryException('INVENTORY_FORBIDDEN', 'Inventory container does not belong to the authenticated player.', 403);
                }

                throw new InventoryException('INVENTORY_CONTAINER_NOT_FOUND', 'Inventory container was not found.', 404);
            }

            $placements = $containers->listPlacements((int) $container['id'], true);
            if ($placements === []) {
                return [
                    'container_public_id' => $containerPublicId,
                    'moved_items' => 0,
                ];
            }

            $entries = [];
            foreach ($placements as $placement) {
                $item = $items->findById((int) $placement['item_instance_id']);
                if ($item === null) {
                    continue;
                }

                $entries[] = [
                    'placement' => $placement,
                    'item' => $item,
                    'area' => (int) $placement['grid_w'] * (int) $placement['grid_h'],
                ];
            }

            usort($entries, function (array $a, array $b): int {
                $areaCompare = $b['area'] <=> $a['area'];
                if ($areaCompare !== 0) {
                    return $areaCompare;
                }

                return ((int) $a['placement']['id']) <=> ((int) $b['placement']['id']);
            });

            $validator = new InventoryPlacementValidator();
            $finder = new GridFreeSpaceFinder($validator);
            $planned = [];
            $moved = 0;

            foreach ($entries as $entry) {
                $item = $entry['item'];
                $placement = $entry['placement'];
                $rotated = (bool) ($placement['rotated'] ?? false);
                $spot = $finder->findFirst($item, $container, $planned, $rotated);

                if ($spot === null) {
                    throw new InventoryException('INVENTORY_ORGANIZE_FAILED', 'Nao foi possivel reorganizar todos os itens neste container.', 409);
                }

                $planned[] = [
                    'item_instance_id' => (int) $placement['item_instance_id'],
                    'grid_x' => (int) $spot['grid_x'],
                    'grid_y' => (int) $spot['grid_y'],
                    'grid_w' => (int) $spot['grid_w'],
                    'grid_h' => (int) $spot['grid_h'],
                    'rotated' => $rotated ? 1 : 0,
                ];

                if (
                    (int) $placement['grid_x'] !== (int) $spot['grid_x']
                    || (int) $placement['grid_y'] !== (int) $spot['grid_y']
                    || (int) $placement['grid_w'] !== (int) $spot['grid_w']
                    || (int) $placement['grid_h'] !== (int) $spot['grid_h']
                ) {
                    $moved++;
                }
            }

            foreach ($planned as $index => $nextPlacement) {
                $placementId = (int) $entries[$index]['placement']['id'];
                $containers->updatePlacement($placementId, [
                    'grid_x' => $nextPlacement['grid_x'],
                    'grid_y' => $nextPlacement['grid_y'],
                    'grid_w' => $nextPlacement['grid_w'],
                    'grid_h' => $nextPlacement['grid_h'],
                    'rotated' => $nextPlacement['rotated'],
                ]);
            }

            return [
                'container_public_id' => $containerPublicId,
                'moved_items' => $moved,
            ];
        });
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
