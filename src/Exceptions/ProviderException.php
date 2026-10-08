<?php

declare(strict_types=1);

namespace SmsGateway\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised by a provider when a send fails.
 *
 * $retryable = false means the message itself is bad (invalid number, blocked
 * content), so trying another provider will not help and the gateway stops.
 */
final class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function permanent(string $message): self
    {
        return new self($message, false);
    }
}
