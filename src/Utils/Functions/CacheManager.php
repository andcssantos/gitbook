<?php

namespace App\Utils\Functions;

use App\Utils\Config;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class CacheManager
{
    private const EXTENSION = '.json.gz';
    private const DIRECTORY_PERMISSIONS = 0755;
    private const PAYLOAD_VERSION = 2;

    private string $baseDir;
    private int $defaultTtl;
    private float $cleanProbability;
    private string $logFile;
    private array $memoryCache = [];
    private array $locks = [];

    private static array $stats = [
        'hits' => 0,
        'misses' => 0,
        'stale_hits' => 0,
        'writes' => 0,
        'deletes' => 0,
        'lock_hits' => 0,
        'errors' => 0,
    ];

    public function __construct(
        string $subDir = '',
        int|string|null $defaultTtl = null,
        float $cleanProbability = 0.001,
        ?string $logFile = null
    ) {
        $this->baseDir = $this->resolveCachePath($subDir);
        $this->defaultTtl = $this->resolveTtl($defaultTtl);
        $this->cleanProbability = max(0.0, min(1.0, $cleanProbability));
        $this->logFile = $logFile ?: "{$this->baseDir}/cache_errors.log";

        $this->ensureDirectoryExists($this->baseDir);

        if ((mt_rand() / mt_getrandmax()) < $this->cleanProbability) {
            $this->clean();
        }
    }

    public function get(string $key): mixed
    {
        $payload = $this->readPayload($key);

        if ($payload === null) {
            self::$stats['misses']++;
            return null;
        }

        if ($this->isExpired($payload)) {
            $this->delete($key);
            self::$stats['misses']++;
            return null;
        }

        self::$stats['hits']++;
        return $payload['data'];
    }

    public function has(string $key): bool
    {
        $payload = $this->readPayload($key);

        return $payload !== null && !$this->isExpired($payload);
    }

    public function set(string $key, mixed $data, int|string|null $ttl = null, array $options = []): bool
    {
        try {
            $path = $this->getFilePath($key);
            $this->ensureDirectoryExists(dirname($path));

            $payload = [
                'version' => self::PAYLOAD_VERSION,
                'key' => $key,
                'data' => $data,
                'created_at' => time(),
                'ttl' => $this->resolveTtl($ttl),
                'stale_ttl' => $this->resolveOptionalTtl($options['stale_ttl'] ?? null),
                'tags' => array_values(array_unique(array_map('strval', (array) ($options['tags'] ?? [])))),
            ];
            $payload['checksum'] = $this->signPayload($payload);

            $compressed = gzencode($this->jsonEncode($payload));
            if ($compressed === false) {
                throw new RuntimeException('Falha ao compactar payload do cache.');
            }

            if (file_put_contents($path, $compressed, LOCK_EX) === false) {
                return false;
            }

            $this->indexTags($key, $payload['tags']);
            $this->storeMemoryPayload($key, $path, $payload);
            self::$stats['writes']++;

            return true;
        } catch (Throwable $e) {
            $this->logError("Erro ao gravar cache [{$key}]: " . $e->getMessage());
            self::$stats['errors']++;
            return false;
        }
    }

    public function remember(string $key, callable $resolver, int|string|null $ttl = null, array $options = []): mixed
    {
        $payload = $this->readPayload($key);

        if ($payload !== null && !$this->isExpired($payload)) {
            self::$stats['hits']++;
            return $payload['data'];
        }

        self::$stats['misses']++;
        $data = $resolver();
        $this->set($key, $data, $ttl, $options);

        return $data;
    }

    public function rememberStale(
        string $key,
        callable $resolver,
        int|string|null $ttl = null,
        int|string|null $staleTtl = null,
        array $options = []
    ): mixed {
        $payload = $this->readPayload($key, allowExpired: true);

        if ($payload !== null && !$this->isExpired($payload)) {
            self::$stats['hits']++;
            return $payload['data'];
        }

        if ($payload !== null && !$this->isStaleExpired($payload)) {
            if (!$this->acquireLock($key)) {
                self::$stats['stale_hits']++;
                return $payload['data'];
            }

            try {
                $data = $resolver();
                $options['stale_ttl'] = $staleTtl;
                $this->set($key, $data, $ttl, $options);
                return $data;
            } finally {
                $this->releaseLock($key);
            }
        }

        if (!$this->acquireLock($key)) {
            self::$stats['lock_hits']++;
            usleep(50000);
            $payload = $this->readPayload($key);

            if ($payload !== null && !$this->isExpired($payload)) {
                self::$stats['hits']++;
                return $payload['data'];
            }
        }

        try {
            self::$stats['misses']++;
            $data = $resolver();
            $options['stale_ttl'] = $staleTtl;
            $this->set($key, $data, $ttl, $options);
            return $data;
        } finally {
            $this->releaseLock($key);
        }
    }

    public function atomic(string $key, callable $callback, int $seconds = 5): mixed
    {
        if (!$this->acquireLock($key, $seconds)) {
            self::$stats['lock_hits']++;
            return $callback(false);
        }

        try {
            return $callback(true);
        } finally {
            $this->releaseLock($key);
        }
    }

    public function delete(string $key): void
    {
        $path = $this->getFilePath($key);
        $payload = $this->readPayload($key, allowExpired: true);

        if (is_file($path)) {
            @unlink($path);
        }

        if ($payload !== null) {
            $this->removeTags($key, (array) ($payload['tags'] ?? []));
        }

        unset($this->memoryCache[$key]);
        self::$stats['deletes']++;
    }

    public function flushTag(string $tag): void
    {
        foreach ($this->readTagIndex($tag) as $key) {
            $this->delete($key);
        }

        $path = $this->getTagPath($tag);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function clean(): void
    {
        if (!is_dir($this->baseDir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'gz') {
                continue;
            }

            $this->validateAndCleanupFile($file->getPathname());
        }

        $this->cleanTagIndexes();
        $this->cleanLocks();
        $this->removeEmptyDirectories($this->baseDir);
    }

    public static function stats(): array
    {
        return self::$stats;
    }

    public static function resetStats(): void
    {
        foreach (self::$stats as $key => $value) {
            self::$stats[$key] = 0;
        }
    }

    private function readPayload(string $key, bool $allowExpired = false): ?array
    {
        $path = $this->getFilePath($key);

        if (array_key_exists($key, $this->memoryCache)) {
            $cached = $this->memoryCache[$key];

            if (
                is_array($cached)
                && isset($cached['payload'], $cached['file_hash'])
                && is_file($path)
                && hash_file('sha256', $path) === $cached['file_hash']
            ) {
                $payload = $cached['payload'];

                if ($this->isValidPayload($payload) && ($allowExpired || !$this->isExpired($payload))) {
                    return $payload;
                }
            }

            unset($this->memoryCache[$key]);
        }

        if (!is_file($path)) {
            return null;
        }

        try {
            $content = file_get_contents($path);
            $json = $content === false ? false : gzdecode($content);

            if ($json === false) {
                throw new RuntimeException('Falha ao descompactar payload.');
            }

            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!$this->isValidPayload($payload)) {
                @unlink($path);
                unset($this->memoryCache[$key]);
                return null;
            }

            $this->storeMemoryPayload($key, $path, $payload);
            return $payload;
        } catch (Throwable $e) {
            $this->logError("Erro ao ler cache [{$key}]: " . $e->getMessage());
            @unlink($path);
            unset($this->memoryCache[$key]);
            self::$stats['errors']++;
            return null;
        }
    }

    private function resolveCachePath(string $subDir): string
    {
        $base = Config::get('cache.path');
        if (!is_string($base) || $base === '') {
            $base = realpath(__DIR__ . '/../../') . '/.cache';
        }

        return $subDir ? rtrim($base, '/\\') . '/' . trim($subDir, '/\\') : rtrim($base, '/\\');
    }

    private function getFilePath(string $key): string
    {
        $hash = hash('sha256', $key);
        $subDirs = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);

        return "{$this->baseDir}/{$subDirs}/{$hash}" . self::EXTENSION;
    }

    private function isExpired(array $payload): bool
    {
        $ttl = (int) $payload['ttl'];
        if ($ttl <= 0) {
            return false;
        }

        return (time() - (int) $payload['created_at']) > $ttl;
    }

    private function isStaleExpired(array $payload): bool
    {
        $ttl = (int) $payload['ttl'];
        $staleTtl = (int) ($payload['stale_ttl'] ?? 0);

        if ($ttl <= 0 || $staleTtl <= 0) {
            return true;
        }

        return (time() - (int) $payload['created_at']) > ($ttl + $staleTtl);
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

        return is_string($payload['checksum'])
            && $payload['checksum'] !== ''
            && hash_equals($payload['checksum'], $this->signPayload($payload));
    }

    private function signPayload(array $payload): string
    {
        $signable = $payload;
        unset($signable['checksum']);

        $json = $this->jsonEncode($signable);
        $secret = $this->secret();

        return $secret !== ''
            ? hash_hmac('sha256', $json, $secret)
            : hash('sha256', $json);
    }

    private function secret(): string
    {
        foreach (['CACHE_KEY', 'MASTER_KEY', 'JWT_KEY'] as $key) {
            $value = trim((string) ($_ENV[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveTtl(int|string|null $ttl): int
    {
        if ($ttl === null) {
            $ttl = Config::get('cache.default_ttl', 3600);
        }

        if (is_string($ttl) && !is_numeric($ttl)) {
            $ttl = Config::get("cache.ttl_profiles.{$ttl}", $this->defaultTtl ?? 3600);
        }

        return max(0, (int) $ttl);
    }

    private function resolveOptionalTtl(int|string|null $ttl): int
    {
        if ($ttl === null) {
            return 0;
        }

        return $this->resolveTtl($ttl);
    }

    private function acquireLock(string $key, int $seconds = 5): bool
    {
        $path = $this->getLockPath($key);
        $this->ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'c');
        if ($handle === false) {
            return false;
        }

        $deadline = microtime(true) + $seconds;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->locks[$key] = $handle;
                return true;
            }

            usleep(50000);
        } while (microtime(true) < $deadline);

        fclose($handle);
        return false;
    }

    private function releaseLock(string $key): void
    {
        if (!isset($this->locks[$key])) {
            return;
        }

        flock($this->locks[$key], LOCK_UN);
        fclose($this->locks[$key]);
        unset($this->locks[$key]);
    }

    private function getLockPath(string $key): string
    {
        return "{$this->baseDir}/_locks/" . hash('sha256', $key) . '.lock';
    }

    private function indexTags(string $key, array $tags): void
    {
        foreach ($tags as $tag) {
            $keys = $this->readTagIndex($tag);
            $keys[] = $key;
            $keys = array_values(array_unique($keys));

            $path = $this->getTagPath($tag);
            $this->ensureDirectoryExists(dirname($path));
            file_put_contents($path, $this->jsonEncode($keys), LOCK_EX);
        }
    }

    private function removeTags(string $key, array $tags): void
    {
        foreach ($tags as $tag) {
            $keys = array_values(array_filter(
                $this->readTagIndex($tag),
                fn (string $cachedKey): bool => $cachedKey !== $key
            ));

            $path = $this->getTagPath($tag);
            if ($keys === []) {
                @unlink($path);
                continue;
            }

            file_put_contents($path, $this->jsonEncode($keys), LOCK_EX);
        }
    }

    private function readTagIndex(string $tag): array
    {
        $path = $this->getTagPath($tag);
        if (!is_file($path)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
        } catch (JsonException) {
            @unlink($path);
            return [];
        }
    }

    private function getTagPath(string $tag): string
    {
        return "{$this->baseDir}/_tags/" . hash('sha256', $tag) . '.json';
    }

    private function storeMemoryPayload(string $key, string $path, array $payload): void
    {
        $this->memoryCache[$key] = [
            'payload' => $payload,
            'file_hash' => hash_file('sha256', $path),
        ];
    }

    private function validateAndCleanupFile(string $filePath): void
    {
        $content = @file_get_contents($filePath);
        $json = $content ? @gzdecode($content) : null;
        $payload = $json ? json_decode($json, true) : null;

        if (!$this->isValidPayload($payload) || $this->isStaleExpired($payload)) {
            if (is_array($payload) && isset($payload['key'])) {
                $this->removeTags((string) $payload['key'], (array) ($payload['tags'] ?? []));
                unset($this->memoryCache[(string) $payload['key']]);
            }

            @unlink($filePath);
        }
    }

    private function cleanTagIndexes(): void
    {
        $tagDir = "{$this->baseDir}/_tags";
        if (!is_dir($tagDir)) {
            return;
        }

        foreach (glob($tagDir . '/*.json') ?: [] as $path) {
            try {
                $keys = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                @unlink($path);
                continue;
            }

            if (!is_array($keys)) {
                @unlink($path);
                continue;
            }

            $activeKeys = [];
            foreach ($keys as $key) {
                if (is_string($key) && $this->readPayload($key) !== null) {
                    $activeKeys[] = $key;
                }
            }

            if ($activeKeys === []) {
                @unlink($path);
                continue;
            }

            file_put_contents($path, $this->jsonEncode(array_values(array_unique($activeKeys))), LOCK_EX);
        }
    }

    private function cleanLocks(int $maxAgeSeconds = 300): void
    {
        $lockDir = "{$this->baseDir}/_locks";
        if (!is_dir($lockDir)) {
            return;
        }

        foreach (glob($lockDir . '/*.lock') ?: [] as $path) {
            if (is_file($path) && (time() - filemtime($path)) > $maxAgeSeconds) {
                @unlink($path);
            }
        }
    }

    private function removeEmptyDirectories(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, self::DIRECTORY_PERMISSIONS, true) && !is_dir($path)) {
            throw new RuntimeException("Falha ao criar diretorio: {$path}");
        }
    }

    /**
     * @throws JsonException
     */
    private function jsonEncode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function logError(string $message): void
    {
        $date = date('Y-m-d H:i:s');
        error_log("[{$date}] {$message}\n", 3, $this->logFile);
    }
}
