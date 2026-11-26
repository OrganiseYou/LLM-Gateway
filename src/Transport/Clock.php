<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

/**
 * The time, given to the library so that it holds none itself and tests need not wait.
 */
interface Clock
{
    /**
     * Seconds on a clock that never goes back. Only the difference between two readings means something.
     */
    public function monotonic(): float;

    /**
     * Seconds since 1970, for a wait that is given as a date.
     */
    public function unixTime(): int;

    /**
     * Waits this long. 0 returns at once.
     */
    public function sleep(float $seconds): void;
}
