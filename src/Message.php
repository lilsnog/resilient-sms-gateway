<?php

declare(strict_types=1);

namespace SmsGateway;

use InvalidArgumentException;

/**
 * An outbound SMS. Immutable once created.
 */
final class Message
{
    public readonly string $to;

    /**
     * @param string               $to       Recipient in E.164 format, e.g. +2348012345678
     * @param string               $body     Message text
     * @param string|null          $senderId Alphanumeric sender ID, if the provider supports one
     * @param string|null          $reference Your own idempotency / correlation key
     * @param array<string, mixed> $meta     Free-form metadata passed through to providers and reports
     */
    public function __construct(
        string $to,
        public readonly string $body,
        public readonly ?string $senderId = null,
        public readonly ?string $reference = null,
        public readonly array $meta = [],
    ) {
        $normalised = self::normalise($to);

        if (!preg_match('/^\+[1-9]\d{7,14}$/', $normalised)) {
            throw new InvalidArgumentException("Recipient '{$to}' is not a valid E.164 phone number.");
        }

        if (trim($body) === '') {
            throw new InvalidArgumentException('Message body cannot be empty.');
        }

        $this->to = $normalised;
    }

    /**
     * Number of SMS segments this message will use (GSM-7 vs UCS-2).
     */
    public function segments(): int
    {
        $isGsm = (bool) preg_match('/^[\x20-\x7E\r\n£¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉÄÖÑÜ§¿äöñüà]*$/u', $this->body);
        $length = mb_strlen($this->body);

        if ($isGsm) {
            return $length <= 160 ? 1 : (int) ceil($length / 153);
        }

        return $length <= 70 ? 1 : (int) ceil($length / 67);
    }

    private static function normalise(string $to): string
    {
        $digits = preg_replace('/[\s\-()]/', '', $to) ?? '';

        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }

        return $digits;
    }
}
