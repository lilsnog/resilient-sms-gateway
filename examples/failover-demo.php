<?php

/**
 * Run: php examples/failover-demo.php
 *
 * Simulates a primary provider outage and shows the gateway failing over,
 * opening the primary's circuit, and recovering once the cool-down passes.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SmsGateway\Gateway;
use SmsGateway\Message;
use SmsGateway\Providers\FakeProvider;
use SmsGateway\Resilience\CircuitBreaker;
use SmsGateway\Resilience\InMemoryStateStore;
use SmsGateway\Routing\Router;
use SmsGateway\Tests\ManualClock;

$clock = new ManualClock();
$primary = new FakeProvider('primary');
$backup = new FakeProvider('backup');

$gateway = new Gateway(
    [$primary, $backup],
    new Router(['primary', 'backup']),
    new CircuitBreaker(new InMemoryStateStore(), $clock, failureThreshold: 3, coolDownSeconds: 30),
    $clock,
);

$gateway->onSent(function (Message $m, $result): void {
    $trail = implode(' -> ', array_map(
        fn ($a) => "{$a->provider}:{$a->status}",
        $result->attempts,
    ));
    printf("%-10s %s\n", $result->provider ?? 'FAILED', $trail);
});

echo "== primary starts timing out ==\n";
$primary->failNext(3);
for ($i = 0; $i < 6; $i++) {
    $gateway->send(new Message('+2348012345678', "Alert #{$i}"));
}

echo "\n== 31 seconds later: one trial send to primary ==\n";
$clock->advance(31);
$gateway->send(new Message('+2348012345678', 'Alert after cool-down'));
$gateway->send(new Message('+2348012345678', 'Back to normal'));
