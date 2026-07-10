<?php

namespace App\Game\Market\Services;

use App\Game\Containers\Repositories\ContainerRepository;
use App\Game\Inventory\InventoryException;
use App\Support\DB;
use PDO;

class ItemMarketEligibilityService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function canSellNpc(array $item): bool
    {
        return $this->evaluate($item) === null;
    }

    public function canListOnMarket(array $item): bool
    {
        return $this->evaluate($item) === null;
    }

    public function assertSellable(array $item): void
    {
        $reason = $this->evaluate($item);
        if ($reason !== null) {
            throw new InventoryException('MARKET_ITEM_NOT_SELLABLE', $reason, 422);
        }
    }

    public function evaluate(array $item): ?string
    {
        if (!(bool) ($item['tradeable'] ?? $item['definition']['tradeable'] ?? false)) {
            return 'Este item nao pode ser vendido.';
        }

        if ((bool) ($item['is_collectible'] ?? $item['definition']['is_collectible'] ?? false)) {
            return 'Itens de colecao nao podem ser vendidos.';
        }

        if ((bool) ($item['is_event_item'] ?? $item['definition']['is_event_item'] ?? false)) {
            return 'Itens de evento nao podem ser vendidos.';
        }

        $bindType = strtolower(trim((string) ($item['bind_type'] ?? 'none')));
        if ($bindType !== '' && $bindType !== 'none') {
            return 'Itens vinculados nao podem ser vendidos.';
        }

        $state = strtolower(trim((string) ($item['state'] ?? 'available')));
        if ($state !== 'available') {
            return 'O item precisa estar disponivel para venda.';
        }

        if ($this->isEquipped($item)) {
            return 'Desequipe o item antes de vender.';
        }

        if ($this->isListed($item)) {
            return 'Este item ja esta anunciado no mercado.';
        }

        if ((bool) ($item['is_container'] ?? $item['definition']['is_container'] ?? false)) {
            $containers = new ContainerRepository($this->pdo());
            $linked = $containers->findInstanceBySourceItemId((int) ($item['item_instance_id'] ?? $item['id'] ?? 0));
            if ($linked !== null && $containers->countItems((int) $linked['id']) > 0) {
                return 'Esvazie o container antes de vender.';
            }
        }

        return null;
    }

    private function isEquipped(array $item): bool
    {
        $itemId = (int) ($item['item_instance_id'] ?? $item['id'] ?? 0);
        $playerId = (int) ($item['owner_player_id'] ?? 0);
        if ($itemId <= 0 || $playerId <= 0) {
            return false;
        }

        $stmt = $this->pdo()->prepare('SELECT item_instance_id FROM player_equipment WHERE player_id = :player_id AND item_instance_id = :item_instance_id LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'item_instance_id' => $itemId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function isListed(array $item): bool
    {
        if (!$this->tableExists('market_listings')) {
            return false;
        }

        $itemId = (int) ($item['item_instance_id'] ?? $item['id'] ?? 0);
        if ($itemId <= 0) {
            return false;
        }

        $stmt = $this->pdo()->prepare("SELECT id FROM market_listings WHERE item_instance_id = :item_instance_id AND status = 'active' LIMIT 1");
        $stmt->execute(['item_instance_id' => $itemId]);

        return $stmt->fetchColumn() !== false;
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return $stmt->fetchColumn() !== false;
        }

        return $this->pdo()->query('SHOW TABLES LIKE ' . $this->pdo()->quote($table))->fetchColumn() !== false;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
