<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Transport\Clock;
use OrganiseYou\LlmGateway\Transport\SystemClock;

describe('SystemClock', function (): void {
    it('is a final clock', function (): void {
        $reflection = new ReflectionClass(SystemClock::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->implementsInterface(Clock::class))->toBeTrue();
    });

    it('gives a monotonic time in seconds that does not go back', function (): void {
        $clock = new SystemClock();
        $first = $clock->monotonic();
        $second = $clock->monotonic();

        expect($second)->toBeGreaterThanOrEqual($first)
            ->and($first)->toEqualWithDelta(hrtime(true) / 1_000_000_000, 5.0);
    });

    it('gives the unix time', function (): void {
        expect((new SystemClock())->unixTime())->toEqualWithDelta(time(), 2);
    });

    it('sleeps for the seconds asked, also a part of a second', function (): void {
        $clock = new SystemClock();
        $before = $clock->monotonic();

        $clock->sleep(0.02);

        expect($clock->monotonic() - $before)->toBeGreaterThanOrEqual(0.019)->toBeLessThan(2.0);
    });

    it('returns at once for a sleep of 0', function (): void {
        $clock = new SystemClock();
        $before = $clock->monotonic();

        $clock->sleep(0.0);

        expect($clock->monotonic() - $before)->toBeLessThan(0.5);
    });

    it('throws when the seconds to sleep are below 0 or not finite', function (float $seconds): void {
        (new SystemClock())->sleep($seconds);
    })->with([
        'negative' => [-0.1],
        'infinite' => [INF],
        'not a number' => [NAN],
    ])->throws(InvalidArgumentException::class, 'The seconds to sleep must be a finite number of 0 or more.');
});

describe('Clock', function (): void {
    it('has a monotonic time, a unix time and a sleep', function (): void {
        $methods = [];
        foreach ((new ReflectionClass(Clock::class))->getMethods() as $method) {
            $methods[$method->getName()] = (string) $method->getReturnType();
        }

        expect($methods)->toBe(['monotonic' => 'float', 'unixTime' => 'int', 'sleep' => 'void']);
    });
});
