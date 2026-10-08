<?php

declare(strict_types=1);

namespace SmsGateway\Tests;

use SmsGateway\Contracts\Clock;

final class ManualClock implements Clock
{
    public function __construct(private float $now = 1_700_000_000.0)
    {
    }

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
