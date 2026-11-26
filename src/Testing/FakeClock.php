<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Transport\Clock;

/**
 * A clock for tests. It never waits: a sleep adds the seconds to its own time and is recorded.
 */
final class FakeClock implements Clock
{
    private float $elapsed = 0.0;

    /** @var list<float> */
    private array $sleeps = [];

    /**
     * @param float $monotonicStart what monotonic() gives before any time has passed
     * @param int $unixStart what unixTime() gives before any time has passed
     */
    public function __construct(
        private readonly float $monotonicStart = 1000.0,
        private readonly int $unixStart = 1_800_000_000,
    ) {
        if (! is_finite($monotonicStart)) {
            throw new InvalidArgumentException('The start of the monotonic time must be a finite number.');
        }
    }

    public function monotonic(): float
    {
        return $this->monotonicStart + $this->elapsed;
    }

    public function unixTime(): int
    {
        return $this->unixStart + (int) floor($this->elapsed);
    }

    public function sleep(float $seconds): void
    {
        $this->advance($seconds);
        $this->sleeps[] = $seconds;
    }

    /**
     * Lets time pass without a sleep: the time a request took, for example.
     */
    public function advance(float $seconds): void
    {
        if (! is_finite($seconds) || $seconds < 0.0) {
            throw new InvalidArgumentException('The seconds must be a finite number of 0 or more.');
        }

        $this->elapsed += $seconds;
    }

    /**
     * Every sleep asked of this clock, in order.
     *
     * @return list<float>
     */
    public function sleeps(): array
    {
        return $this->sleeps;
    }
}
