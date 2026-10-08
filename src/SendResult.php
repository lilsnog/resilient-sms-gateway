<?php

declare(strict_types=1);

namespace SmsGateway;

/**
 * Outcome of sending one message, including every provider that was tried.
 */
final class SendResult
{
    /**
     * @param list<Attempt> $attempts
     */
    public function __construct(
        public readonly bool $delivered,
        public readonly ?string $provider,
        public readonly ?string $providerMessageId,
        public readonly array $attempts,
    ) {
    }

    public function failed(): bool
    {
        return !$this->delivered;
    }

    public function lastError(): ?string
    {
        $key = array_key_last($this->attempts);

        return $key === null ? null : $this->attempts[$key]->error;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'delivered' => $this->delivered,
            'provider' => $this->provider,
            'provider_message_id' => $this->providerMessageId,
            'attempts' => array_map(static fn (Attempt $a) => $a->toArray(), $this->attempts),
        ];
    }
}
