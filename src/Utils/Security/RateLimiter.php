<?php

namespace App\Utils\Security;

use App\Utils\Functions\CacheManager;

class RateLimiter
{
    private CacheManager $cache;

    public function __construct(?CacheManager $cache = null)
    {
        $this->cache = $cache ?? new CacheManager('security/rate_limit', 'short');
    }

    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return (bool) $this->cache->atomic($this->cacheKey($key), function () use ($key, $maxAttempts, $decaySeconds): bool {
            $state = $this->state($key);
            $now = time();

            if ($state === null || ($state['reset_at'] ?? 0) <= $now) {
                $state = [
                    'count' => 0,
                    'reset_at' => $now + max(1, $decaySeconds),
                ];
            }

            if ((int) $state['count'] >= $maxAttempts) {
                $this->cache->set($this->cacheKey($key), $state, max(1, (int) $state['reset_at'] - $now));
                return false;
            }

            $state['count'] = (int) $state['count'] + 1;
            $this->cache->set($this->cacheKey($key), $state, max(1, (int) $state['reset_at'] - $now));

            return true;
        });
    }

    public function retryAfter(string $key): int
    {
        $state = $this->state($key);
        if ($state === null) {
            return 0;
        }

        return max(0, (int) $state['reset_at'] - time());
    }

    private function state(string $key): ?array
    {
        $state = $this->cache->get($this->cacheKey($key));

        return is_array($state) ? $state : null;
    }

    private function cacheKey(string $key): string
    {
        return 'rate:' . hash('sha256', $key);
    }
}
