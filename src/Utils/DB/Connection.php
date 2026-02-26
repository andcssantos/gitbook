<?php

namespace App\Utils\DB;

use PDO;
use PDOException;

class Connection
{
    private static array $dbConfigs;

    public static function init(): void
    {
        self::$dbConfigs = [
            'dev' => [
                'host'     => $_ENV['DB_DEV_HOST']      ?: '127.0.0.1',
                'port'     => $_ENV['DB_DEV_PORT']      ?: '3306',
                'username' => $_ENV['DB_DEV_USERNAME']  ?: 'root',
                'password' => $_ENV['DB_DEV_PASSWORD']  ?: '',
                'dbname'   => $_ENV['DB_DEV_DBNAME']    ?: '',
            ],
            'prod' => [
                'host'     => $_ENV['DB_PROD_HOST']     ?: '127.0.0.1',
                'port'     => $_ENV['DB_PROD_PORT']     ?: '3306',
                'username' => $_ENV['DB_PROD_USERNAME'] ?: 'root',
                'password' => $_ENV['DB_PROD_PASSWORD'] ?: '',
                'dbname'   => $_ENV['DB_PROD_DBNAME']   ?: '',
            ]
        ];
    }

    public static function Conn(string $database = 'dev', string $charset = 'utf8mb4'): PDO
    {
        try {
            if (!isset(self::$dbConfigs)) {
                self::init();
            }

            $dbConfig = self::$dbConfigs[$database] ?? throw new PDOException("Database não encontrada: {$database}.");

            $dsn = self::getDSN($dbConfig, 'mysql', $charset);
            $conn = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $conn;

        } catch (PDOException $e) {
            exit(json_encode([
                "code"  => "error",
                "msg"   => "Erro na conexão com o banco de dados: " . $e->getMessage(),
                "error" => $e->getCode()
            ]));
        }
    }

    private static function getDSN(array $dbConfig, string $banco, string $charset): string
    {
        $host   = $dbConfig['host'];
        $port   = $dbConfig['port'];
        $dbname = $dbConfig['dbname'];

        return match ($banco) {
            'mysql' => "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}",
            default => throw new PDOException("Driver de conexão não encontrado para: {$banco}")
        };
    }
}
