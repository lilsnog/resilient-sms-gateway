<?php

declare(strict_types=1);

namespace SmsGateway\Laravel;

use Illuminate\Contracts\Cache\Repository as Cache;
use SmsGateway\Contracts\StateStore;

/**
 * Stores circuit state in Laravel's cache (use Redis so all workers share it).
 */
final class CacheStateStore implements StateStore
{
    public function __construct(
        private readonly Cache $cache,
        private readonly int $ttlSeconds = 3600,
    ) {
    }

    public function get(string $key): ?array
    {
        $value = $this->cache->get($key);

        return is_array($value) ? $value : null;
    }

    public function put(string $key, array $value): void
    {
        $this->cache->put($key, $value, $this->ttlSeconds);
    }
}
