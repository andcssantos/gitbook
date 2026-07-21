<?php

namespace App\Game\Admin\Services;

use App\Game\Inventory\InventoryException;
use App\Support\DB;
use PDO;
use Throwable;

class AdminItemAffixDefinitionService
{
    private const AFFIX_TYPES = ['prefix', 'suffix', 'implicit', 'gem', 'upgrade'];
    private const STATUSES = ['active', 'inactive', 'draft'];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return array{definitions: list<array<string, mixed>>, total: int, limit: int, offset: int} */
    public function list(array $filters = []): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $affixType = trim((string) ($filters['affix_type'] ?? ''));
        $propertyCode = trim((string) ($filters['property_code'] ?? ''));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $where = ['1=1'];
        $params = [];

        if ($q !== '') {
            $where[] = '(a.code LIKE :q OR a.name LIKE :q OR p.code LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($status !== '') {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }
        if ($affixType !== '') {
            $where[] = 'a.affix_type = :affix_type';
            $params['affix_type'] = $affixType;
        }
        if ($propertyCode !== '') {
            $where[] = 'p.code = :property_code';
            $params['property_code'] = $propertyCode;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo()->prepare(
            "SELECT COUNT(*)
             FROM item_affix_definitions a
             INNER JOIN item_property_definitions p ON p.id = a.property_definition_id
             WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT
                a.*,
                p.code AS property_code,
                p.name AS property_name,
                p.equipment_scope AS property_equipment_scope
            FROM item_affix_definitions a
            INNER JOIN item_property_definitions p ON p.id = a.property_definition_id
            WHERE {$whereSql}
            ORDER BY a.code ASC
            LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'definitions' => array_map(fn (array $row): array => $this->mapRow($row), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** @return array<string, mixed> */
    public function getByCode(string $code): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT
                a.*,
                p.code AS property_code,
                p.name AS property_name,
                p.equipment_scope AS property_equipment_scope
             FROM item_affix_definitions a
             INNER JOIN item_property_definitions p ON p.id = a.property_definition_id
             WHERE a.code = :code
             LIMIT 1'
        );
        $stmt->execute(['code' => $this->normalizeCode($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InventoryException('ADMIN_AFFIX_NOT_FOUND', 'Affix definition was not found.', 404);
        }

        return $this->mapRow($row);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        return $this->transaction(function () use ($payload): array {
            $code = $this->normalizeCode((string) ($payload['code'] ?? ''));
            if ($code === '') {
                throw new InventoryException('ADMIN_AFFIX_INVALID', 'Affix code is required.', 422);
            }

            $existing = $this->pdo()->prepare('SELECT id FROM item_affix_definitions WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $code]);
            if ($existing->fetchColumn() !== false) {
                throw new InventoryException('ADMIN_AFFIX_CODE_EXISTS', 'Affix definition code already exists.', 409);
            }

            $fields = $this->normalizeWritableFields($payload, true);
            $stmt = $this->pdo()->prepare(
                'INSERT INTO item_affix_definitions (
                    code, name, affix_type, property_definition_id,
                    min_value, max_value, rarity_weight, min_item_level, status
                ) VALUES (
                    :code, :name, :affix_type, :property_definition_id,
                    :min_value, :max_value, :rarity_weight, :min_item_level, :status
                )'
            );
            $stmt->execute([
                'code' => $code,
                ...$fields,
            ]);

            return $this->getByCode($code);
        });
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(string $code, array $payload): array
    {
        return $this->transaction(function () use ($code, $payload): array {
            $normalizedCode = $this->normalizeCode($code);
            $current = $this->getByCode($normalizedCode);
            $fields = $this->normalizeWritableFields($payload, false, $current);

            $stmt = $this->pdo()->prepare(
                'UPDATE item_affix_definitions SET
                    name = :name,
                    affix_type = :affix_type,
                    property_definition_id = :property_definition_id,
                    min_value = :min_value,
                    max_value = :max_value,
                    rarity_weight = :rarity_weight,
                    min_item_level = :min_item_level,
                    status = :status,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE code = :code'
            );
            $stmt->execute([
                'code' => $normalizedCode,
                ...$fields,
            ]);

            return $this->getByCode($normalizedCode);
        });
    }

    /** @return array{affix_types: list<string>, statuses: list<string>, properties: list<array<string, mixed>>} */
    public function meta(): array
    {
        $properties = $this->pdo()->query(
            'SELECT code, name, COALESCE(equipment_scope, \'shared\') AS equipment_scope, status
             FROM item_property_definitions
             ORDER BY code ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'affix_types' => self::AFFIX_TYPES,
            'statuses' => self::STATUSES,
            'properties' => array_map(static fn (array $row): array => [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'equipment_scope' => (string) ($row['equipment_scope'] ?? 'shared'),
                'status' => (string) ($row['status'] ?? 'active'),
            ], $properties),
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'affix_type' => (string) ($row['affix_type'] ?? 'prefix'),
            'property_code' => (string) ($row['property_code'] ?? ''),
            'property_name' => (string) ($row['property_name'] ?? ''),
            'property_equipment_scope' => (string) ($row['property_equipment_scope'] ?? 'shared'),
            'min_value' => (float) ($row['min_value'] ?? 0),
            'max_value' => (float) ($row['max_value'] ?? 0),
            'rarity_weight' => (int) ($row['rarity_weight'] ?? 1),
            'min_item_level' => (int) ($row['min_item_level'] ?? 1),
            'status' => (string) ($row['status'] ?? 'active'),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function normalizeWritableFields(array $payload, bool $creating, ?array $current = null): array
    {
        $name = trim((string) ($payload['name'] ?? ($current['name'] ?? '')));
        if ($name === '') {
            throw new InventoryException('ADMIN_AFFIX_INVALID', 'Affix name is required.', 422);
        }

        $affixType = trim((string) ($payload['affix_type'] ?? ($current['affix_type'] ?? 'prefix')));
        if (!in_array($affixType, self::AFFIX_TYPES, true)) {
            throw new InventoryException('ADMIN_AFFIX_INVALID', 'Invalid affix_type.', 422);
        }

        $status = trim((string) ($payload['status'] ?? ($current['status'] ?? 'active')));
        if (!in_array($status, self::STATUSES, true)) {
            throw new InventoryException('ADMIN_AFFIX_INVALID', 'Invalid status.', 422);
        }

        $propertyCode = trim((string) ($payload['property_code'] ?? ($current['property_code'] ?? '')));
        $propertyId = $this->propertyIdByCode($propertyCode);
        if ($propertyId === null) {
            throw new InventoryException('ADMIN_AFFIX_INVALID', 'Invalid property_code.', 422);
        }

        $minValue = (float) ($payload['min_value'] ?? ($current['min_value'] ?? 0));
        $maxValue = (float) ($payload['max_value'] ?? ($current['max_value'] ?? 0));
        if ($minValue > $maxValue) {
            throw new InventoryException('ADMIN_AFFIX_INVALID', 'min_value cannot be greater than max_value.', 422);
        }

        $rarityWeight = max(1, (int) ($payload['rarity_weight'] ?? ($current['rarity_weight'] ?? 1)));
        $minItemLevel = max(1, (int) ($payload['min_item_level'] ?? ($current['min_item_level'] ?? 1)));

        return [
            'name' => $name,
            'affix_type' => $affixType,
            'property_definition_id' => $propertyId,
            'min_value' => $minValue,
            'max_value' => $maxValue,
            'rarity_weight' => $rarityWeight,
            'min_item_level' => $minItemLevel,
            'status' => $status,
        ];
    }

    private function propertyIdByCode(string $code): ?int
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT id FROM item_property_definitions WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]/', '', $code) ?: '';

        return $code;
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
