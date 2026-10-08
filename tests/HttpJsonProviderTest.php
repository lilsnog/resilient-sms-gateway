<?php

declare(strict_types=1);

namespace SmsGateway\Tests;

use PHPUnit\Framework\TestCase;
use SmsGateway\Exceptions\ProviderException;
use SmsGateway\Message;
use SmsGateway\Providers\HttpJsonProvider;

final class HttpJsonProviderTest extends TestCase
{
    public function testMapsFieldsAndReadsNestedId(): void
    {
        $captured = [];
        $provider = new HttpJsonProvider('acme', [
            'url' => 'https://sms.example/send',
            'headers' => ['Authorization' => 'Bearer test'],
            'fields' => ['to' => 'recipient', 'body' => 'text', 'sender' => 'from'],
            'static' => ['channel' => 'generic'],
            'id_path' => 'data.message_id',
        ], function (string $url, array $headers, string $body) use (&$captured): array {
            $captured = compact('url', 'headers', 'body');

            return [200, '{"data":{"message_id":"abc-123"}}'];
        });

        $id = $provider->send(new Message('+2348012345678', 'Hello', 'MyBrand'));

        $this->assertSame('abc-123', $id);
        $this->assertSame('https://sms.example/send', $captured['url']);
        $this->assertSame('Bearer test', $captured['headers']['Authorization']);
        $this->assertSame(
            ['channel' => 'generic', 'recipient' => '+2348012345678', 'text' => 'Hello', 'from' => 'MyBrand'],
            json_decode($captured['body'], true),
        );
    }

    public function testServerErrorsAreRetryable(): void
    {
        $provider = $this->providerReturning(503, 'Service Unavailable');

        try {
            $provider->send(new Message('+2348012345678', 'Hello'));
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function testRateLimitIsRetryable(): void
    {
        try {
            $this->providerReturning(429, 'slow down')->send(new Message('+2348012345678', 'Hello'));
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function testClientErrorsArePermanent(): void
    {
        try {
            $this->providerReturning(400, '{"error":"invalid number"}')->send(new Message('+2348012345678', 'Hello'));
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertFalse($e->retryable);
        }
    }

    public function testMissingIdIsAnError(): void
    {
        $this->expectException(ProviderException::class);

        $this->providerReturning(200, '{"status":"ok"}')->send(new Message('+2348012345678', 'Hello'));
    }

    private function providerReturning(int $status, string $body): HttpJsonProvider
    {
        return new HttpJsonProvider('acme', ['url' => 'https://sms.example/send'], fn () => [$status, $body]);
    }
}
