<?php

namespace App\Core;

use App\Utils\DB\Connection;
use PDO;
use PDOException;
use Exception;

abstract class Model
{
    protected static ?PDO $pdo = null;
    protected string $table;

    public function __construct()
    {
        
        if (self::$pdo === null) {
            self::$pdo = Connection::Conn($_ENV['APP_ENV']);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }

        if (!isset($this->table)) {
            throw new Exception("A propriedade \$table deve ser definida na classe filha.");
        }
    }

    public function setTable(string $table): self
    {
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
            throw new Exception("Erro na query: {$e->getMessage()} | SQL: $sql | Params: " . json_encode($params));

        }
    }

    public function fetch(string $sql, array $params = []): array|false
    {
        return $this->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);
    }

    public function listAll(string $sql, array $params = []): array|false
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function all(array|string $columns = ['*'], array $params = []): array|false
    {
        try {
            $columnString = is_array($columns) ? implode(', ', $columns) : $columns;
            $sql = "SELECT $columnString FROM {$this->table}";
    
            if (!empty($params)) {
                $conditions = [];
                foreach ($params as $key => $value) {
                    $conditions[] = "$key = :$key";
                }
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }
    
            return $this->listAll($sql, $params);
        } catch (Exception $e) {
            return false;
        }
    }
    

    public function findById(int $id, array|string $columns = ['*']): array|false
    {
        try {
            $columnString = is_array($columns) ? implode(', ', $columns) : $columns;
            $sql = "SELECT $columnString FROM {$this->table} WHERE id = :id";
            return $this->fetch($sql, ['id' => $id]);
        } catch (Exception $e) {
            return false;
        }
    }

    public function select(string $where = '', array $params = [], array|string $columns = ['*']): array|false
    {
        try {
            $columnString = is_array($columns) ? implode(', ', $columns) : $columns;
            $sql = "SELECT $columnString FROM {$this->table}";
            if ($where) {
                $sql .= " WHERE $where";
            }
            return $this->listAll($sql, $params);
        } catch (Exception $e) {
            return false;
        }
    }

    public function create(array $data): bool | array
    {
        try {
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $sql = "INSERT INTO {$this->table} ($columns) VALUES ($placeholders)";
            return $this->query($sql, $data)->rowCount() > 0;
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function update(int $id, array $data): bool | array
    {
        try {
            $set = implode(', ', array_map(fn($key) => "$key = :$key", array_keys($data)));
            $data['id'] = $id;
            $sql = "UPDATE {$this->table} SET $set WHERE id = :id";
            return $this->query($sql, $data)->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            $sql = "DELETE FROM {$this->table} WHERE id = :id";
            return $this->query($sql, ['id' => $id])->rowCount() > 0;
        } catch (Exception $e) {
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
}