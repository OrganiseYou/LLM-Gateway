<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use InvalidArgumentException;

/**
 * How a failed attempt is tried again. The caller gives the waits; the library has no default and adds no jitter.
 */
final readonly class RetryPolicy
{
    /** @var list<float> the wait before retry 1, 2, …; its length is the number of retries */
    public array $waitsSeconds;

    /**
     * @param list<float|int> $waitsSeconds the wait before retry 1, 2, …; its length is the number of retries
     * @param float $maxProviderWaitSeconds the longest wait accepted from the provider
     */
    public function __construct(
        array $waitsSeconds,
        public float $maxProviderWaitSeconds,
    ) {
        $waits = self::waits($waitsSeconds);

        if (! is_finite($maxProviderWaitSeconds) || $maxProviderWaitSeconds < 0.0) {
            throw new InvalidArgumentException(
                'The longest wait accepted from the provider must be a finite number of seconds of 0 or more.',
            );
        }

        $this->waitsSeconds = $waits;
    }

    /**
     * @param array<mixed> $waitsSeconds
     * @return list<float>
     */
    private static function waits(array $waitsSeconds): array
    {
        if (! array_is_list($waitsSeconds)) {
            throw new InvalidArgumentException('The waits must be a list.');
        }

        $waits = [];
        foreach ($waitsSeconds as $wait) {
            if ((! is_int($wait) && ! is_float($wait)) || ! is_finite((float) $wait) || $wait < 0) {
                throw new InvalidArgumentException('A wait must be a finite number of seconds of 0 or more.');
            }
            $waits[] = (float) $wait;
        }

        return $waits;
    }

    /**
     * No retries, and no wait accepted from the provider.
     */
    public static function none(): self
    {
        return new self([], 0.0);
    }
}
