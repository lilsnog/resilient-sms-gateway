<?php

declare(strict_types=1);

namespace SmsGateway;

/**
 * A single try against a single provider.
 */
final class Attempt
{
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const SKIPPED_CIRCUIT_OPEN = 'skipped_circuit_open';

    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly float $durationMs,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'status' => $this->status,
            'duration_ms' => round($this->durationMs, 2),
            'error' => $this->error,
        ];
    }
}
