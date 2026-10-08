<?php

declare(strict_types=1);

namespace SmsGateway\Laravel;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use SmsGateway\Gateway;
use SmsGateway\Message;
use SmsGateway\Providers\HttpJsonProvider;
use SmsGateway\Resilience\CircuitBreaker;
use SmsGateway\Resilience\SystemClock;
use SmsGateway\Routing\Router;
use SmsGateway\SendResult;

/**
 * Wires the gateway into a Laravel app from config/sms-gateway.php.
 *
 *   php artisan vendor:publish --tag=sms-gateway-config
 *
 *   app(Gateway::class)->send(new Message('+2348012345678', 'Your OTP is 123456'));
 *   SendSms::dispatch('+2348012345678', 'Your OTP is 123456');   // queued
 */
final class SmsGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/sms-gateway.php', 'sms-gateway');

        $this->app->singleton(Gateway::class, function ($app): Gateway {
            $config = $app['config']->get('sms-gateway');

            $providers = [];
            foreach ($config['providers'] as $name => $providerConfig) {
                $providers[] = new HttpJsonProvider($name, $providerConfig);
            }

            $router = new Router($config['default_route']);
            foreach ($config['routes'] as $prefix => $route) {
                $router->route((string) $prefix, $route);
            }

            $breaker = new CircuitBreaker(
                new CacheStateStore($app->make(Cache::class), $config['circuit']['state_ttl']),
                new SystemClock(),
                $config['circuit']['failure_threshold'],
                $config['circuit']['window_seconds'],
                $config['circuit']['cool_down_seconds'],
            );

            $gateway = new Gateway($providers, $router, $breaker);

            $gateway->onSent(static function (Message $message, SendResult $result): void {
                Log::channel(config('sms-gateway.log_channel'))->info('sms.sent', [
                    'to' => substr($message->to, 0, -4) . '****',
                    'reference' => $message->reference,
                    'segments' => $message->segments(),
                ] + $result->toArray());
            });

            return $gateway;
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/sms-gateway.php' => config_path('sms-gateway.php'),
        ], 'sms-gateway-config');
    }
}
