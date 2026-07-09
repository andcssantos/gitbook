<?php

namespace App\Utils\Security;

use App\Utils\Functions\CacheManager;

class NonceStore
{
    private CacheManager $cache;

    public function __construct(?CacheManager $cache = null)
    {
        $this->cache = $cache ?? new CacheManager('security/nonces', 'medium');
    }

    public function consume(string $nonce, int $ttl): bool
    {
        $key = 'nonce:' . hash('sha256', $nonce);

        if ($this->cache->has($key)) {
            return false;
        }

        return $this->cache->set($key, true, max(1, $ttl));
    }
}
