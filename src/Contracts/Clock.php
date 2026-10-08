<?php

declare(strict_types=1);

namespace SmsGateway\Contracts;

interface Clock
{
    /**
     * Current Unix time in seconds (float for sub-second precision).
     */
    public function now(): float;
}
