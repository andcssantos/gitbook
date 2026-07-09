<?php

namespace App\Database;

use PDO;
use RuntimeException;

class QueryBuilder
{
    private array $columns = ['*'];
    private array $wheres = [];
    private array $bindings = [];
    private array $orders = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private ?string $lock = null;

    public function __construct(private PDO $pdo, private string $table)
    {
        $this->assertIdentifier($table);
    }

    public function select(array|string $columns = ['*']): self
    {
        $columns = is_array($columns) ? $columns : array_map('trim', explode(',', $columns));
        if ($columns !== ['*']) {
            $this->assertIdentifiers($columns);
        }
        $this->columns = $columns;

        return $this;
    }

    public function where(string $column, string $operator, mixed $value = null): self
    {
        $this->assertIdentifier($column);
        $allowed = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'IN'];
        $operator = strtoupper($operator);
        if (!in_array($operator, $allowed, true)) {
            throw new RuntimeException("Operador invalido: {$operator}");
        }

        if ($operator === 'IN') {
            $values = is_array($value) ? $value : [$value];
            $placeholders = [];
            foreach ($values as $item) {
                $key = $this->bind($item);
                $placeholders[] = ":{$key}";
            }
            $this->wheres[] = "{$column} IN (" . implode(', ', $placeholders) . ')';
            return $this;
        }

        $key = $this->bind($value);
        $this->wheres[] = "{$column} {$operator} :{$key}";

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->assertIdentifier($column);
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new RuntimeException('Direcao invalida.');
        }

        $this->orders[] = "{$column} {$direction}";
        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit = max(0, $limit);
        $this->offset = max(0, $offset);

        return $this;
    }

    public function forUpdate(): self
    {
        $this->lock = 'FOR UPDATE';

        return $this;
    }

    public function sharedLock(): self
    {
        $this->lock = 'LOCK IN SHARE MODE';

        return $this;
    }

    public function get(): array
    {
        $stmt = $this->pdo->prepare($this->toSql());
        $stmt->execute($this->bindings);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function first(): array|false
    {
        $this->limit(1);
        $rows = $this->get();

        return $rows[0] ?? false;
    }

    public function insert(array $data): bool
    {
        $this->assertIdentifiers(array_keys($data));
        $columns = array_keys($data);
        $placeholders = array_map(fn (string $column): string => ':' . $column, $columns);
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');

        return $stmt->execute($data);
    }

    public function update(array $data): int
    {
        $this->assertIdentifiers(array_keys($data));
        $sets = [];
        foreach ($data as $column => $value) {
            $key = $this->bind($value);
            $sets[] = "{$column} = :{$key}";
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . $this->whereSql();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);

        return $stmt->rowCount();
    }

    public function delete(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table}" . $this->whereSql());
        $stmt->execute($this->bindings);

        return $stmt->rowCount();
    }

    public function toSql(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->columns) . " FROM {$this->table}" . $this->whereSql();

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
            if ($this->offset !== null && $this->offset > 0) {
                $sql .= ' OFFSET ' . $this->offset;
            }
        }

        if ($this->lock !== null) {
            $sql .= ' ' . $this->lock;
        }

        return $sql;
    }

    private function whereSql(): string
    {
        return $this->wheres === [] ? '' : ' WHERE ' . implode(' AND ', $this->wheres);
    }

    private function bind(mixed $value): string
    {
        $key = 'p' . count($this->bindings);
        $this->bindings[$key] = $value;

        return $key;
    }

    private function assertIdentifiers(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            $this->assertIdentifier((string) $identifier);
        }
    }

    private function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Identificador SQL invalido: {$identifier}");
        }
    }
}
