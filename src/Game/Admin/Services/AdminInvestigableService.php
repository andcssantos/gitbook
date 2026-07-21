<?php

namespace App\Game\Admin\Services;

use App\Game\Exploration\Services\ExplorationBiomeCatalogService;
use App\Game\Inventory\InventoryException;
use App\Support\DB;
use PDO;
use Throwable;

class AdminInvestigableService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    /** @return array{definitions: list<array<string, mixed>>, total: int, limit: int, offset: int} */
    public function list(array $filters = []): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $biome = trim((string) ($filters['biome_code'] ?? ''));
        $active = $filters['is_active'] ?? null;
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 80)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(code LIKE :q OR name LIKE :q OR COALESCE(summary, \'\') LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($biome !== '') {
            $where[] = 'biome_code = :biome_code';
            $params['biome_code'] = $biome;
        }
        if ($active !== null && $active !== '') {
            $where[] = 'is_active = :is_active';
            $params['is_active'] = (int) (bool) $active;
        }

        $whereSql = implode(' AND ', $where);
        $count = $this->pdo()->prepare("SELECT COUNT(*) FROM investigable_definitions WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo()->prepare(
            "SELECT * FROM investigable_definitions
             WHERE {$whereSql}
             ORDER BY biome_code ASC, sort_order ASC, code ASC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'definitions' => array_map(fn (array $row): array => $this->mapDefinition($row, false), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** @return array<string, mixed> */
    public function getByCode(string $code): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM investigable_definitions WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $this->normalizeCode($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InventoryException('ADMIN_INVESTIGABLE_NOT_FOUND', 'Investigable definition was not found.', 404);
        }

        return $this->mapDefinition($row, true);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        return $this->transaction(function () use ($payload): array {
            $code = $this->normalizeCode((string) ($payload['code'] ?? ''));
            if ($code === '') {
                throw new InventoryException('ADMIN_INVESTIGABLE_INVALID', 'Definition code is required.', 422);
            }

            $exists = $this->pdo()->prepare('SELECT id FROM investigable_definitions WHERE code = :code LIMIT 1');
            $exists->execute(['code' => $code]);
            if ($exists->fetchColumn() !== false) {
                throw new InventoryException('ADMIN_INVESTIGABLE_CODE_EXISTS', 'Definition code already exists.', 409);
            }

            $fields = $this->normalizeDefinitionFields($payload);
            $this->pdo()->prepare(
                'INSERT INTO investigable_definitions (
                    code, name, biome_code, kind, summary, icon_key, config_json, sort_order, is_active
                ) VALUES (
                    :code, :name, :biome_code, :kind, :summary, :icon_key, :config_json, :sort_order, :is_active
                )'
            )->execute([
                'code' => $code,
                ...$fields,
            ]);

            if (!empty($payload['actions']) && is_array($payload['actions'])) {
                foreach ($payload['actions'] as $action) {
                    if (!is_array($action)) {
                        continue;
                    }
                    $this->upsertAction($code, $action);
                }
            }

            return $this->getByCode($code);
        });
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(string $code, array $payload): array
    {
        return $this->transaction(function () use ($code, $payload): array {
            $normalized = $this->normalizeCode($code);
            $current = $this->getByCode($normalized);
            $fields = $this->normalizeDefinitionFields($payload, $current);

            $this->pdo()->prepare(
                'UPDATE investigable_definitions SET
                    name = :name,
                    biome_code = :biome_code,
                    kind = :kind,
                    summary = :summary,
                    icon_key = :icon_key,
                    config_json = :config_json,
                    sort_order = :sort_order,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE code = :code'
            )->execute([
                'code' => $normalized,
                ...$fields,
            ]);

            if (array_key_exists('actions', $payload) && is_array($payload['actions'])) {
                $this->replaceActions($normalized, $payload['actions']);
            }

            return $this->getByCode($normalized);
        });
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upsertAction(string $definitionCode, array $payload): array
    {
        return $this->transaction(function () use ($definitionCode, $payload): array {
            $definition = $this->getByCode($definitionCode);
            $actionCode = $this->normalizeCode((string) ($payload['action_code'] ?? ''));
            if ($actionCode === '') {
                throw new InventoryException('ADMIN_INVESTIGABLE_INVALID', 'action_code is required.', 422);
            }

            $config = $payload['config'] ?? ($payload['config_json'] ?? []);
            if (is_string($config)) {
                try {
                    $config = json_decode($config, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    throw new InventoryException('ADMIN_INVESTIGABLE_INVALID', 'Action config must be valid JSON.', 422);
                }
            }
            if (!is_array($config)) {
                $config = [];
            }

            $tool = trim((string) ($payload['required_tool_type'] ?? ''));
            $tool = $tool !== '' ? $tool : null;
            $attribute = trim((string) ($payload['attribute_code'] ?? ''));
            $attribute = $attribute !== '' ? $attribute : null;
            $maxTier = $payload['max_reveal_tier'] ?? null;
            $maxTier = $maxTier === null || $maxTier === '' ? null : (int) $maxTier;

            $stmt = $this->pdo()->prepare(
                'SELECT id FROM investigable_actions WHERE definition_id = :definition_id AND action_code = :action_code LIMIT 1'
            );
            $stmt->execute([
                'definition_id' => (int) $definition['id'],
                'action_code' => $actionCode,
            ]);
            $existingId = $stmt->fetchColumn();

            $fields = [
                'required_tool_type' => $tool,
                'min_reveal_tier' => max(0, (int) ($payload['min_reveal_tier'] ?? 0)),
                'max_reveal_tier' => $maxTier,
                'xp_tool' => max(0, (int) ($payload['xp_tool'] ?? 0)),
                'xp_attribute' => max(0, (int) ($payload['xp_attribute'] ?? 0)),
                'attribute_code' => $attribute,
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'sort_order' => max(0, (int) ($payload['sort_order'] ?? 20)),
                'is_active' => (int) (bool) ($payload['is_active'] ?? true),
            ];

            if ($existingId !== false) {
                $this->pdo()->prepare(
                    'UPDATE investigable_actions SET
                        required_tool_type = :required_tool_type,
                        min_reveal_tier = :min_reveal_tier,
                        max_reveal_tier = :max_reveal_tier,
                        xp_tool = :xp_tool,
                        xp_attribute = :xp_attribute,
                        attribute_code = :attribute_code,
                        config_json = :config_json,
                        sort_order = :sort_order,
                        is_active = :is_active,
                        updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                )->execute([
                    'id' => (int) $existingId,
                    ...$fields,
                ]);
            } else {
                $this->pdo()->prepare(
                    'INSERT INTO investigable_actions (
                        definition_id, action_code, required_tool_type, min_reveal_tier, max_reveal_tier,
                        xp_tool, xp_attribute, attribute_code, config_json, sort_order, is_active
                    ) VALUES (
                        :definition_id, :action_code, :required_tool_type, :min_reveal_tier, :max_reveal_tier,
                        :xp_tool, :xp_attribute, :attribute_code, :config_json, :sort_order, :is_active
                    )'
                )->execute([
                    'definition_id' => (int) $definition['id'],
                    'action_code' => $actionCode,
                    ...$fields,
                ]);
            }

            return $this->getByCode($definitionCode);
        });
    }

    /** @return array<string, mixed> */
    public function deleteAction(string $definitionCode, string $actionCode): array
    {
        return $this->transaction(function () use ($definitionCode, $actionCode): array {
            $definition = $this->getByCode($definitionCode);
            $this->pdo()->prepare(
                'DELETE FROM investigable_actions WHERE definition_id = :definition_id AND action_code = :action_code'
            )->execute([
                'definition_id' => (int) $definition['id'],
                'action_code' => $this->normalizeCode($actionCode),
            ]);

            return $this->getByCode($definitionCode);
        });
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        $biomes = [];
        foreach ((new ExplorationBiomeCatalogService($this->pdo))->listBiomes() as $biome) {
            $biomes[] = [
                'code' => (string) ($biome['code'] ?? ''),
                'name' => (string) ($biome['name'] ?? ''),
            ];
        }

        return [
            'biomes' => $biomes,
            'kinds' => ['flora', 'wood', 'stone', 'container', 'cache', 'water', 'herb', 'other'],
            'common_action_codes' => [
                'analyze_magnifier',
                'harvest_shears',
                'chop_hatchet',
                'mine_pickaxe',
                'dig_shovel',
                'pick_lock',
                'force_open',
                'clear_shears',
                'pluck_tweezers',
            ],
            'common_tool_types' => [
                'magnifier',
                'shears',
                'hatchet',
                'pickaxe',
                'shovel',
                'lockpick_kit',
                'tweezers',
            ],
        ];
    }

    /** @param list<mixed> $actions */
    private function replaceActions(string $definitionCode, array $actions): void
    {
        $definition = $this->getByCode($definitionCode);
        $this->pdo()->prepare('DELETE FROM investigable_actions WHERE definition_id = :definition_id')
            ->execute(['definition_id' => (int) $definition['id']]);

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $this->upsertAction($definitionCode, $action);
        }
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapDefinition(array $row, bool $withActions): array
    {
        $config = $this->decodeJson($row['config_json'] ?? null);
        $mapped = [
            'id' => (int) ($row['id'] ?? 0),
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'biome_code' => (string) ($row['biome_code'] ?? ''),
            'kind' => (string) ($row['kind'] ?? 'other'),
            'summary' => $row['summary'] !== null ? (string) $row['summary'] : null,
            'icon_key' => $row['icon_key'] !== null ? (string) $row['icon_key'] : null,
            'config' => $config,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_active' => (bool) ($row['is_active'] ?? true),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];

        if ($withActions) {
            $mapped['actions'] = $this->actionsForDefinition((int) $mapped['id']);
        }

        return $mapped;
    }

    /** @return list<array<string, mixed>> */
    private function actionsForDefinition(int $definitionId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM investigable_actions WHERE definition_id = :definition_id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['definition_id' => $definitionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'action_code' => (string) ($row['action_code'] ?? ''),
                'required_tool_type' => $row['required_tool_type'] !== null ? (string) $row['required_tool_type'] : null,
                'min_reveal_tier' => (int) ($row['min_reveal_tier'] ?? 0),
                'max_reveal_tier' => $row['max_reveal_tier'] !== null ? (int) $row['max_reveal_tier'] : null,
                'xp_tool' => (int) ($row['xp_tool'] ?? 0),
                'xp_attribute' => (int) ($row['xp_attribute'] ?? 0),
                'attribute_code' => $row['attribute_code'] !== null ? (string) $row['attribute_code'] : null,
                'config' => $this->decodeJson($row['config_json'] ?? null),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'is_active' => (bool) ($row['is_active'] ?? true),
            ];
        }, $rows);
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function normalizeDefinitionFields(array $payload, ?array $current = null): array
    {
        $name = trim((string) ($payload['name'] ?? ($current['name'] ?? '')));
        if ($name === '') {
            throw new InventoryException('ADMIN_INVESTIGABLE_INVALID', 'Name is required.', 422);
        }

        $biome = trim((string) ($payload['biome_code'] ?? ($current['biome_code'] ?? '')));
        if ($biome === '') {
            throw new InventoryException('ADMIN_INVESTIGABLE_INVALID', 'biome_code is required.', 422);
        }

        $kind = trim((string) ($payload['kind'] ?? ($current['kind'] ?? 'other')));
        if ($kind === '') {
            $kind = 'other';
        }

        $config = $payload['config'] ?? ($payload['config_json'] ?? ($current['config'] ?? []));
        if (is_string($config)) {
            try {
                $config = json_decode($config, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new InventoryException('ADMIN_INVESTIGABLE_INVALID', 'config must be valid JSON.', 422);
            }
        }
        if (!is_array($config)) {
            $config = [];
        }

        $summary = array_key_exists('summary', $payload)
            ? trim((string) ($payload['summary'] ?? ''))
            : (string) ($current['summary'] ?? '');
        $icon = array_key_exists('icon_key', $payload)
            ? trim((string) ($payload['icon_key'] ?? ''))
            : (string) ($current['icon_key'] ?? '');

        return [
            'name' => $name,
            'biome_code' => $biome,
            'kind' => $kind,
            'summary' => $summary !== '' ? $summary : null,
            'icon_key' => $icon !== '' ? $icon : null,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'sort_order' => max(0, (int) ($payload['sort_order'] ?? ($current['sort_order'] ?? 0))),
            'is_active' => (int) (bool) ($payload['is_active'] ?? ($current['is_active'] ?? true)),
        ];
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));

        return preg_replace('/[^a-z0-9_]/', '', $code) ?: '';
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
