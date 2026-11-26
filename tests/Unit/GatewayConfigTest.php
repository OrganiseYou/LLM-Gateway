<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Tests\Support\RecordingObserver;
use OrganiseYou\LlmGateway\Transport\NullAttemptObserver;
use OrganiseYou\LlmGateway\Transport\SystemClock;

describe('GatewayConfig', function (): void {
    it('requires a retry policy and nothing else', function (): void {
        $parameters = (new ReflectionClass(GatewayConfig::class))->getConstructor()?->getParameters() ?? [];
        $required = array_map(
            fn(ReflectionParameter $parameter): string => $parameter->getName(),
            array_values(array_filter($parameters, fn(ReflectionParameter $parameter): bool => ! $parameter->isOptional())),
        );

        expect($required)->toBe(['retry']);
    });

    it('has no base URL, no settings, an observer that does nothing and the real clock by default', function (): void {
        $policy = RetryPolicy::none();
        $config = new GatewayConfig($policy);

        expect($config->retry)->toBe($policy)
            ->and($config->baseUrl)->toBeNull()
            ->and($config->settings)->toBe([])
            ->and($config->observer)->toBeInstanceOf(NullAttemptObserver::class)
            ->and($config->clock)->toBeInstanceOf(SystemClock::class);
    });

    it('holds what the host gives', function (): void {
        $observer = new RecordingObserver();
        $clock = new FakeClock();
        $config = new GatewayConfig(RetryPolicy::none(), 'http://localhost:1234/v1', ['region' => 'eu'], $observer, $clock);

        expect($config->baseUrl)->toBe('http://localhost:1234/v1')
            ->and($config->settings)->toBe(['region' => 'eu'])
            ->and($config->observer)->toBe($observer)
            ->and($config->clock)->toBe($clock);
    });

    it('refuses a base URL that is not an absolute http or https URL with a host, or holds user info, a query or a fragment', function (string $url): void {
        expect(fn() => new GatewayConfig(RetryPolicy::none(), $url))
            ->toThrow(InvalidArgumentException::class, 'The base URL must be an absolute http or https URL with a host');
    })->with([
        'relative' => ['/v1'],
        'another scheme' => ['file:///etc/hosts'],
        'no host' => ['https:///v1'],
        'user info' => ['https://user:word@api.example.test/v1'],
        'a query' => ['https://api.example.test/v1?version=1'],
        'an empty query' => ['https://api.example.test/v1?'],
        'a fragment' => ['https://api.example.test/v1#top'],
        'a backslash' => ['https://api.example.test\\@other.example.test'],
        'a line break at the end' => ["https://api.example.test/v1\n"],
    ]);

    it('refuses settings whose key or value is not a string', function (mixed $settings): void {
        expect(fn() => new GatewayConfig(RetryPolicy::none(), null, untyped($settings)))
            ->toThrow(InvalidArgumentException::class, 'The settings must be strings by string keys.');
    })->with([
        'a list' => [['eu']],
        'a number as value' => [['version' => 2]],
        'null as value' => [['region' => null]],
    ]);

    it('keeps a refused base URL out of the refusal', function (): void {
        withExceptionIgnoreArgs('0', function (): void {
            try {
                new GatewayConfig(RetryPolicy::none(), 'https://user:' . LEAK_MARKER . '@api.example.test');
                $refusal = null;
            } catch (InvalidArgumentException $thrown) {
                $refusal = $thrown;
            }

            expect($refusal)->not->toBeNull();
            expectNoMarker(exceptionForms($refusal ?? new LogicException('not refused')));
        });
    });
});
