<?php

declare(strict_types=1);

namespace SmsGateway\Providers;

use SmsGateway\Contracts\SmsProvider;
use SmsGateway\Exceptions\ProviderException;
use SmsGateway\Message;

/**
 * Generic adapter for the many aggregators that expose a "POST JSON, get an ID
 * back" API. Configure the field names instead of writing a class per vendor.
 *
 *   new HttpJsonProvider('acme', [
 *       'url'          => 'https://api.acme-sms.example/v1/messages',
 *       'headers'      => ['Authorization' => 'Bearer ' . env('ACME_KEY')],
 *       'fields'       => ['to' => 'recipient', 'body' => 'text', 'sender' => 'from'],
 *       'static'       => ['channel' => 'generic'],
 *       'id_path'      => 'data.message_id',
 *       'timeout'      => 5,
 *   ]);
 *
 * HTTP 4xx (except 408/429) is treated as a permanent rejection of the message;
 * 5xx, 408, 429 and network errors are retryable and trip the circuit breaker.
 */
final class HttpJsonProvider implements SmsProvider
{
    /** @var callable(string, array<string, string>, string, int): array{0: int, 1: string} */
    private $transport;

    /**
     * @param array{
     *     url: string,
     *     headers?: array<string, string>,
     *     fields?: array{to?: string, body?: string, sender?: string, reference?: string},
     *     static?: array<string, mixed>,
     *     id_path?: string,
     *     timeout?: int
     * } $config
     * @param (callable(string, array<string, string>, string, int): array{0: int, 1: string})|null $transport
     *        Injected for tests; defaults to cURL.
     */
    public function __construct(
        private readonly string $name,
        private readonly array $config,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function send(Message $message): string
    {
        $fields = ($this->config['fields'] ?? []) + [
            'to' => 'to',
            'body' => 'message',
            'sender' => 'sender_id',
            'reference' => 'reference',
        ];

        $payload = $this->config['static'] ?? [];
        $payload[$fields['to']] = $message->to;
        $payload[$fields['body']] = $message->body;

        if ($message->senderId !== null) {
            $payload[$fields['sender']] = $message->senderId;
        }

        if ($message->reference !== null) {
            $payload[$fields['reference']] = $message->reference;
        }

        $headers = ($this->config['headers'] ?? []) + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        [$status, $body] = ($this->transport)(
            $this->config['url'],
            $headers,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $this->config['timeout'] ?? 5,
        );

        if ($status >= 400) {
            $retryable = $status >= 500 || in_array($status, [408, 429], true);
            $summary = mb_substr($body, 0, 200);

            throw new ProviderException("{$this->name} returned HTTP {$status}: {$summary}", $retryable);
        }

        $decoded = json_decode($body, true);
        $id = is_array($decoded) ? self::dig($decoded, $this->config['id_path'] ?? 'id') : null;

        if (!is_scalar($id) || (string) $id === '') {
            throw new ProviderException("{$this->name} accepted the request but returned no message ID.");
        }

        return (string) $id;
    }

    /**
     * @param array<mixed> $data
     */
    private static function dig(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: string}
     */
    private static function curlTransport(string $url, array $headers, string $body, int $timeout): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $k, string $v) => "{$k}: {$v}",
                array_keys($headers),
                $headers,
            ),
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new ProviderException("Network error: {$error}");
        }

        return [$status, (string) $response];
    }
}
