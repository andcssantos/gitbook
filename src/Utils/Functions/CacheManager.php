<?php

namespace App\Utils\Functions;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Exception;
use JsonException;

class CacheManager
{
    private const EXTENSION = '.json.gz';
    private const DIRECTORY_PERMISSIONS = 0755;

    private string $baseDir;
    private int $defaultTtl;
    private float $cleanProbability;
    private string $logFile;
    private array $memoryCache = [];

    public function __construct(
        string $subDir = '',
        int $defaultTtl = 3600,
        float $cleanProbability = 0.001,
        ?string $logFile = null
    ) {
        // Define o diretório base de forma mais flexível
        $this->baseDir = $this->resolveCachePath($subDir);
        $this->defaultTtl = max(1, $defaultTtl);
        $this->cleanProbability = max(0.0, min(1.0, $cleanProbability));
        $this->logFile = $logFile ?: "{$this->baseDir}/cache_errors.log";

        $this->ensureDirectoryExists($this->baseDir);

        // Limpeza probabilística (Garbage Collection)
        if ((mt_rand() / mt_getrandmax()) < $this->cleanProbability) {
            $this->clean();
        }
    }

    /**
     * Recupera um item do cache.
     */
    public function get(string $key): mixed
    {
        // 1. Check na memória (L1 Cache)
        if (array_key_exists($key, $this->memoryCache)) {
            return $this->memoryCache[$key];
        }

        $path = $this->getFilePath($key);

        if (!is_file($path)) {
            return null;
        }

        try {
            $content = file_get_contents($path);
            if ($content === false) {
                return null;
            }

            $json = gzdecode($content);
            if ($json === false) {
                throw new Exception("Falha ao descompactar.");
            }

            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (!$this->isValidPayload($payload)) {
                $this->delete($key);
                return null;
            }
            
            // Validação de integridade e expiração
            if ($this->isExpired($payload) || !$this->verifyChecksum($payload)) {
                $this->delete($key);
                return null;
            }

            return $this->memoryCache[$key] = $payload['data'];

        } catch (Exception | JsonException $e) {
            $this->logError("Erro ao ler cache [{$key}]: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Salva um item no cache.
     */
    public function set(string $key, mixed $data, ?int $ttl = null): bool
    {
        try {
            $path = $this->getFilePath($key);
            $this->ensureDirectoryExists(dirname($path));

            $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            
            $payload = [
                'data'       => $data,
                'created_at' => time(),
                'ttl'        => $ttl ?? $this->defaultTtl,
                'checksum'   => hash('sha256', $dataJson) // Checksum direto do JSON dos dados
            ];

            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $compressed = gzencode($payloadJson);

            if ($compressed === false) {
                throw new RuntimeException('Falha ao compactar payload do cache.');
            }
            
            if (file_put_contents($path, $compressed, LOCK_EX)) {
                $this->memoryCache[$key] = $data;
                return true;
            }

            return false;
        } catch (Exception | JsonException $e) {
            $this->logError("Erro ao gravar cache [{$key}]: " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): void
    {
        $path = $this->getFilePath($key);
        if (is_file($path)) {
            unlink($path);
        }
        unset($this->memoryCache[$key]);
    }

    /**
     * Limpa arquivos expirados ou corrompidos.
     */
    public function clean(): void
    {
        if (!is_dir($this->baseDir)) return;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'gz') {
                $this->validateAndCleanupFile($file->getPathname());
            }
        }
    }

    // --- Métodos Privados de Suporte ---

    private function resolveCachePath(string $subDir): string
    {
        $base = realpath(__DIR__ . '/../../') . '/.cache';
        return $subDir ? "{$base}/" . trim($subDir, '/') : $base;
    }

    private function getFilePath(string $key): string
    {
        $hash = md5($key);
        // Estrutura de pastas aninhadas (ex: .cache/ab/cd/hash.json.gz)
        $subDirs = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        return "{$this->baseDir}/{$subDirs}/{$hash}" . self::EXTENSION;
    }

    private function isExpired(array $payload): bool
    {
        if ((int) $payload['ttl'] <= 0) {
            return false;
        }

        return (time() - (int) $payload['created_at']) > (int) $payload['ttl'];
    }

    private function verifyChecksum(array $payload): bool
    {
        $json = json_encode($payload['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $currentChecksum = hash('sha256', $json);
        return hash_equals($payload['checksum'], $currentChecksum);
    }

    private function isValidPayload(mixed $payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        foreach (['data', 'created_at', 'ttl', 'checksum'] as $requiredField) {
            if (!array_key_exists($requiredField, $payload)) {
                return false;
            }
        }

        if (!is_int($payload['created_at']) || !is_int($payload['ttl'])) {
            return false;
        }

        return is_string($payload['checksum']) && $payload['checksum'] !== '';
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, self::DIRECTORY_PERMISSIONS, true) && !is_dir($path)) {
            throw new RuntimeException("Falha ao criar diretório: {$path}");
        }
    }

    private function validateAndCleanupFile(string $filePath): void
    {
        $content = @file_get_contents($filePath);
        $json = $content ? @gzdecode($content) : null;
        $payload = $json ? json_decode($json, true) : null;

        if (!$payload || $this->isExpired($payload)) {
            @unlink($filePath);
        }
    }

    private function logError(string $message): void
    {
        $date = date('Y-m-d H:i:s');
        error_log("[{$date}] {$message}\n", 3, $this->logFile);
    }
}
