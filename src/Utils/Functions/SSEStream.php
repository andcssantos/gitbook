<?php

namespace App\Utils\Functions;

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
        // Instanciamos o cache uma única vez
        $this->cache = new CacheManager($cacheNamespace, $defaultTtl);
        $this->cacheKey = $cacheKey;
        $this->fetchCallback = $fetchCallback;
        $this->maxMessages = $maxMessages;
        $this->sleepSeconds = $sleepSeconds;
        $this->eventName = $eventName;
    }

    public function start(): void
    {
        // Headers de protocolo para fluxo contínuo
        header("Content-Type: text/event-stream");
        header("Cache-Control: no-cache");
        header("Connection: keep-alive");
        header("Access-Control-Allow-Origin: *");
        header("X-Accel-Buffering: no");

        // Libera o arquivo de sessão para não travar o restante do sistema
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $lastHash = null;
        $messageCount = 0;

        // Instrução para o navegador reconectar em 1s se a conexão cair
        echo "retry: 1000\n\n";
        $this->flushBuffer();

        while (true) {
            // Tenta obter dados do cache primeiro
            $data = $this->cache->get($this->cacheKey);
            $source = 'cache';

            if ($data === null) {
                // Se expirou no cache, busca na fonte original (DB/API)
                $data = ($this->fetchCallback)();
                $this->cache->set($this->cacheKey, $data);
                $source = 'origin';
            }

            // Gera hash para verificar mudança de estado
            $currentHash = md5(json_encode($data));

            if ($currentHash !== $lastHash) {
                $lastHash = $currentHash;

                $payload = json_encode([
                    "status"  => "success",
                    "source"  => $source,
                    "event"   => $this->eventName,
                    "data"    => $data,
                    "timestamp" => time()
                ], JSON_UNESCAPED_UNICODE);
                
                echo "event: {$this->eventName}\n";
                echo "data: {$payload}\n\n";

                $this->flushBuffer();
                $messageCount++;
            }

            // Critérios de saída: desconexão ou limite de mensagens
            if (connection_aborted() || $messageCount >= $this->maxMessages) {
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
}