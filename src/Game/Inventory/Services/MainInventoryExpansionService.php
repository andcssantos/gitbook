<?php

namespace App\Game\Inventory\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\InventoryException;
use App\Game\Market\Services\PlayerCurrencyService;
use App\Game\Player\Services\PlayerHudService;
use App\Support\DB;
use App\Utils\Config;
use PDO;

class MainInventoryExpansionService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(int $playerId, string $containerPublicId): array
    {
        $container = $this->requireMainContainer($playerId, $containerPublicId);
        $plan = $this->resolveNextTier($container);

        return [
            'container_public_id' => (string) $container['public_id'],
            'can_expand' => $plan !== null,
            'grid_before' => [
                'columns' => (int) $container['grid_columns'],
                'rows' => (int) $container['grid_rows'],
            ],
            'grid_after' => $plan ? [
                'columns' => (int) $plan['columns'],
                'rows' => (int) $plan['rows'],
            ] : null,
            'gold_cost' => $plan ? (int) $plan['gold_cost'] : null,
            'currency' => (string) Config::get('inventory.main_expansion.currency', 'gold'),
            'gold_balance' => (new PlayerCurrencyService($this->pdo()))->balance($playerId, 'gold'),
            'maxed' => $plan === null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function expand(int $playerId, string $containerPublicId): array
    {
        return DB::transaction(function () use ($playerId, $containerPublicId): array {
            $container = $this->requireMainContainer($playerId, $containerPublicId, true);
            $plan = $this->resolveNextTier($container);
            if ($plan === null) {
                throw new InventoryException(
                    'INVENTORY_EXPANSION_MAXED',
                    'Inventario principal ja esta no tamanho maximo.',
                    422
                );
            }

            $currency = (string) Config::get('inventory.main_expansion.currency', 'gold');
            $goldCost = (int) $plan['gold_cost'];
            $currencyService = new PlayerCurrencyService($this->pdo());
            $balanceAfter = $currencyService->debit(
                $playerId,
                $currency,
                $goldCost,
                'INVENTORY_EXPAND_FEE',
                'container',
                (string) $container['public_id'],
                [
                    'grid_before' => [
                        'columns' => (int) $container['grid_columns'],
                        'rows' => (int) $container['grid_rows'],
                    ],
                    'grid_after' => [
                        'columns' => (int) $plan['columns'],
                        'rows' => (int) $plan['rows'],
                    ],
                ]
            );

            $this->resizeContainer((int) $container['id'], (int) $plan['columns'], (int) $plan['rows']);

            InventoryStateService::forgetCombatSnapshot($playerId);
            $state = new InventoryStateService($this->pdo());
            $updatedPayload = $state->containerForPlayer($playerId, (string) $container['public_id']);
            $summary = $state->summaryForPlayer($playerId);
            $nextPreview = $this->preview($playerId, (string) $container['public_id']);

            return [
                'action' => 'expanded',
                'container_public_id' => (string) $container['public_id'],
                'grid_before' => [
                    'columns' => (int) $container['grid_columns'],
                    'rows' => (int) $container['grid_rows'],
                ],
                'grid_after' => [
                    'columns' => (int) $plan['columns'],
                    'rows' => (int) $plan['rows'],
                ],
                'gold_cost' => $goldCost,
                'gold_balance' => $balanceAfter,
                'currency' => $currency,
                'container' => $updatedPayload['container'] ?? $updatedPayload,
                'summary' => $summary,
                'player_hud' => (new PlayerHudService($this->pdo()))->forPlayer($playerId),
                'next_expansion' => $nextPreview,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMainContainer(int $playerId, string $containerPublicId, bool $lock = false): array
    {
        $container = (new ContainerRepository($this->pdo()))
            ->findInstanceByPublicIdForPlayer($containerPublicId, $playerId, $lock);

        if ($container === null) {
            throw new InventoryException('INVENTORY_CONTAINER_NOT_FOUND', 'Container nao encontrado.', 404);
        }

        $definitionCode = (string) ($container['definition_code'] ?? '');
        $type = strtoupper((string) ($container['container_type'] ?? $container['type'] ?? ''));
        $isMain = $type === 'MAIN_INVENTORY'
            || str_starts_with($definitionCode, 'main_inventory');

        if (!$isMain) {
            throw new InventoryException(
                'INVENTORY_EXPANSION_NOT_ALLOWED',
                'Apenas o inventario principal pode ser expandido.',
                422
            );
        }

        return $container;
    }

    /**
     * @param array<string, mixed> $container
     * @return array{columns:int,rows:int,gold_cost:int}|null
     */
    private function resolveNextTier(array $container): ?array
    {
        $columns = (int) ($container['grid_columns'] ?? 0);
        $rows = (int) ($container['grid_rows'] ?? 0);
        $baseColumns = (int) Config::get('inventory.main_expansion.base_columns', 12);
        $tiers = (array) Config::get('inventory.main_expansion.tiers', []);

        $targetColumns = $columns > 0 ? $columns : $baseColumns;

        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                continue;
            }
            $tierRows = (int) ($tier['rows'] ?? 0);
            $cost = (int) ($tier['gold_cost'] ?? 0);
            if ($tierRows <= $rows) {
                continue;
            }
            if ($cost < 0) {
                continue;
            }

            return [
                'columns' => $targetColumns,
                'rows' => $tierRows,
                'gold_cost' => $cost,
            ];
        }

        return null;
    }

    private function resizeContainer(int $containerId, int $columns, int $rows): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE container_instances
             SET grid_columns = :grid_columns, grid_rows = :grid_rows, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $containerId,
            'grid_columns' => $columns,
            'grid_rows' => $rows,
        ]);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
