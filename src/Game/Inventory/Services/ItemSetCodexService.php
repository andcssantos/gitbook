<?php

namespace App\Game\Inventory\Services;

use App\Support\DB;
use PDO;

class ItemSetCodexService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function forPlayer(int $playerId): array
    {
        if (!$this->tableExists('item_sets')) {
            return ['sets' => [], 'wanted_definition_codes' => []];
        }

        $wanted = $this->wantedDefinitionCodes($playerId);
        $ownedByDefinition = $this->ownedDefinitionMap($playerId);
        $equippedByDefinition = $this->equippedDefinitionMap($playerId);

        $sets = [];
        $setRows = $this->pdo()->query(
            "SELECT id, code, name, description, aura_color
             FROM item_sets
             WHERE status = 'active'
             ORDER BY name ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($setRows as $setRow) {
            $setId = (int) $setRow['id'];
            $pieces = $this->piecesForSet($setId);
            $ownedCount = 0;
            $equippedCount = 0;
            $mappedPieces = [];

            foreach ($pieces as $piece) {
                $definitionId = (int) $piece['item_definition_id'];
                $definitionCode = (string) $piece['definition_code'];
                $owned = $ownedByDefinition[$definitionId] ?? null;
                $equipped = $equippedByDefinition[$definitionId] ?? null;
                $status = 'missing';
                if ($equipped !== null) {
                    $status = 'equipped';
                    $equippedCount += 1;
                    $ownedCount += 1;
                } elseif ($owned !== null) {
                    $status = 'owned';
                    $ownedCount += 1;
                }

                $mappedPieces[] = [
                    'piece_key' => (string) $piece['piece_key'],
                    'sort_order' => (int) $piece['sort_order'],
                    'definition_code' => $definitionCode,
                    'definition_name' => (string) $piece['definition_name'],
                    'equip_slot_code' => $piece['equip_slot_code'] !== null ? (string) $piece['equip_slot_code'] : null,
                    'status' => $status,
                    'owned_item_public_id' => $owned['public_id'] ?? ($equipped['public_id'] ?? null),
                    'wishlisted' => in_array($definitionCode, $wanted, true)
                        || (bool) ($owned['wishlist'] ?? false)
                        || (bool) ($equipped['wishlist'] ?? false),
                ];
            }

            $bonuses = $this->bonusesForSet($setId);

            $sets[] = [
                'set_code' => (string) $setRow['code'],
                'set_name' => (string) $setRow['name'],
                'description' => $setRow['description'] !== null ? (string) $setRow['description'] : null,
                'aura_color' => (string) $setRow['aura_color'],
                'owned_pieces' => $ownedCount,
                'equipped_pieces' => $equippedCount,
                'total_pieces' => count($mappedPieces),
                'pieces' => $mappedPieces,
                'bonuses' => $bonuses,
            ];
        }

        return [
            'sets' => $sets,
            'wanted_definition_codes' => $wanted,
        ];
    }

    public function toggleDefinitionWishlist(int $playerId, string $definitionCode, bool $wanted): array
    {
        $definitionCode = trim($definitionCode);
        if ($definitionCode === '') {
            return ['definition_code' => $definitionCode, 'wishlisted' => false];
        }

        $stmt = $this->pdo()->prepare('SELECT id FROM item_definitions WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $definitionCode]);
        $definitionId = (int) $stmt->fetchColumn();
        if ($definitionId <= 0) {
            return ['definition_code' => $definitionCode, 'wishlisted' => false];
        }

        if (!$this->tableExists('player_definition_wishlist')) {
            return ['definition_code' => $definitionCode, 'wishlisted' => false];
        }

        if ($wanted) {
            if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $insert = $this->pdo()->prepare(
                    'INSERT INTO player_definition_wishlist (player_id, item_definition_id)
                     VALUES (:player_id, :item_definition_id)
                     ON CONFLICT(player_id, item_definition_id) DO NOTHING'
                );
            } else {
                $insert = $this->pdo()->prepare(
                    'INSERT IGNORE INTO player_definition_wishlist (player_id, item_definition_id)
                     VALUES (:player_id, :item_definition_id)'
                );
            }
            $insert->execute([
                'player_id' => $playerId,
                'item_definition_id' => $definitionId,
            ]);
        } else {
            $delete = $this->pdo()->prepare(
                'DELETE FROM player_definition_wishlist
                 WHERE player_id = :player_id AND item_definition_id = :item_definition_id'
            );
            $delete->execute([
                'player_id' => $playerId,
                'item_definition_id' => $definitionId,
            ]);
        }

        return [
            'definition_code' => $definitionCode,
            'wishlisted' => $wanted,
        ];
    }

    private function piecesForSet(int $setId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT isp.piece_key, isp.sort_order, isp.item_definition_id,
                    id.code AS definition_code, id.name AS definition_name,
                    id.equip_slot_code
             FROM item_set_pieces isp
             INNER JOIN item_definitions id ON id.id = isp.item_definition_id
             WHERE isp.item_set_id = :set_id
             ORDER BY isp.sort_order ASC, id.name ASC'
        );
        $stmt->execute(['set_id' => $setId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function bonusesForSet(int $setId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT isb.required_pieces, isb.description, ipd.code, ipd.name, ipd.unit,
                    COALESCE(isb.integer_value, isb.numeric_value, 0) AS value
             FROM item_set_bonuses isb
             INNER JOIN item_property_definitions ipd ON ipd.id = isb.property_definition_id
             WHERE isb.item_set_id = :set_id
             ORDER BY isb.required_pieces ASC, ipd.name ASC'
        );
        $stmt->execute(['set_id' => $setId]);

        return array_map(static fn (array $row): array => [
            'required_pieces' => (int) $row['required_pieces'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'unit' => $row['unit'] !== null ? (string) $row['unit'] : null,
            'value' => (float) $row['value'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function ownedDefinitionMap(int $playerId): array
    {
        $hasFlags = $this->tableExists('player_item_flags');
        $sql = $hasFlags
            ? "SELECT ii.id, ii.public_id, ii.item_definition_id, COALESCE(f.wishlist, 0) AS wishlist
                 FROM item_instances ii
                 LEFT JOIN player_item_flags f ON f.player_id = :player_id AND f.item_instance_id = ii.id
                 WHERE ii.owner_player_id = :player_id2
                   AND ii.state IN ('available', 'equipped', 'socketed')
                 ORDER BY ii.id ASC"
            : "SELECT ii.id, ii.public_id, ii.item_definition_id, 0 AS wishlist
                 FROM item_instances ii
                 WHERE ii.owner_player_id = :player_id
                   AND ii.state IN ('available', 'equipped', 'socketed')
                 ORDER BY ii.id ASC";
        $stmt = $this->pdo()->prepare($sql);
        if ($hasFlags) {
            $stmt->execute(['player_id' => $playerId, 'player_id2' => $playerId]);
        } else {
            $stmt->execute(['player_id' => $playerId]);
        }
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $definitionId = (int) $row['item_definition_id'];
            if (isset($map[$definitionId])) {
                continue;
            }
            $map[$definitionId] = [
                'public_id' => (string) $row['public_id'],
                'wishlist' => (bool) $row['wishlist'],
            ];
        }

        return $map;
    }

    private function equippedDefinitionMap(int $playerId): array
    {
        $hasFlags = $this->tableExists('player_item_flags');
        $sql = $hasFlags
            ? 'SELECT ii.public_id, ii.item_definition_id, COALESCE(f.wishlist, 0) AS wishlist
                 FROM player_equipment pe
                 INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
                 LEFT JOIN player_item_flags f ON f.player_id = pe.player_id AND f.item_instance_id = ii.id
                 WHERE pe.player_id = :player_id'
            : 'SELECT ii.public_id, ii.item_definition_id, 0 AS wishlist
                 FROM player_equipment pe
                 INNER JOIN item_instances ii ON ii.id = pe.item_instance_id
                 WHERE pe.player_id = :player_id';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['player_id' => $playerId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['item_definition_id']] = [
                'public_id' => (string) $row['public_id'],
                'wishlist' => (bool) $row['wishlist'],
            ];
        }

        return $map;
    }

    private function wantedDefinitionCodes(int $playerId): array
    {
        if (!$this->tableExists('player_definition_wishlist')) {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            'SELECT id.code
             FROM player_definition_wishlist w
             INNER JOIN item_definitions id ON id.id = w.item_definition_id
             WHERE w.player_id = :player_id
             ORDER BY id.name ASC'
        );
        $stmt->execute(['player_id' => $playerId]);

        return array_map(static fn ($code): string => (string) $code, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute(['table' => $table]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1'
        );
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
