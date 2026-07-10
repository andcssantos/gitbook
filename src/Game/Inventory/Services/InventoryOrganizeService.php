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
    private const RARITY_ORDER = [
        'divine' => 70,
        'unique' => 65,
        'legendary' => 60,
        'epic' => 50,
        'rare' => 40,
        'magic' => 30,
        'uncommon' => 20,
        'common' => 10,
    ];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function organize(int $playerId, string $containerPublicId, string $mode = 'compact'): array
    {
        $normalizedMode = $this->normalizeMode($mode);

        return $this->transaction(function () use ($playerId, $containerPublicId, $normalizedMode): array {
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
                    'mode' => $normalizedMode,
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

            usort($entries, fn (array $a, array $b): int => $this->compareEntries($a, $b, $normalizedMode));

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

                $nextRotated = (bool) ($spot['rotated'] ?? $rotated);

                $planned[] = [
                    'item_instance_id' => (int) $placement['item_instance_id'],
                    'grid_x' => (int) $spot['grid_x'],
                    'grid_y' => (int) $spot['grid_y'],
                    'grid_w' => (int) $spot['grid_w'],
                    'grid_h' => (int) $spot['grid_h'],
                    'rotated' => $nextRotated ? 1 : 0,
                ];

                if (
                    (int) $placement['grid_x'] !== (int) $spot['grid_x']
                    || (int) $placement['grid_y'] !== (int) $spot['grid_y']
                    || (int) $placement['grid_w'] !== (int) $spot['grid_w']
                    || (int) $placement['grid_h'] !== (int) $spot['grid_h']
                    || (int) ($placement['rotated'] ?? 0) !== ($nextRotated ? 1 : 0)
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
                'mode' => $normalizedMode,
                'moved_items' => $moved,
            ];
        });
    }

    private function normalizeMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        return match ($normalized) {
            'type', 'rarity', 'size', 'name', 'compact' => $normalized,
            default => 'compact',
        };
    }

    private function compareEntries(array $a, array $b, string $mode): int
    {
        return match ($mode) {
            'type' => $this->compareType($a['item'], $b['item']) ?: $this->compareSize($a, $b),
            'rarity' => $this->compareRarity($a['item'], $b['item']) ?: $this->compareSize($a, $b),
            'name' => $this->compareName($a['item'], $b['item']) ?: $this->compareSize($a, $b),
            'size' => $this->compareSize($a, $b),
            default => $this->compareSize($a, $b),
        };
    }

    private function compareSize(array $a, array $b): int
    {
        $areaCompare = $b['area'] <=> $a['area'];
        if ($areaCompare !== 0) {
            return $areaCompare;
        }

        return ((int) $a['placement']['id']) <=> ((int) $b['placement']['id']);
    }

    private function compareType(array $left, array $right): int
    {
        $leftCode = (string) ($left['category_code'] ?? 'zzz');
        $rightCode = (string) ($right['category_code'] ?? 'zzz');

        return $leftCode <=> $rightCode;
    }

    private function compareRarity(array $left, array $right): int
    {
        $leftScore = self::RARITY_ORDER[(string) ($left['quality_bucket'] ?? 'common')] ?? 0;
        $rightScore = self::RARITY_ORDER[(string) ($right['quality_bucket'] ?? 'common')] ?? 0;

        return $rightScore <=> $leftScore;
    }

    private function compareName(array $left, array $right): int
    {
        $leftName = strtolower((string) ($left['item_name'] ?? $left['definition_code'] ?? ''));
        $rightName = strtolower((string) ($right['item_name'] ?? $right['definition_code'] ?? ''));

        return $leftName <=> $rightName;
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
