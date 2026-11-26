<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Transport\Clock;
use OrganiseYou\LlmGateway\Transport\SystemClock;

describe('FakeClock', function (): void {
    it('is a clock', function (): void {
        expect(new FakeClock())->toBeInstanceOf(Clock::class);
    });

    it('starts at the times it is given and stands still', function (): void {
        $clock = new FakeClock(50.0, 1_900_000_000);

        expect($clock->monotonic())->toBe(50.0)
            ->and($clock->monotonic())->toBe(50.0)
            ->and($clock->unixTime())->toBe(1_900_000_000)
            ->and($clock->sleeps())->toBe([]);
    });

    it('starts at a fixed time of its own, not at the time of the machine', function (): void {
        expect((new FakeClock())->monotonic())->toBe((new FakeClock())->monotonic())
            ->and((new FakeClock())->unixTime())->toBe(1_800_000_000);
    });

    it('adds a sleep to its time and records it, without waiting', function (): void {
        $clock = new FakeClock(0.0, 1_900_000_000);
        $real = new SystemClock();
        $before = $real->monotonic();

        $clock->sleep(3600.0);
        $clock->sleep(0.5);
        $clock->sleep(0.0);

        expect($clock->monotonic())->toBe(3600.5)
            ->and($clock->unixTime())->toBe(1_900_003_600)
            ->and($clock->sleeps())->toBe([3600.0, 0.5, 0.0])
            ->and($real->monotonic() - $before)->toBeLessThan(1.0);
    });

    it('moves forward without recording a sleep', function (): void {
        $clock = new FakeClock(10.0, 1_900_000_000);

        $clock->advance(2.5);

        expect($clock->monotonic())->toBe(12.5)
            ->and($clock->unixTime())->toBe(1_900_000_002)
            ->and($clock->sleeps())->toBe([]);
    });

    it('throws when the seconds to sleep are below 0 or not finite', function (float $seconds): void {
        (new FakeClock())->sleep($seconds);
    })->with([
        'negative' => [-0.1],
        'infinite' => [INF],
        'not a number' => [NAN],
    ])->throws(InvalidArgumentException::class, 'The seconds must be a finite number of 0 or more.');

    it('does not record a sleep it refused', function (): void {
        $clock = new FakeClock();

        try {
            $clock->sleep(-1.0);
        } catch (InvalidArgumentException) {
        }

        expect($clock->sleeps())->toBe([]);
    });

    it('throws when the seconds to move forward are below 0 or not finite', function (float $seconds): void {
        (new FakeClock())->advance($seconds);
    })->with([
        'negative' => [-0.1],
        'infinite' => [INF],
        'not a number' => [NAN],
    ])->throws(InvalidArgumentException::class, 'The seconds must be a finite number of 0 or more.');

    it('throws when the start of the monotonic time is not finite', function (float $start): void {
        new FakeClock($start);
    })->with([
        'infinite' => [INF],
        'not a number' => [NAN],
    ])->throws(InvalidArgumentException::class, 'The start of the monotonic time must be a finite number.');
});
