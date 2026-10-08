<?php

declare(strict_types=1);

namespace SmsGateway\Providers;

use SmsGateway\Contracts\SmsProvider;
use SmsGateway\Exceptions\ProviderException;
use SmsGateway\Message;

/**
 * Scriptable provider for tests and local development.
 *
 *   $p = new FakeProvider('primary');
 *   $p->failNext(3);          // next three sends throw a retryable error
 *   $p->rejectNext();         // next send throws a permanent error
 */
final class FakeProvider implements SmsProvider
{
    /** @var list<Message> */
    public array $sent = [];

    private int $failuresQueued = 0;
    private bool $rejectQueued = false;
    private int $counter = 0;

    public function __construct(private readonly string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function failNext(int $times = 1): self
    {
        $this->failuresQueued += $times;

        return $this;
    }

    public function rejectNext(): self
    {
        $this->rejectQueued = true;

        return $this;
    }

    public function send(Message $message): string
    {
        if ($this->rejectQueued) {
            $this->rejectQueued = false;

            throw ProviderException::permanent("{$this->name}: recipient rejected");
        }

        if ($this->failuresQueued > 0) {
            $this->failuresQueued--;

            throw new ProviderException("{$this->name}: upstream timeout");
        }

        $this->sent[] = $message;

        return sprintf('%s-%04d', $this->name, ++$this->counter);
    }
}
