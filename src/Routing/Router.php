<?php

declare(strict_types=1);

namespace SmsGateway\Routing;

use SmsGateway\Exceptions\NoRouteException;
use SmsGateway\Message;

/**
 * Decides which providers to try, and in what order, for a message.
 *
 * Rules are matched by the longest phone-number prefix, so you can send
 * Nigerian MTN numbers (+234803, +234806 ...) through one aggregator and
 * every other Nigerian number through another, with a global default
 * for everything else.
 *
 *   $router = new Router(default: ['twilio']);
 *   $router->route('+234', ['termii', 'africastalking']);
 *   $router->route('+234803', ['mtn-direct', 'termii']);
 */
final class Router
{
    /** @var array<string, list<string>> prefix => ordered provider names */
    private array $rules = [];

    /**
     * @param list<string> $default
     */
    public function __construct(private readonly array $default = [])
    {
    }

    /**
     * @param list<string> $providers
     */
    public function route(string $prefix, array $providers): self
    {
        $this->rules[$prefix] = array_values($providers);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function providersFor(Message $message): array
    {
        $match = null;

        foreach ($this->rules as $prefix => $providers) {
            if (str_starts_with($message->to, $prefix) && ($match === null || strlen($prefix) > strlen($match))) {
                $match = $prefix;
            }
        }

        $providers = $match !== null ? $this->rules[$match] : $this->default;

        if ($providers === []) {
            throw new NoRouteException("No SMS provider is configured for {$message->to}.");
        }

        return $providers;
    }
}
