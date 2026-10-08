<?php

declare(strict_types=1);

namespace SmsGateway\Tests;

use PHPUnit\Framework\TestCase;
use SmsGateway\Resilience\CircuitBreaker;
use SmsGateway\Resilience\InMemoryStateStore;

final class CircuitBreakerTest extends TestCase
{
    private ManualClock $clock;
    private CircuitBreaker $breaker;

    protected function setUp(): void
    {
        $this->clock = new ManualClock();
        $this->breaker = new CircuitBreaker(
            new InMemoryStateStore(),
            $this->clock,
            failureThreshold: 3,
            windowSeconds: 60,
            coolDownSeconds: 30,
        );
    }

    public function testStartsClosed(): void
    {
        $this->assertSame(CircuitBreaker::CLOSED, $this->breaker->state('p'));
        $this->assertTrue($this->breaker->allowRequest('p'));
    }

    public function testOpensAfterThresholdFailuresInsideWindow(): void
    {
        $this->breaker->recordFailure('p');
        $this->breaker->recordFailure('p');
        $this->assertSame(CircuitBreaker::CLOSED, $this->breaker->state('p'));

        $this->breaker->recordFailure('p');
        $this->assertSame(CircuitBreaker::OPEN, $this->breaker->state('p'));
        $this->assertFalse($this->breaker->allowRequest('p'));
    }

    public function testFailuresOutsideWindowAreForgotten(): void
    {
        $this->breaker->recordFailure('p');
        $this->breaker->recordFailure('p');
        $this->clock->advance(61);
        $this->breaker->recordFailure('p');

        $this->assertSame(CircuitBreaker::CLOSED, $this->breaker->state('p'));
    }

    public function testHalfOpensAfterCoolDownAndAllowsOneTrial(): void
    {
        $this->trip();
        $this->clock->advance(30);

        $this->assertSame(CircuitBreaker::HALF_OPEN, $this->breaker->state('p'));
        $this->assertTrue($this->breaker->allowRequest('p'));
        $this->assertFalse($this->breaker->allowRequest('p'), 'only one trial while half-open');
    }

    public function testSuccessfulTrialClosesCircuit(): void
    {
        $this->trip();
        $this->clock->advance(30);
        $this->breaker->allowRequest('p');
        $this->breaker->recordSuccess('p');

        $this->assertSame(CircuitBreaker::CLOSED, $this->breaker->state('p'));
    }

    public function testFailedTrialReopensCircuitWithFreshCoolDown(): void
    {
        $this->trip();
        $this->clock->advance(30);
        $this->breaker->allowRequest('p');
        $this->breaker->recordFailure('p');

        $this->assertSame(CircuitBreaker::OPEN, $this->breaker->state('p'));
        $this->clock->advance(29);
        $this->assertSame(CircuitBreaker::OPEN, $this->breaker->state('p'));
        $this->clock->advance(1);
        $this->assertSame(CircuitBreaker::HALF_OPEN, $this->breaker->state('p'));
    }

    public function testProvidersAreTrackedIndependently(): void
    {
        $this->trip();

        $this->assertSame(CircuitBreaker::OPEN, $this->breaker->state('p'));
        $this->assertSame(CircuitBreaker::CLOSED, $this->breaker->state('other'));
    }

    private function trip(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->breaker->recordFailure('p');
        }
    }
}
