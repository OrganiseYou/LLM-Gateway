<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\RetryPolicy;

describe('RetryPolicy', function (): void {
    it('keeps the waits as floats and the longest wait accepted from the provider', function (): void {
        $policy = new RetryPolicy([0, 1, 2.5], 30.0);

        expect($policy->waitsSeconds)->toBe([0.0, 1.0, 2.5])
            ->and($policy->maxProviderWaitSeconds)->toBe(30.0);
    });

    it('gives no retries and accepts no wait with none()', function (): void {
        $policy = RetryPolicy::none();

        expect($policy->waitsSeconds)->toBe([])
            ->and($policy->maxProviderWaitSeconds)->toBe(0.0);
    });

    it('has no default policy: both values are required', function (): void {
        $parameters = (new ReflectionClass(RetryPolicy::class))->getConstructor()?->getParameters() ?? [];

        expect($parameters)->toHaveCount(2);
        foreach ($parameters as $parameter) {
            expect($parameter->isOptional())->toBeFalse();
        }
    });

    it('refuses waits that are not a list', function (): void {
        expect(fn() => new RetryPolicy(untyped(['first' => 1.0]), 1.0))
            ->toThrow(InvalidArgumentException::class, 'The waits must be a list.');
    });

    it('refuses a wait that is not a finite number of 0 or more', function (mixed $wait): void {
        expect(fn() => new RetryPolicy(untyped([1.0, $wait]), 1.0))
            ->toThrow(InvalidArgumentException::class, 'A wait must be a finite number of seconds of 0 or more.');
    })->with([
        'below 0' => [-0.1],
        'infinite' => [INF],
        'not a number' => [NAN],
        'a string' => ['1'],
        'null' => [null],
    ]);

    it('refuses a longest provider wait that is not finite or is below 0', function (float $longest): void {
        expect(fn() => new RetryPolicy([], $longest))
            ->toThrow(InvalidArgumentException::class, 'The longest wait accepted from the provider');
    })->with([
        'below 0' => [-1.0],
        'infinite' => [INF],
        'not a number' => [NAN],
    ]);
});
