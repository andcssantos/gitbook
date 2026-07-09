<?php

namespace App\Security;

use App\Http\HttpException;
use App\Http\Request;
use App\Support\DB;
use App\Utils\Config;
use App\Utils\Functions\CacheManager;
use PDO;
use PDOException;
use Throwable;

class Idempotency
{
    private CacheManager $cache;
    private ?PDO $pdo;

    public function __construct(?CacheManager $cache = null, ?PDO $pdo = null)
    {
        $this->cache = $cache ?? new CacheManager('security/idempotency', (int) Config::get('idempotency.ttl', 86400));
        $this->pdo = $pdo;
    }

    public function key(?string $key = null): ?string
    {
        $header = (string) Config::get('idempotency.header', 'Idempotency-Key');

        return $key ?? Request::header($header);
    }

    public function handle(string $scope, callable $callback, ?string $key = null): mixed
    {
        $key = $this->key($key);
        if (!$key) {
            return $callback();
        }

        if ((string) Config::get('idempotency.driver', 'database') === 'database') {
            return $this->handleDatabase($scope, $key, $callback);
        }

        return $this->handleCache($scope, $key, $callback);
    }

    public function seen(string $scope, ?string $key = null): bool
    {
        $key = $this->key($key);
        if (!$key) {
            return false;
        }

        if ((string) Config::get('idempotency.driver', 'database') === 'database') {
            $pdo = $this->pdo ?? DB::pdo();
            $stmt = $pdo->prepare('SELECT id FROM ' . $this->table() . ' WHERE scope = :scope AND key_hash = :key_hash AND expires_at > :now LIMIT 1');
            $stmt->execute([
                'scope' => $scope,
                'key_hash' => $this->cacheKey($scope, $key),
                'now' => $this->now(),
            ]);

            return (bool) $stmt->fetchColumn();
        }

        return $this->cache->has($this->cacheKey($scope, $key));
    }

    private function handleCache(string $scope, string $key, callable $callback): mixed
    {
        $cacheKey = $this->cacheKey($scope, $key);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && array_key_exists('result', $cached)) {
            if (($cached['request_hash'] ?? null) !== $this->requestHash()) {
                throw new HttpException('Idempotency-Key reused with a different request payload.', 409);
            }

            return $cached['result'];
        }

        $result = $callback();
        $this->cache->set($cacheKey, [
            'result' => $result,
            'created_at' => time(),
            'request_hash' => hash('sha256', Request::method() . '|' . Request::path() . '|' . Request::rawBody()),
        ], (int) Config::get('idempotency.ttl', 86400));

        return $result;
    }

    private function handleDatabase(string $scope, string $key, callable $callback): mixed
    {
        $pdo = $this->pdo ?? DB::pdo();
        $requestHash = $this->requestHash();
        $keyHash = $this->cacheKey($scope, $key);
        $table = $this->table();
        $ttl = (int) Config::get('idempotency.ttl', 86400);
        $started = !$pdo->inTransaction();

        if ($started) {
            $pdo->beginTransaction();
        }

        try {
            $result = $this->reserveAndRun($pdo, $table, $scope, $keyHash, $requestHash, $ttl, $callback);
            if ($started) {
                $pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private function reserveAndRun(PDO $pdo, string $table, string $scope, string $keyHash, string $requestHash, int $ttl, callable $callback): mixed
    {
        try {
            $this->insertReservation($pdo, $table, $scope, $keyHash, $requestHash, $ttl);
            $result = $callback();
            $this->completeReservation($pdo, $table, $scope, $keyHash, $result);

            return $result;
        } catch (PDOException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }

            $row = $this->findReservationForUpdate($pdo, $table, $scope, $keyHash);
            if ($row === null || (string) ($row['expires_at'] ?? '') <= $this->now()) {
                $this->resetReservation($pdo, $table, $scope, $keyHash, $requestHash, $ttl);
                $result = $callback();
                $this->completeReservation($pdo, $table, $scope, $keyHash, $result);

                return $result;
            }

            if (($row['request_hash'] ?? null) !== $requestHash) {
                throw new HttpException('Idempotency-Key reused with a different request payload.', 409);
            }

            if (($row['status'] ?? null) === 'completed') {
                return $this->decodeResult((string) ($row['response_payload'] ?? ''));
            }

            throw new HttpException('Idempotent request is already processing.', 409);
        }
    }

    private function insertReservation(PDO $pdo, string $table, string $scope, string $keyHash, string $requestHash, int $ttl): void
    {
        $stmt = $pdo->prepare("INSERT INTO {$table} (scope, key_hash, request_hash, status, expires_at) VALUES (:scope, :key_hash, :request_hash, 'processing', :expires_at)");
        $stmt->execute([
            'scope' => $scope,
            'key_hash' => $keyHash,
            'request_hash' => $requestHash,
            'expires_at' => $this->expiresAt($ttl),
        ]);
    }

    private function completeReservation(PDO $pdo, string $table, string $scope, string $keyHash, mixed $result): void
    {
        $stmt = $pdo->prepare("UPDATE {$table} SET status = 'completed', response_payload = :response_payload, completed_at = :completed_at WHERE scope = :scope AND key_hash = :key_hash");
        $stmt->execute([
            'response_payload' => json_encode(['result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'completed_at' => $this->now(),
            'scope' => $scope,
            'key_hash' => $keyHash,
        ]);
    }

    private function resetReservation(PDO $pdo, string $table, string $scope, string $keyHash, string $requestHash, int $ttl): void
    {
        $stmt = $pdo->prepare("UPDATE {$table} SET request_hash = :request_hash, status = 'processing', response_payload = NULL, completed_at = NULL, expires_at = :expires_at WHERE scope = :scope AND key_hash = :key_hash");
        $stmt->execute([
            'request_hash' => $requestHash,
            'expires_at' => $this->expiresAt($ttl),
            'scope' => $scope,
            'key_hash' => $keyHash,
        ]);
    }

    private function findReservationForUpdate(PDO $pdo, string $table, string $scope, string $keyHash): ?array
    {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE scope = :scope AND key_hash = :key_hash LIMIT 1{$lock}");
        $stmt->execute([
            'scope' => $scope,
            'key_hash' => $keyHash,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function decodeResult(string $payload): mixed
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) && array_key_exists('result', $decoded) ? $decoded['result'] : null;
    }

    private function cacheKey(string $scope, string $key): string
    {
        return hash('sha256', $scope . '|' . $key);
    }

    private function requestHash(): string
    {
        return hash('sha256', Request::method() . '|' . Request::path() . '|' . Request::rawBody());
    }

    private function table(): string
    {
        $table = (string) Config::get('idempotency.table', 'idempotency_keys');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new HttpException('Invalid idempotency table configuration.', 500);
        }

        return $table;
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        return $e->getCode() === '23000' || str_contains($e->getMessage(), '1062') || str_contains(strtolower($e->getMessage()), 'unique');
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function expiresAt(int $ttl): string
    {
        return date('Y-m-d H:i:s', time() + max(1, $ttl));
    }
}
