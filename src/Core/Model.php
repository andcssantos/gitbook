<?php

namespace App\Core;

use App\Utils\DB\Connection;
use App\Database\QueryBuilder;
use PDO;
use PDOException;
use RuntimeException;

abstract class Model
{
    protected static ?PDO $pdo = null;
    protected string $table;

    public function __construct()
    {
        if (self::$pdo === null) {
            self::$pdo = Connection::Conn($_ENV['APP_ENV'] ?? 'dev');
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }

        if (!isset($this->table)) {
            throw new RuntimeException('A propriedade $table deve ser definida na classe filha.');
        }

        $this->assertIdentifier($this->table);
    }

    public function setTable(string $table): self
    {
        $this->assertIdentifier($table);
        $this->table = $table;

        return $this;
    }

    protected function query(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        } catch (PDOException $e) {
            error_log("Erro na query: {$e->getMessage()} | SQL: {$sql}");
            throw new RuntimeException('Erro ao executar operacao no banco de dados.', 0, $e);
        }
    }

    public function fetch(string $sql, array $params = []): array|false
    {
        return $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);
    }

    public function listAll(string $sql, array $params = []): array|false
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function all(array|string $columns = ['*'], array $params = []): array|false
    {
        try {
            $sql = 'SELECT ' . $this->columnList($columns) . " FROM {$this->table}";

            if (!empty($params)) {
                $this->assertIdentifiers(array_keys($params));
                $conditions = [];
                foreach ($params as $key => $value) {
                    $conditions[] = "$key = :$key";
                }
                $sql .= ' WHERE ' . implode(' AND ', $conditions);
            }

            return $this->listAll($sql, $params);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function findById(int $id, array|string $columns = ['*']): array|false
    {
        try {
            $sql = 'SELECT ' . $this->columnList($columns) . " FROM {$this->table} WHERE id = :id";

            return $this->fetch($sql, ['id' => $id]);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function select(string $where = '', array $params = [], array|string $columns = ['*']): array|false
    {
        try {
            $sql = 'SELECT ' . $this->columnList($columns) . " FROM {$this->table}";
            if ($where) {
                $sql .= " WHERE $where";
            }

            return $this->listAll($sql, $params);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function create(array $data): bool|array
    {
        try {
            $keys = array_keys($data);
            $this->assertIdentifiers($keys);

            $columns = implode(', ', $keys);
            $placeholders = ':' . implode(', :', $keys);
            $sql = "INSERT INTO {$this->table} ($columns) VALUES ($placeholders)";

            return $this->query($sql, $data)->rowCount() > 0;
        } catch (RuntimeException) {
            return ['error' => 'Erro ao criar registro.'];
        }
    }

    public function update(int $id, array $data): bool|array
    {
        try {
            $keys = array_keys($data);
            $this->assertIdentifiers($keys);

            $set = implode(', ', array_map(fn ($key) => "$key = :$key", $keys));
            $data['id'] = $id;
            $sql = "UPDATE {$this->table} SET $set WHERE id = :id";

            return $this->query($sql, $data)->rowCount() > 0;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            $sql = "DELETE FROM {$this->table} WHERE id = :id";

            return $this->query($sql, ['id' => $id])->rowCount() > 0;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function beginTransaction(): void
    {
        self::$pdo->beginTransaction();
    }

    public function commit(): void
    {
        self::$pdo->commit();
    }

    public function rollback(): void
    {
        self::$pdo->rollBack();
    }

    protected function pdo(): PDO
    {
        return self::$pdo;
    }

    protected function table(?string $table = null): QueryBuilder
    {
        return new QueryBuilder(self::$pdo, $table ?? $this->table);
    }

    private function columnList(array|string $columns): string
    {
        if ($columns === '*') {
            return '*';
        }

        $columns = is_array($columns) ? $columns : array_map('trim', explode(',', $columns));
        $this->assertIdentifiers($columns);

        return implode(', ', $columns);
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
