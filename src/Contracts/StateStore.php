<?php

declare(strict_types=1);

namespace SmsGateway\Contracts;

/**
 * Where circuit-breaker state lives. Use a shared store (Redis, database cache)
 * when several app servers or queue workers send SMS, so they agree on which
 * providers are currently unhealthy.
 */
interface StateStore
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param array<string, mixed> $value
     */
    public function put(string $key, array $value): void;
}
