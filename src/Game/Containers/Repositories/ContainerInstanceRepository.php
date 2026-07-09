<?php

namespace App\Game\Containers\Repositories;

use App\Support\DB;
use App\Support\PublicId;
use PDO;

class ContainerInstanceRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function findByOwnerAndDefinitionCode(int $playerId, string $definitionCode): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ci.* FROM container_instances ci INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id WHERE ci.owner_player_id = :player_id AND cd.code = :code AND ci.status = :status LIMIT 1');
        $stmt->execute([
            'player_id' => $playerId,
            'code' => $definitionCode,
            'status' => 'active',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function create(array $data): int
    {
        $payload = array_merge([
            'public_id' => PublicId::uuid(),
            'status' => 'active',
            'sort_order' => 0,
            'source_item_instance_id' => null,
        ], $data);

        $stmt = $this->pdo()->prepare('INSERT INTO container_instances (
            public_id,
            container_definition_id,
            owner_player_id,
            source_item_instance_id,
            name,
            grid_columns,
            grid_rows,
            status,
            sort_order
        ) VALUES (
            :public_id,
            :container_definition_id,
            :owner_player_id,
            :source_item_instance_id,
            :name,
            :grid_columns,
            :grid_rows,
            :status,
            :sort_order
        )');

        $stmt->execute([
            'public_id' => $payload['public_id'],
            'container_definition_id' => $payload['container_definition_id'],
            'owner_player_id' => $payload['owner_player_id'],
            'source_item_instance_id' => $payload['source_item_instance_id'],
            'name' => $payload['name'],
            'grid_columns' => $payload['grid_columns'],
            'grid_rows' => $payload['grid_rows'],
            'status' => $payload['status'],
            'sort_order' => $payload['sort_order'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
