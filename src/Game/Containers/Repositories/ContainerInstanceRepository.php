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

    public function findByPublicIdAndOwner(string $publicId, int $playerId, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ci.*, cd.code AS definition_code, cd.container_type, cd.allow_container_items
            FROM container_instances ci
            INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id
            WHERE ci.public_id = :public_id AND ci.owner_player_id = :player_id AND ci.status = :status
            LIMIT 1' . $this->lockClause($lock));
        $stmt->execute([
            'public_id' => $publicId,
            'player_id' => $playerId,
            'status' => 'active',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findBySourceItemInstanceId(int $sourceItemInstanceId, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ci.*, cd.code AS definition_code, cd.container_type, cd.allow_container_items
            FROM container_instances ci
            INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id
            WHERE ci.source_item_instance_id = :source_item_instance_id AND ci.status = :status
            LIMIT 1' . $this->lockClause($lock));
        $stmt->execute([
            'source_item_instance_id' => $sourceItemInstanceId,
            'status' => 'active',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findByPublicId(string $publicId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM container_instances WHERE public_id = :public_id LIMIT 1');
        $stmt->execute(['public_id' => $publicId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findById(int $id, bool $lock = false): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ci.*, cd.code AS definition_code, cd.container_type, cd.allow_container_items
            FROM container_instances ci
            INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id
            WHERE ci.id = :id
            LIMIT 1' . $this->lockClause($lock));
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function listActiveForPlayer(int $playerId, bool $lock = false): array
    {
        $stmt = $this->pdo()->prepare('SELECT
                ci.*,
                cd.code AS definition_code,
                cd.container_type,
                cd.allow_container_items
            FROM container_instances ci
            INNER JOIN container_definitions cd ON cd.id = ci.container_definition_id
            WHERE ci.owner_player_id = :player_id AND ci.status = :status
            ORDER BY ci.sort_order ASC, ci.id ASC' . $this->lockClause($lock));
        $stmt->execute([
            'player_id' => $playerId,
            'status' => 'active',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    private function lockClause(bool $lock): string
    {
        return $lock && $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
