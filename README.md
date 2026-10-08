# Resilient SMS Gateway

![tests](https://github.com/lilsnog/resilient-sms-gateway/actions/workflows/tests.yml/badge.svg)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4)
![Laravel](https://img.shields.io/badge/Laravel-ready-FF2D20)

A small PHP library (with a drop-in Laravel integration) for sending SMS through **several aggregators at once**, so one provider's outage doesn't stop your OTPs and transaction alerts.

- **Prefix routing**: choose the provider order per destination (for example `+234803` → carrier-direct first, other `+234` → aggregator A, everything else → aggregator B).
- **Automatic failover**: if a provider times out or returns 5xx/429, the next provider on the route is tried immediately.
- **Per-provider circuit breaker**: after N failures in a rolling window, the provider is skipped for a cool-down period. Then a single trial send decides whether it's healthy again. This stops every request paying a 5-second timeout during an outage.
- **Permanent vs temporary errors**: a 4xx such as "invalid number" stops the chain, because retrying elsewhere won't help and it says nothing about provider health.
- **Config-driven HTTP adapter**: most aggregators are "POST JSON, get an ID back", so you map field names in config instead of writing a class per vendor.
- **Audit trail**: every send returns (and emits to listeners) the full list of attempts, with status and latency per provider.
- **Queued sends in Laravel**: if *every* provider fails, the job backs off and retries rather than dropping the message.

## How it works

```
                 ┌──────────────┐
 Message ──────► │    Router    │  longest-prefix match → [primary, backup, ...]
                 └──────┬───────┘
                        ▼
          ┌──────────────────────────┐   circuit open?  ──► skip, record attempt
          │  for each provider:      │
          │   CircuitBreaker.allow() │   send ok        ──► record success, return
          │   provider.send()        │   retryable fail ──► record failure, next provider
          └──────────────────────────┘   permanent fail ──► stop, return failure
```

Circuit states per provider:

```
CLOSED --(N failures in window)--> OPEN --(cool-down elapsed)--> HALF_OPEN
   ^                                  ^                               |
   |                                  +-------(trial fails)-----------+
   +-------------------------------(trial succeeds)-------------------+
```

## Quick start (plain PHP)

```php
use SmsGateway\Gateway;
use SmsGateway\Message;
use SmsGateway\Providers\HttpJsonProvider;
use SmsGateway\Resilience\CircuitBreaker;
use SmsGateway\Resilience\InMemoryStateStore;
use SmsGateway\Resilience\SystemClock;
use SmsGateway\Routing\Router;

$primary = new HttpJsonProvider('primary', [
    'url'     => 'https://api.primary-sms.example/v1/send',
    'headers' => ['Authorization' => 'Bearer ' . getenv('PRIMARY_KEY')],
    'fields'  => ['to' => 'to', 'body' => 'sms', 'sender' => 'from'],
    'id_path' => 'message_id',
]);

$backup = new HttpJsonProvider('backup', [
    'url'     => 'https://api.backup-sms.example/messages',
    'headers' => ['X-Api-Key' => getenv('BACKUP_KEY')],
    'fields'  => ['to' => 'recipient', 'body' => 'text'],
    'id_path' => 'data.id',
]);

$router = (new Router(default: ['primary', 'backup']))
    ->route('+234', ['backup', 'primary']);

$gateway = new Gateway(
    [$primary, $backup],
    $router,
    new CircuitBreaker(new InMemoryStateStore(), new SystemClock(), failureThreshold: 5, coolDownSeconds: 30),
);

$result = $gateway->send(new Message('+2348012345678', 'Your one-time code is 482913', senderId: 'MyApp'));

$result->delivered;          // true
$result->provider;           // "backup"
$result->toArray();          // full attempt log for auditing
```

## Laravel

Add the repository to your app's `composer.json`, then require it:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/lilsnog/resilient-sms-gateway" }]
```

```bash
composer require godswill/resilient-sms-gateway:dev-main
php artisan vendor:publish --tag=sms-gateway-config
```

Configure providers, routes and circuit settings in `config/sms-gateway.php`. Then:

```php
use SmsGateway\Laravel\SendSms;

SendSms::dispatch('+2348012345678', 'Your one-time code is 482913', 'MyApp', reference: 'otp:'.$user->id);
```

Circuit state is kept in Laravel's cache. Use Redis so every queue worker shares the same view of which providers are down. Every send is logged with the recipient masked, segment count and attempt trail.

## Demo

```bash
composer install
php examples/failover-demo.php
```

```
== primary starts timing out ==
backup     primary:failed -> backup:sent
backup     primary:failed -> backup:sent
backup     primary:failed -> backup:sent
backup     primary:skipped_circuit_open -> backup:sent
backup     primary:skipped_circuit_open -> backup:sent
backup     primary:skipped_circuit_open -> backup:sent

== 31 seconds later: one trial send to primary ==
primary    primary:sent
primary    primary:sent
```

After three failures the primary is skipped entirely, so no more waiting on timeouts. Once the cool-down passes, one trial succeeds and traffic returns to it.

## Tests

```bash
composer test
```

The tests cover circuit transitions (window expiry, half-open trials, re-opening), failover order, permanent-error short-circuiting, longest-prefix routing, E.164 normalisation, GSM-7/UCS-2 segment counting and the HTTP adapter's status-code handling. The HTTP transport and clock are injected, so tests never touch the network or sleep.

## Project layout

```
src/
  Gateway.php                 orchestrates routing, breaker and failover
  Message.php                 validated, normalised outbound SMS
  SendResult.php, Attempt.php result + per-provider audit trail
  Routing/Router.php          longest-prefix provider selection
  Resilience/CircuitBreaker   closed / open / half-open state machine
  Providers/HttpJsonProvider  config-driven adapter for JSON SMS APIs
  Providers/FakeProvider      scriptable provider for tests and demos
  Laravel/                    service provider, cache-backed state, queued job
```

## License

MIT
