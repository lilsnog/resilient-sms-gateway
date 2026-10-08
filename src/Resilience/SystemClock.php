<?php

declare(strict_types=1);

namespace SmsGateway\Resilience;

use SmsGateway\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }
}
