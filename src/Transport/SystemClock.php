<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

use OrganiseYou\LlmGateway\Contract\InvalidValue;

/**
 * The real time of the machine.
 */
final readonly class SystemClock implements Clock
{
    public function monotonic(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function unixTime(): int
    {
        return time();
    }

    public function sleep(float $seconds): void
    {
        if (! is_finite($seconds) || $seconds < 0.0) {
            throw new InvalidValue('The seconds to sleep must be a finite number of 0 or more.');
        }

        $whole = (int) floor($seconds);
        if ($whole > 0) {
            \sleep($whole);
        }

        $rest = (int) round(($seconds - $whole) * 1_000_000);
        if ($rest > 0) {
            usleep($rest);
        }
    }
}
