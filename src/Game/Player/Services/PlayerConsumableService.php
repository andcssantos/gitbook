<?php

namespace App\Game\Player\Services;

use App\Game\Items\Repositories\ItemInstanceRepository;
use App\Support\DB;
use PDO;

/**
 * Consome comida/itens de uso fora (ou dentro) da expedição via inventário.
 */
class PlayerConsumableService
{
    public function __construct(
        private ?PDO $pdo = null,
        private ?PlayerVitalsService $vitals = null,
        private ?ItemInstanceRepository $items = null
    ) {
        $this->vitals ??= new PlayerVitalsService($this->pdo);
        $this->items ??= new ItemInstanceRepository($this->pdo);
    }

    /** @return array<string, mixed> */
    public function consume(int $playerId, string $itemPublicId): array
    {
        $item = $this->loadOwnedItem($playerId, $itemPublicId);
        $effect = $this->resolveUseEffect($item);
        if ($effect === null || (string) ($effect['kind'] ?? '') !== 'food') {
            throw new \RuntimeException('This item cannot be consumed as food.');
        }

        $energy = max(0, (float) ($effect['energy'] ?? 0));
        $hunger = max(0, (float) ($effect['hunger'] ?? 0));
        $thirst = max(0, (float) ($effect['thirst'] ?? 0));
        $vitals = $this->vitals->restore($playerId, $energy, $hunger, $thirst);

        $buff = null;
        $duration = max(0, (int) ($effect['duration_seconds'] ?? 0));
        $stats = (array) ($effect['stats'] ?? []);
        if ($duration > 0 || $stats !== []) {
            $buffCode = (string) ($effect['buff_code'] ?? ($item['definition_code'] . '_food'));
            $buff = [
                'code' => $buffCode,
                'label' => (string) ($effect['name'] ?? $item['name']),
                'source_item_public_id' => (string) $item['public_id'],
                'source_definition_code' => (string) $item['definition_code'],
                'consumed_at' => date('Y-m-d H:i:s'),
                'expires_at' => $duration > 0 ? date('Y-m-d H:i:s', time() + $duration) : null,
                'stats' => $stats,
            ];
            $vitals = $this->vitals->applyBuff($playerId, $buff);
        }

        $remaining = $this->consumeOne($item);

        return [
            'consumed' => true,
            'item_public_id' => (string) $item['public_id'],
            'definition_code' => (string) $item['definition_code'],
            'remaining_quantity' => $remaining,
            'effect' => $effect,
            'buff' => $buff,
            'vitals' => $vitals,
        ];
    }

    /** @return array<string, mixed> */
    private function loadOwnedItem(int $playerId, string $itemPublicId): array
    {
        $stmt = $this->pdo()->prepare('SELECT
                ii.id,
                ii.public_id,
                ii.quantity,
                ii.state,
                id.code AS definition_code,
                id.name,
                id.base_config
            FROM item_instances ii
            INNER JOIN item_definitions id ON id.id = ii.item_definition_id
            WHERE ii.public_id = :public_id AND ii.owner_player_id = :player_id
            LIMIT 1');
        $stmt->execute([
            'public_id' => $itemPublicId,
            'player_id' => $playerId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Item not found.');
        }
        if ((string) ($row['state'] ?? '') === 'destroyed') {
            throw new \RuntimeException('Item is not available.');
        }

        return $row;
    }

    /** @param array<string, mixed> $item */
    private function resolveUseEffect(array $item): ?array
    {
        $config = $item['base_config'] ?? null;
        if (is_string($config) && $config !== '') {
            try {
                $config = json_decode($config, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $config = [];
            }
        }
        if (!is_array($config)) {
            return null;
        }
        $effect = $config['use_effect'] ?? null;

        return is_array($effect) ? $effect : null;
    }

    /** @param array<string, mixed> $item */
    private function consumeOne(array $item): int
    {
        $id = (int) ($item['id'] ?? 0);
        $qty = max(1, (int) ($item['quantity'] ?? 1));
        if ($qty <= 1) {
            // quantity tem CHECK (>= 1): remove placement e apaga a instancia.
            $this->pdo()->prepare('DELETE FROM container_items WHERE item_instance_id = :id')->execute(['id' => $id]);
            $this->pdo()->prepare('DELETE FROM player_equipment WHERE item_instance_id = :id')->execute(['id' => $id]);
            $this->pdo()->prepare('DELETE FROM item_instances WHERE id = :id')->execute(['id' => $id]);

            return 0;
        }

        $remaining = $qty - 1;
        $this->pdo()->prepare('UPDATE item_instances SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $id, 'quantity' => $remaining]);

        return $remaining;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
