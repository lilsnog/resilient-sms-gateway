<?php

declare(strict_types=1);

namespace SmsGateway\Tests;

use PHPUnit\Framework\TestCase;
use SmsGateway\Attempt;
use SmsGateway\Gateway;
use SmsGateway\Message;
use SmsGateway\Providers\FakeProvider;
use SmsGateway\Resilience\CircuitBreaker;
use SmsGateway\Resilience\InMemoryStateStore;
use SmsGateway\Routing\Router;
use SmsGateway\SendResult;

final class GatewayTest extends TestCase
{
    private FakeProvider $primary;
    private FakeProvider $backup;
    private ManualClock $clock;
    private Gateway $gateway;

    protected function setUp(): void
    {
        $this->primary = new FakeProvider('primary');
        $this->backup = new FakeProvider('backup');
        $this->clock = new ManualClock();

        $this->gateway = new Gateway(
            [$this->primary, $this->backup],
            new Router(['primary', 'backup']),
            new CircuitBreaker(new InMemoryStateStore(), $this->clock, failureThreshold: 2, coolDownSeconds: 30),
            $this->clock,
        );
    }

    public function testSendsThroughFirstProvider(): void
    {
        $result = $this->gateway->send($this->message());

        $this->assertTrue($result->delivered);
        $this->assertSame('primary', $result->provider);
        $this->assertSame('primary-0001', $result->providerMessageId);
        $this->assertCount(1, $this->primary->sent);
        $this->assertCount(0, $this->backup->sent);
    }

    public function testFailsOverWhenPrimaryErrors(): void
    {
        $this->primary->failNext();

        $result = $this->gateway->send($this->message());

        $this->assertTrue($result->delivered);
        $this->assertSame('backup', $result->provider);
        $this->assertSame([Attempt::FAILED, Attempt::SENT], array_map(fn ($a) => $a->status, $result->attempts));
    }

    public function testSkipsProviderWhoseCircuitIsOpen(): void
    {
        $this->primary->failNext(2);
        $this->gateway->send($this->message());
        $this->gateway->send($this->message()); // second failure opens primary's circuit

        $result = $this->gateway->send($this->message());

        $this->assertSame('backup', $result->provider);
        $this->assertSame(Attempt::SKIPPED_CIRCUIT_OPEN, $result->attempts[0]->status);
        $this->assertCount(0, $this->primary->sent, 'primary was never called while open');
    }

    public function testPrimaryRecoversAfterCoolDown(): void
    {
        $this->primary->failNext(2);
        $this->gateway->send($this->message());
        $this->gateway->send($this->message());

        $this->clock->advance(31);
        $result = $this->gateway->send($this->message());

        $this->assertSame('primary', $result->provider);
    }

    public function testPermanentRejectionStopsFailover(): void
    {
        $this->primary->rejectNext();

        $result = $this->gateway->send($this->message());

        $this->assertFalse($result->delivered);
        $this->assertCount(1, $result->attempts);
        $this->assertCount(0, $this->backup->sent);
        $this->assertStringContainsString('rejected', (string) $result->lastError());
    }

    public function testReportsFailureWhenEveryProviderFails(): void
    {
        $this->primary->failNext();
        $this->backup->failNext();

        $result = $this->gateway->send($this->message());

        $this->assertTrue($result->failed());
        $this->assertCount(2, $result->attempts);
    }

    public function testListenersReceiveEveryResult(): void
    {
        $seen = [];
        $this->gateway->onSent(function (Message $m, SendResult $r) use (&$seen): void {
            $seen[] = $r->provider;
        });

        $this->gateway->send($this->message());
        $this->primary->failNext();
        $this->gateway->send($this->message());

        $this->assertSame(['primary', 'backup'], $seen);
    }

    private function message(): Message
    {
        return new Message('+2348012345678', 'Your one-time code is 482913');
    }
}
