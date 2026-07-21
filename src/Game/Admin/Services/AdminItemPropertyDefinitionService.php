<?php

namespace App\Game\Admin\Services;

use App\Game\Enhancement\Services\PropertyScopeService;
use App\Game\Inventory\InventoryException;
use App\Support\DB;
use PDO;
use Throwable;

class AdminItemPropertyDefinitionService
{
    private const VALUE_TYPES = ['numeric', 'integer', 'text', 'boolean'];
    private const STATUSES = ['active', 'inactive', 'draft'];
    private const SCOPES = ['shared', 'offense', 'defense', 'exclusive_offense', 'exclusive_defense'];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return array{definitions: list<array<string, mixed>>, total: int, limit: int, offset: int} */
    public function list(array $filters = []): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $valueType = trim((string) ($filters['value_type'] ?? ''));
        $scope = trim((string) ($filters['equipment_scope'] ?? ''));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $where = ['1=1'];
        $params = [];

        if ($q !== '') {
            $where[] = '(code LIKE :q OR name LIKE :q OR COALESCE(unit, \'\') LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($valueType !== '') {
            $where[] = 'value_type = :value_type';
            $params['value_type'] = $valueType;
        }
        if ($scope !== '') {
            $where[] = 'COALESCE(equipment_scope, \'shared\') = :equipment_scope';
            $params['equipment_scope'] = $scope;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo()->prepare("SELECT COUNT(*) FROM item_property_definitions WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT *
            FROM item_property_definitions
            WHERE {$whereSql}
            ORDER BY code ASC
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
            'SELECT * FROM item_property_definitions WHERE code = :code LIMIT 1'
        );
        $stmt->execute(['code' => $this->normalizeCode($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InventoryException('ADMIN_PROPERTY_NOT_FOUND', 'Property definition was not found.', 404);
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
                throw new InventoryException('ADMIN_PROPERTY_INVALID', 'Property code is required.', 422);
            }

            $existing = $this->pdo()->prepare('SELECT id FROM item_property_definitions WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $code]);
            if ($existing->fetchColumn() !== false) {
                throw new InventoryException('ADMIN_PROPERTY_CODE_EXISTS', 'Property definition code already exists.', 409);
            }

            $fields = $this->normalizeWritableFields($payload, true);
            $stmt = $this->pdo()->prepare(
                'INSERT INTO item_property_definitions (
                    code, name, value_type, unit, min_value, max_value,
                    market_filterable, equipment_scope, status
                ) VALUES (
                    :code, :name, :value_type, :unit, :min_value, :max_value,
                    :market_filterable, :equipment_scope, :status
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
                'UPDATE item_property_definitions SET
                    name = :name,
                    value_type = :value_type,
                    unit = :unit,
                    min_value = :min_value,
                    max_value = :max_value,
                    market_filterable = :market_filterable,
                    equipment_scope = :equipment_scope,
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

    /** @return array{value_types: list<string>, statuses: list<string>, equipment_scopes: list<string>} */
    public function meta(): array
    {
        return [
            'value_types' => self::VALUE_TYPES,
            'statuses' => self::STATUSES,
            'equipment_scopes' => self::SCOPES,
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $scope = $row['equipment_scope'] ?? null;
        $scope = $scope !== null && $scope !== ''
            ? (string) $scope
            : (new PropertyScopeService())->defaultScope();

        return [
            'id' => (int) ($row['id'] ?? 0),
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'value_type' => (string) ($row['value_type'] ?? 'numeric'),
            'unit' => $row['unit'] !== null ? (string) $row['unit'] : null,
            'min_value' => $row['min_value'] !== null ? (float) $row['min_value'] : null,
            'max_value' => $row['max_value'] !== null ? (float) $row['max_value'] : null,
            'market_filterable' => (bool) ($row['market_filterable'] ?? false),
            'equipment_scope' => $scope,
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
            throw new InventoryException('ADMIN_PROPERTY_INVALID', 'Property name is required.', 422);
        }

        $valueType = trim((string) ($payload['value_type'] ?? ($current['value_type'] ?? 'numeric')));
        if (!in_array($valueType, self::VALUE_TYPES, true)) {
            throw new InventoryException('ADMIN_PROPERTY_INVALID', 'Invalid value_type.', 422);
        }

        $status = trim((string) ($payload['status'] ?? ($current['status'] ?? 'active')));
        if (!in_array($status, self::STATUSES, true)) {
            throw new InventoryException('ADMIN_PROPERTY_INVALID', 'Invalid status.', 422);
        }

        $scope = trim((string) ($payload['equipment_scope'] ?? ($current['equipment_scope'] ?? 'shared')));
        if ($scope === '') {
            $scope = 'shared';
        }
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InventoryException('ADMIN_PROPERTY_INVALID', 'Invalid equipment_scope.', 422);
        }

        $unit = array_key_exists('unit', $payload)
            ? trim((string) ($payload['unit'] ?? ''))
            : (string) ($current['unit'] ?? '');
        $unit = $unit !== '' ? $unit : null;

        $minValue = array_key_exists('min_value', $payload)
            ? $payload['min_value']
            : ($current['min_value'] ?? null);
        $maxValue = array_key_exists('max_value', $payload)
            ? $payload['max_value']
            : ($current['max_value'] ?? null);

        $minValue = $minValue === null || $minValue === '' ? null : (float) $minValue;
        $maxValue = $maxValue === null || $maxValue === '' ? null : (float) $maxValue;
        if ($minValue !== null && $maxValue !== null && $minValue > $maxValue) {
            throw new InventoryException('ADMIN_PROPERTY_INVALID', 'min_value cannot be greater than max_value.', 422);
        }

        return [
            'name' => $name,
            'value_type' => $valueType,
            'unit' => $unit,
            'min_value' => $minValue,
            'max_value' => $maxValue,
            'market_filterable' => (int) (bool) ($payload['market_filterable'] ?? ($current['market_filterable'] ?? false)),
            'equipment_scope' => $scope,
            'status' => $status,
        ];
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
