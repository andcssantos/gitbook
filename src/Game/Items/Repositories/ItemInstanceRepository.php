<?php

namespace App\Game\Items\Repositories;

use App\Support\DB;
use App\Support\PublicId;
use PDO;

class ItemInstanceRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function create(array $data): int
    {
        $payload = array_merge([
            'public_id' => PublicId::uuid(),
            'quantity' => 1,
            'state' => 'available',
            'bind_type' => 'none',
        ], $data);

        $stmt = $this->pdo()->prepare('INSERT INTO item_instances (
            public_id,
            item_definition_id,
            owner_player_id,
            quantity,
            quality_value,
            quality_bucket,
            material_origin_id,
            item_name,
            current_durability,
            max_durability,
            bind_type,
            state
        ) VALUES (
            :public_id,
            :item_definition_id,
            :owner_player_id,
            :quantity,
            :quality_value,
            :quality_bucket,
            :material_origin_id,
            :item_name,
            :current_durability,
            :max_durability,
            :bind_type,
            :state
        )');

        $stmt->execute([
            'public_id' => $payload['public_id'],
            'item_definition_id' => $payload['item_definition_id'],
            'owner_player_id' => $payload['owner_player_id'],
            'quantity' => $payload['quantity'],
            'quality_value' => $payload['quality_value'] ?? null,
            'quality_bucket' => $payload['quality_bucket'] ?? null,
            'material_origin_id' => $payload['material_origin_id'] ?? null,
            'item_name' => $payload['item_name'] ?? null,
            'current_durability' => $payload['current_durability'] ?? null,
            'max_durability' => $payload['max_durability'] ?? null,
            'bind_type' => $payload['bind_type'],
            'state' => $payload['state'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
