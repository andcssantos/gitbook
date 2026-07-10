<?php

namespace App\Game\Items\Repositories;

use App\Support\DB;
use PDO;

class ItemInstancePropertyRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function listForItem(int $itemInstanceId): array
    {
        $stmt = $this->pdo()->prepare('SELECT
                iip.*,
                ipd.code,
                ipd.name,
                ipd.value_type,
                ipd.unit,
                ipd.equipment_scope,
                ipd.min_value,
                ipd.max_value
            FROM item_instance_properties iip
            INNER JOIN item_property_definitions ipd ON ipd.id = iip.property_definition_id
            WHERE iip.item_instance_id = :item_instance_id
            ORDER BY ipd.name ASC, iip.source ASC');
        $stmt->execute(['item_instance_id' => $itemInstanceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByItemAndCode(int $itemInstanceId, string $propertyCode): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT
                iip.*,
                ipd.code,
                ipd.name,
                ipd.value_type,
                ipd.unit,
                ipd.equipment_scope,
                ipd.min_value,
                ipd.max_value
            FROM item_instance_properties iip
            INNER JOIN item_property_definitions ipd ON ipd.id = iip.property_definition_id
            WHERE iip.item_instance_id = :item_instance_id
                AND ipd.code = :code
            ORDER BY iip.id ASC
            LIMIT 1');
        $stmt->execute([
            'item_instance_id' => $itemInstanceId,
            'code' => $propertyCode,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function upsertNumeric(int $itemInstanceId, int $propertyDefinitionId, float $value, string $source = 'upgrade'): void
    {
        $existing = $this->pdo()->prepare('SELECT iip.id, ipd.value_type
            FROM item_instance_properties iip
            INNER JOIN item_property_definitions ipd ON ipd.id = iip.property_definition_id
            WHERE iip.item_instance_id = :item_instance_id
                AND iip.property_definition_id = :property_definition_id
                AND iip.source = :source
            LIMIT 1');
        $existing->execute([
            'item_instance_id' => $itemInstanceId,
            'property_definition_id' => $propertyDefinitionId,
            'source' => $source,
        ]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if (is_array($row)) {
            $column = ($row['value_type'] ?? 'numeric') === 'integer' ? 'integer_value' : 'numeric_value';
            $stmt = $this->pdo()->prepare("UPDATE item_instance_properties SET {$column} = :value WHERE id = :id");
            $stmt->execute([
                'id' => (int) $row['id'],
                'value' => $value,
            ]);
            return;
        }

        $definition = $this->pdo()->prepare('SELECT value_type FROM item_property_definitions WHERE id = :id LIMIT 1');
        $definition->execute(['id' => $propertyDefinitionId]);
        $valueType = (string) ($definition->fetchColumn() ?: 'numeric');

        $stmt = $this->pdo()->prepare('INSERT INTO item_instance_properties (
            item_instance_id,
            property_definition_id,
            numeric_value,
            integer_value,
            source
        ) VALUES (
            :item_instance_id,
            :property_definition_id,
            :numeric_value,
            :integer_value,
            :source
        )');
        $stmt->execute([
            'item_instance_id' => $itemInstanceId,
            'property_definition_id' => $propertyDefinitionId,
            'numeric_value' => $valueType === 'numeric' ? $value : null,
            'integer_value' => $valueType === 'integer' ? (int) round($value) : null,
            'source' => $source,
        ]);
    }

    public function propertyDefinitionId(string $code): int
    {
        $row = $this->findDefinitionByCode($code);
        if ($row === null) {
            throw new \RuntimeException('Property definition not found: ' . $code);
        }

        return (int) $row['id'];
    }

    public function findDefinitionByCode(string $code): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM item_property_definitions WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? DB::pdo();
    }
}
