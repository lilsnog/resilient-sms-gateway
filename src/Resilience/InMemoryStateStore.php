<?php

declare(strict_types=1);

namespace SmsGateway\Resilience;

use SmsGateway\Contracts\StateStore;

/**
 * Process-local store. Fine for tests and single-process scripts; use a shared
 * cache in production so every worker sees the same circuit state.
 */
final class InMemoryStateStore implements StateStore
{
    /** @var array<string, array<string, mixed>> */
    private array $items = [];

    public function get(string $key): ?array
    {
        return $this->items[$key] ?? null;
    }

    public function put(string $key, array $value): void
    {
        $this->items[$key] = $value;
    }
}
