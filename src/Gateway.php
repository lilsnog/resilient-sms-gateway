<?php

declare(strict_types=1);

namespace SmsGateway;

use InvalidArgumentException;
use SmsGateway\Contracts\Clock;
use SmsGateway\Contracts\SmsProvider;
use SmsGateway\Exceptions\ProviderException;
use SmsGateway\Resilience\CircuitBreaker;
use SmsGateway\Resilience\SystemClock;
use SmsGateway\Routing\Router;
use Throwable;

/**
 * Sends a message through the first healthy provider on its route, failing
 * over to the next provider when one errors or its circuit is open.
 */
final class Gateway
{
    /** @var array<string, SmsProvider> */
    private array $providers = [];

    /** @var list<callable(Message, SendResult): void> */
    private array $listeners = [];

    /**
     * @param iterable<SmsProvider> $providers
     */
    public function __construct(
        iterable $providers,
        private readonly Router $router,
        private readonly CircuitBreaker $breaker,
        private readonly Clock $clock = new SystemClock(),
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->name()] = $provider;
        }
    }

    /**
     * Register a callback that runs after every send (logging, metrics, persistence).
     *
     * @param callable(Message, SendResult): void $listener
     */
    public function onSent(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function send(Message $message): SendResult
    {
        $attempts = [];

        foreach ($this->router->providersFor($message) as $name) {
            $provider = $this->providers[$name]
                ?? throw new InvalidArgumentException("Route refers to unknown provider '{$name}'.");

            if (!$this->breaker->allowRequest($name)) {
                $attempts[] = new Attempt($name, Attempt::SKIPPED_CIRCUIT_OPEN, 0.0, 'Circuit open');
                continue;
            }

            $started = $this->clock->now();

            try {
                $id = $provider->send($message);
                $this->breaker->recordSuccess($name);
                $attempts[] = new Attempt($name, Attempt::SENT, $this->elapsed($started));

                return $this->finish($message, new SendResult(true, $name, $id, $attempts));
            } catch (ProviderException $e) {
                $attempts[] = new Attempt($name, Attempt::FAILED, $this->elapsed($started), $e->getMessage());

                if (!$e->retryable) {
                    // The message itself was rejected; another provider won't help,
                    // and it says nothing about this provider's health.
                    break;
                }

                $this->breaker->recordFailure($name);
            } catch (Throwable $e) {
                $attempts[] = new Attempt($name, Attempt::FAILED, $this->elapsed($started), $e->getMessage());
                $this->breaker->recordFailure($name);
            }
        }

        return $this->finish($message, new SendResult(false, null, null, $attempts));
    }

    private function finish(Message $message, SendResult $result): SendResult
    {
        foreach ($this->listeners as $listener) {
            $listener($message, $result);
        }

        return $result;
    }

    private function elapsed(float $started): float
    {
        return ($this->clock->now() - $started) * 1000;
    }
}
