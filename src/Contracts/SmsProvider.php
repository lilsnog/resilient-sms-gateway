<?php

declare(strict_types=1);

namespace SmsGateway\Contracts;

use SmsGateway\Exceptions\ProviderException;
use SmsGateway\Message;

/**
 * One upstream SMS aggregator / carrier API.
 */
interface SmsProvider
{
    /**
     * Unique, stable name used in config, routing rules and reports.
     */
    public function name(): string;

    /**
     * Send the message and return the provider's message ID.
     *
     * @throws ProviderException when the provider rejects the message or is unreachable
     */
    public function send(Message $message): string;
}
