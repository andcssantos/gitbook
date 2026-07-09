<?php

namespace App\Utils\DB;

use PDO;
use PDOException;
use RuntimeException;

class Connection
{
    private static array $dbConfigs;

    public static function init(): void
    {
        self::$dbConfigs = [
            'dev' => [
                'host' => $_ENV['DB_DEV_HOST'] ?? '127.0.0.1',
                'port' => $_ENV['DB_DEV_PORT'] ?? '3306',
                'username' => $_ENV['DB_DEV_USERNAME'] ?? 'root',
                'password' => $_ENV['DB_DEV_PASSWORD'] ?? '',
                'dbname' => $_ENV['DB_DEV_DBNAME'] ?? '',
            ],
            'prod' => [
                'host' => $_ENV['DB_PROD_HOST'] ?? '127.0.0.1',
                'port' => $_ENV['DB_PROD_PORT'] ?? '3306',
                'username' => $_ENV['DB_PROD_USERNAME'] ?? 'root',
                'password' => $_ENV['DB_PROD_PASSWORD'] ?? '',
                'dbname' => $_ENV['DB_PROD_DBNAME'] ?? '',
            ],
        ];
    }

    public static function Conn(string $database = 'dev', string $charset = 'utf8mb4'): PDO
    {
        try {
            if (!isset(self::$dbConfigs)) {
                self::init();
            }

            $dbConfig = self::$dbConfigs[$database] ?? throw new PDOException("Database nao encontrada: {$database}.");

            $conn = new PDO(self::getDSN($dbConfig, 'mysql', $charset), $dbConfig['username'], $dbConfig['password']);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            return $conn;
        } catch (PDOException $e) {
            throw new RuntimeException('Erro na conexao com o banco de dados.', (int) $e->getCode(), $e);
        }
    }

    private static function getDSN(array $dbConfig, string $banco, string $charset): string
    {
        return match ($banco) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['dbname'],
                $charset
            ),
            default => throw new PDOException("Driver de conexao nao encontrado para: {$banco}"),
        };
    }
}
