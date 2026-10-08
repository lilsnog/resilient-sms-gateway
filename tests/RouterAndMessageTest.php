<?php

declare(strict_types=1);

namespace SmsGateway\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmsGateway\Exceptions\NoRouteException;
use SmsGateway\Message;
use SmsGateway\Routing\Router;

final class RouterAndMessageTest extends TestCase
{
    public function testLongestPrefixWins(): void
    {
        $router = (new Router(['global']))
            ->route('+234', ['ng-a', 'ng-b'])
            ->route('+234803', ['carrier-direct', 'ng-a']);

        $this->assertSame(['carrier-direct', 'ng-a'], $router->providersFor(new Message('+2348031234567', 'hi')));
        $this->assertSame(['ng-a', 'ng-b'], $router->providersFor(new Message('+2349031234567', 'hi')));
        $this->assertSame(['global'], $router->providersFor(new Message('+447700900123', 'hi')));
    }

    public function testThrowsWhenNothingRoutes(): void
    {
        $this->expectException(NoRouteException::class);

        (new Router())->providersFor(new Message('+447700900123', 'hi'));
    }

    public function testNormalisesInternationalPrefixAndSpacing(): void
    {
        $this->assertSame('+2348012345678', (new Message('00234 801-234-5678', 'hi'))->to);
    }

    public function testRejectsInvalidNumbers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Message('08012345678', 'hi');
    }

    public function testRejectsEmptyBody(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Message('+2348012345678', '   ');
    }

    public function testCountsSegments(): void
    {
        $this->assertSame(1, (new Message('+2348012345678', str_repeat('a', 160)))->segments());
        $this->assertSame(2, (new Message('+2348012345678', str_repeat('a', 161)))->segments());
        $this->assertSame(1, (new Message('+2348012345678', str_repeat('ñ', 71)))->segments(), 'ñ is in the GSM-7 alphabet');
        $this->assertSame(2, (new Message('+2348012345678', str_repeat('₦', 71)))->segments());
    }
}
