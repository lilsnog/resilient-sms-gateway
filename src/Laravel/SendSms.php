<?php

declare(strict_types=1);

namespace SmsGateway\Laravel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use SmsGateway\Gateway;
use SmsGateway\Message;

/**
 * Queued send. If every provider on the route fails, the job is released back
 * to the queue with a growing delay, so a short outage across all providers
 * doesn't lose messages.
 */
final class SendSms implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> seconds between retries */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly string $to,
        public readonly string $body,
        public readonly ?string $senderId = null,
        public readonly ?string $reference = null,
    ) {
    }

    public function handle(Gateway $gateway): void
    {
        $result = $gateway->send(new Message($this->to, $this->body, $this->senderId, $this->reference));

        if ($result->failed()) {
            throw new RuntimeException('All SMS providers failed: ' . $result->lastError());
        }
    }
}
