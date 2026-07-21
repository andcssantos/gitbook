<?php

namespace App\Game\Admin\Services;

use App\Game\Inventory\InventoryException;
use App\Support\DB;
use PDO;
use Throwable;

class AdminItemDefinitionService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int} */
    public function list(array $filters = []): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $category = trim((string) ($filters['category_code'] ?? ''));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $where = ['1=1'];
        $params = [];

        if ($q !== '') {
            $where[] = '(d.code LIKE :q OR d.name LIKE :q OR COALESCE(d.description, \'\') LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($status !== '') {
            $where[] = 'd.status = :status';
            $params['status'] = $status;
        }
        if ($category !== '') {
            $where[] = 'c.code = :category_code';
            $params['category_code'] = $category;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM item_definitions d
             INNER JOIN item_categories c ON c.id = d.category_id
             WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT
                d.id,
                d.code,
                d.name,
                d.description,
                d.stackable,
                d.max_stack,
                d.grid_w,
                d.grid_h,
                d.equip_slot_code,
                d.is_container,
                d.tradeable,
                d.base_config,
                d.status,
                d.created_at,
                d.updated_at,
                c.code AS category_code,
                c.name AS category_name,
                mf.code AS material_family_code,
                mf.name AS material_family_name
            FROM item_definitions d
            INNER JOIN item_categories c ON c.id = d.category_id
            LEFT JOIN material_families mf ON mf.id = d.material_family_id
            WHERE {$whereSql}
            ORDER BY d.code ASC
            LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'items' => array_map(fn (array $row): array => $this->mapRow($row), $rows),
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
                d.*,
                c.code AS category_code,
                c.name AS category_name,
                mf.code AS material_family_code,
                mf.name AS material_family_name
             FROM item_definitions d
             INNER JOIN item_categories c ON c.id = d.category_id
             LEFT JOIN material_families mf ON mf.id = d.material_family_id
             WHERE d.code = :code
             LIMIT 1'
        );
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InventoryException('ADMIN_ITEM_NOT_FOUND', 'Item definition was not found.', 404);
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
                throw new InventoryException('ADMIN_ITEM_INVALID', 'Item code is required.', 422);
            }

            $existing = $this->pdo()->prepare('SELECT id FROM item_definitions WHERE code = :code LIMIT 1');
            $existing->execute(['code' => $code]);
            if ($existing->fetchColumn() !== false) {
                throw new InventoryException('ADMIN_ITEM_CODE_EXISTS', 'Item definition code already exists.', 409);
            }

            $fields = $this->normalizeWritableFields($payload, true);
            $stmt = $this->pdo()->prepare(
                'INSERT INTO item_definitions (
                    code, name, description, category_id, material_family_id,
                    stackable, max_stack, grid_w, grid_h, equip_slot_code,
                    is_container, tradeable, base_config, status
                ) VALUES (
                    :code, :name, :description, :category_id, :material_family_id,
                    :stackable, :max_stack, :grid_w, :grid_h, :equip_slot_code,
                    :is_container, :tradeable, :base_config, :status
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
                'UPDATE item_definitions SET
                    name = :name,
                    description = :description,
                    category_id = :category_id,
                    material_family_id = :material_family_id,
                    stackable = :stackable,
                    max_stack = :max_stack,
                    grid_w = :grid_w,
                    grid_h = :grid_h,
                    equip_slot_code = :equip_slot_code,
                    is_container = :is_container,
                    tradeable = :tradeable,
                    base_config = :base_config,
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

    /** @return array{categories: list<array<string, mixed>>, material_families: list<array<string, mixed>>} */
    public function meta(): array
    {
        $categories = $this->pdo()->query(
            'SELECT code, name FROM item_categories ORDER BY code ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $families = $this->pdo()->query(
            'SELECT code, name FROM material_families ORDER BY code ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'categories' => array_values($categories),
            'material_families' => array_values($families),
            'statuses' => ['active', 'inactive', 'draft'],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $baseConfig = $row['base_config'] ?? null;
        if (is_string($baseConfig) && $baseConfig !== '') {
            try {
                $decoded = json_decode($baseConfig, true, 512, JSON_THROW_ON_ERROR);
                $baseConfig = is_array($decoded) ? $decoded : null;
            } catch (Throwable) {
                $baseConfig = null;
            }
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'category_code' => (string) ($row['category_code'] ?? ''),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'material_family_code' => $row['material_family_code'] !== null ? (string) $row['material_family_code'] : null,
            'material_family_name' => $row['material_family_name'] !== null ? (string) $row['material_family_name'] : null,
            'stackable' => (bool) ($row['stackable'] ?? false),
            'max_stack' => (int) ($row['max_stack'] ?? 1),
            'grid_w' => (int) ($row['grid_w'] ?? 1),
            'grid_h' => (int) ($row['grid_h'] ?? 1),
            'equip_slot_code' => $row['equip_slot_code'] !== null ? (string) $row['equip_slot_code'] : null,
            'is_container' => (bool) ($row['is_container'] ?? false),
            'tradeable' => (bool) ($row['tradeable'] ?? true),
            'base_config' => is_array($baseConfig) ? $baseConfig : new \stdClass(),
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
            throw new InventoryException('ADMIN_ITEM_INVALID', 'Item name is required.', 422);
        }

        $categoryCode = trim((string) ($payload['category_code'] ?? ($current['category_code'] ?? '')));
        $categoryId = $this->categoryIdByCode($categoryCode);
        if ($categoryId === null) {
            throw new InventoryException('ADMIN_ITEM_INVALID', 'Invalid category_code.', 422);
        }

        $familyCode = array_key_exists('material_family_code', $payload)
            ? trim((string) ($payload['material_family_code'] ?? ''))
            : (string) ($current['material_family_code'] ?? '');
        $familyId = null;
        if ($familyCode !== '') {
            $familyId = $this->familyIdByCode($familyCode);
            if ($familyId === null) {
                throw new InventoryException('ADMIN_ITEM_INVALID', 'Invalid material_family_code.', 422);
            }
        }

        $stackable = (int) (bool) ($payload['stackable'] ?? ($current['stackable'] ?? false));
        $maxStack = max(1, (int) ($payload['max_stack'] ?? ($current['max_stack'] ?? 1)));
        $gridW = max(1, (int) ($payload['grid_w'] ?? ($current['grid_w'] ?? 1)));
        $gridH = max(1, (int) ($payload['grid_h'] ?? ($current['grid_h'] ?? 1)));
        $status = trim((string) ($payload['status'] ?? ($current['status'] ?? 'active')));
        if (!in_array($status, ['active', 'inactive', 'draft'], true)) {
            throw new InventoryException('ADMIN_ITEM_INVALID', 'Invalid status.', 422);
        }

        $equipSlot = array_key_exists('equip_slot_code', $payload)
            ? trim((string) ($payload['equip_slot_code'] ?? ''))
            : (string) ($current['equip_slot_code'] ?? '');
        $equipSlot = $equipSlot !== '' ? $equipSlot : null;

        $description = array_key_exists('description', $payload)
            ? trim((string) ($payload['description'] ?? ''))
            : (string) ($current['description'] ?? '');
        $description = $description !== '' ? $description : null;

        $baseConfig = $payload['base_config'] ?? ($current['base_config'] ?? []);
        if (is_string($baseConfig)) {
            try {
                $baseConfig = json_decode($baseConfig, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new InventoryException('ADMIN_ITEM_INVALID', 'base_config must be valid JSON.', 422);
            }
        }
        if (!is_array($baseConfig) && !($baseConfig instanceof \stdClass)) {
            $baseConfig = [];
        }
        if ($baseConfig instanceof \stdClass) {
            $baseConfig = (array) $baseConfig;
        }

        $fields = [
            'name' => $name,
            'description' => $description,
            'category_id' => $categoryId,
            'material_family_id' => $familyId,
            'stackable' => $stackable,
            'max_stack' => $maxStack,
            'grid_w' => $gridW,
            'grid_h' => $gridH,
            'equip_slot_code' => $equipSlot,
            'is_container' => (int) (bool) ($payload['is_container'] ?? ($current['is_container'] ?? false)),
            'tradeable' => (int) (bool) ($payload['tradeable'] ?? ($current['tradeable'] ?? true)),
            'base_config' => json_encode($baseConfig, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => $status,
        ];

        if ($creating) {
            return $fields;
        }

        return $fields;
    }

    private function categoryIdByCode(string $code): ?int
    {
        if ($code === '') {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT id FROM item_categories WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function familyIdByCode(string $code): ?int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM material_families WHERE code = :code LIMIT 1');
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
