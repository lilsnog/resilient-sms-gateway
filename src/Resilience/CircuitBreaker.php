<?php

declare(strict_types=1);

namespace SmsGateway\Resilience;

use SmsGateway\Contracts\Clock;
use SmsGateway\Contracts\StateStore;

/**
 * Per-provider circuit breaker.
 *
 *  CLOSED     normal operation; failures are counted inside a rolling window
 *  OPEN       provider is skipped until the cool-down has passed
 *  HALF_OPEN  after cool-down, a limited number of trial sends are let through;
 *             one success closes the circuit, one failure re-opens it
 */
final class CircuitBreaker
{
    public const CLOSED = 'closed';
    public const OPEN = 'open';
    public const HALF_OPEN = 'half_open';

    public function __construct(
        private readonly StateStore $store,
        private readonly Clock $clock,
        private readonly int $failureThreshold = 5,
        private readonly int $windowSeconds = 60,
        private readonly int $coolDownSeconds = 30,
        private readonly int $halfOpenTrials = 1,
    ) {
    }

    public function state(string $provider): string
    {
        $s = $this->load($provider);

        if ($s['state'] === self::OPEN && $this->clock->now() >= $s['opened_at'] + $this->coolDownSeconds) {
            return self::HALF_OPEN;
        }

        return $s['state'];
    }

    /**
     * Should we try this provider right now? Reserves a trial slot when half-open.
     */
    public function allowRequest(string $provider): bool
    {
        $state = $this->state($provider);

        if ($state === self::CLOSED) {
            return true;
        }

        if ($state === self::OPEN) {
            return false;
        }

        $s = $this->load($provider);
        $s['state'] = self::HALF_OPEN;

        if ($s['trials'] >= $this->halfOpenTrials) {
            $this->save($provider, $s);

            return false;
        }

        $s['trials']++;
        $this->save($provider, $s);

        return true;
    }

    public function recordSuccess(string $provider): void
    {
        $this->save($provider, $this->fresh());
    }

    public function recordFailure(string $provider): void
    {
        $now = $this->clock->now();
        $s = $this->load($provider);

        if ($s['state'] === self::HALF_OPEN || $this->state($provider) === self::HALF_OPEN) {
            $this->open($provider, $now);

            return;
        }

        // Keep only failures inside the rolling window.
        $failures = array_values(array_filter(
            $s['failures'],
            fn (float $t) => $t > $now - $this->windowSeconds,
        ));
        $failures[] = $now;

        if (count($failures) >= $this->failureThreshold) {
            $this->open($provider, $now);

            return;
        }

        $s['failures'] = $failures;
        $this->save($provider, $s);
    }

    private function open(string $provider, float $now): void
    {
        $this->save($provider, [
            'state' => self::OPEN,
            'failures' => [],
            'opened_at' => $now,
            'trials' => 0,
        ]);
    }

    /**
     * @return array{state: string, failures: list<float>, opened_at: float, trials: int}
     */
    private function load(string $provider): array
    {
        /** @var array{state: string, failures: list<float>, opened_at: float, trials: int}|null $s */
        $s = $this->store->get($this->key($provider));

        return $s ?? $this->fresh();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(string $provider, array $state): void
    {
        $this->store->put($this->key($provider), $state);
    }

    /**
     * @return array{state: string, failures: list<float>, opened_at: float, trials: int}
     */
    private function fresh(): array
    {
        return ['state' => self::CLOSED, 'failures' => [], 'opened_at' => 0.0, 'trials' => 0];
    }

    private function key(string $provider): string
    {
        return "sms-gateway:circuit:{$provider}";
    }
}
