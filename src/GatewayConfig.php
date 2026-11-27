<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Transport\AttemptObserver;
use OrganiseYou\LlmGateway\Transport\Clock;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\NullAttemptObserver;
use OrganiseYou\LlmGateway\Transport\SystemClock;
use SensitiveParameter;

/**
 * What the host gives beside a preset, credentials and a transport. The retry policy has no default.
 */
final readonly class GatewayConfig
{
    /**
     * @param string|null $baseUrl the customer's own address: absolute, http or https, without user info, query
     *                             or fragment
     * @param array<string, string> $settings the values for the fields of the preset, by field key
     * @param AttemptObserver $observer told before and after every HTTP attempt
     * @param Clock $clock the time and the waits; a test gives a clock that does not wait
     */
    public function __construct(
        public RetryPolicy $retry,
        #[SensitiveParameter]
        public ?string $baseUrl = null,
        public array $settings = [],
        public AttemptObserver $observer = new NullAttemptObserver(),
        public Clock $clock = new SystemClock(),
    ) {
        if ($baseUrl !== null) {
            self::assertBaseUrl($baseUrl);
        }
        self::assertSettings($settings);
    }

    /**
     * @param array<mixed> $settings
     */
    private static function assertSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new InvalidValue('The settings must be strings by string keys.');
            }
        }
    }

    private static function assertBaseUrl(#[SensitiveParameter] string $url): void
    {
        $valid = ! str_contains($url, '?');
        if ($valid) {
            try {
                new HttpRequest('GET', $url, [], [], 1.0);
            } catch (InvalidArgumentException) {
                $valid = false;
            }
        }
        if (! $valid) {
            throw new InvalidValue(
                'The base URL must be an absolute http or https URL with a host, without user info, query or fragment.',
            );
        }
    }
}
