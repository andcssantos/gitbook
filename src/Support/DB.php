<?php

namespace App\Support;

use App\Database\QueryBuilder;
use App\Utils\Config;
use App\Utils\DB\Connection;
use PDO;
use Throwable;

class DB
{
    private static ?PDO $pdo = null;
    private static int $transactionDepth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = Connection::Conn((string) Config::get('database.default', $_ENV['APP_ENV'] ?? 'dev'));
        }

        return self::$pdo;
    }

    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder(self::pdo(), $table);
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $started = !$pdo->inTransaction();
        $savepoint = null;

        if ($started) {
            $pdo->beginTransaction();
            self::$transactionDepth = 1;
        } else {
            self::$transactionDepth++;
            $savepoint = 'sp_' . self::$transactionDepth;
            $pdo->exec("SAVEPOINT {$savepoint}");
        }

        try {
            $result = $callback($pdo);
            if ($savepoint !== null) {
                $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
                self::$transactionDepth--;
            } elseif ($started) {
                $pdo->commit();
                self::$transactionDepth = 0;
            }

            return $result;
        } catch (Throwable $e) {
            if ($savepoint !== null && $pdo->inTransaction()) {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                self::$transactionDepth--;
            } elseif ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
                self::$transactionDepth = 0;
            }

            throw $e;
        }
    }
}
