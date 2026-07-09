<?php

namespace App\Utils\Functions;

use App\Utils\Config;

class SSEStream
{
    private CacheManager $cache;
    private string $cacheKey;
    private string $eventName;
    private int $maxMessages;
    private int $sleepSeconds;
    private \Closure $fetchCallback;

    public function __construct(
        string $cacheNamespace,
        string $cacheKey,
        \Closure $fetchCallback,
        int $defaultTtl = 60,
        int $maxMessages = 90,
        int $sleepSeconds = 2,
        string $eventName = 'update'
    ) {
        $this->cache = new CacheManager($cacheNamespace, $defaultTtl);
        $this->cacheKey = $cacheKey;
        $this->fetchCallback = $fetchCallback;
        $this->maxMessages = max(1, $maxMessages);
        $this->sleepSeconds = max(1, $sleepSeconds);
        $this->eventName = $this->sanitizeEventName($eventName);
    }

    public function start(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        $this->sendCorsHeader();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $lastHash = null;
        $messageCount = 0;
        $startedAt = time();
        $lastHeartbeat = time();
        $maxSeconds = max(1, (int) Config::get('security.sse.max_seconds', 120));
        $heartbeatSeconds = max(1, (int) Config::get('security.sse.heartbeat_seconds', 15));

        echo "retry: 1000\n\n";
        $this->flushBuffer();

        while (true) {
            $source = 'cache';
            $data = $this->cache->rememberStale(
                $this->cacheKey,
                function () use (&$source): mixed {
                    $source = 'origin';
                    return ($this->fetchCallback)();
                },
                null,
                $this->sleepSeconds * 3
            );

            $encodedData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $currentHash = hash('sha256', $encodedData === false ? '' : $encodedData);

            if ($currentHash !== $lastHash) {
                $lastHash = $currentHash;

                $payload = json_encode([
                    'status' => 'success',
                    'source' => $source,
                    'event' => $this->eventName,
                    'data' => $data,
                    'timestamp' => time(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                echo "event: {$this->eventName}\n";
                echo 'data: ' . ($payload ?: '{}') . "\n\n";
                $this->flushBuffer();
                $messageCount++;
            }

            if ((time() - $lastHeartbeat) >= $heartbeatSeconds) {
                echo ': heartbeat ' . time() . "\n\n";
                $this->flushBuffer();
                $lastHeartbeat = time();
            }

            if (
                connection_aborted()
                || $messageCount >= $this->maxMessages
                || (time() - $startedAt) >= $maxSeconds
            ) {
                break;
            }

            sleep($this->sleepSeconds);
        }
    }

    private function flushBuffer(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    private function sendCorsHeader(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = (array) Config::get('security.sse.allowed_origins', []);

        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Vary: Origin');
        }
    }

    private function sanitizeEventName(string $eventName): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '', $eventName) ?: 'update';
    }
}
