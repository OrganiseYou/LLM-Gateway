<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Internal\Rules;

describe('the shared rules', function (): void {
    it('are one final internal class with static methods only, without state', function (): void {
        $reflection = new ReflectionClass(Rules::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->getProperties())->toBe([])
            ->and((string) $reflection->getDocComment())->toContain('@internal');
        foreach ($reflection->getMethods() as $method) {
            expect($method->isStatic())->toBeTrue($method->getName() . ' must be static');
        }
    });

    it('know a trace id', function (string $traceId, bool $valid): void {
        expect(Rules::isTraceId($traceId))->toBe($valid);
    })->with([
        ['trace-1_a.b:c', true], [str_repeat('a', 128), true], ['', false], [str_repeat('a', 129), false], ['trace 1', false], ["trace-1\n", false],
    ]);

    it('know a model name', function (string $name, bool $valid): void {
        expect(Rules::isModelName($name))->toBe($valid);
    })->with([
        ['org/model:1.5', true], ["mod\u{00E8}le", true], ['', false], ["model\n", false], ["model\0", false], ["model\xFF", false],
    ]);

    it('keep a provider code only when it is a plain word, lower-cased', function (?string $code, ?string $kept): void {
        expect(Rules::plainProviderCode($code))->toBe($kept);
    })->with([
        [null, null], ['Model_Not.Found-1', 'model_not.found-1'], ['', null], ['two words', null], [str_repeat('a', 65), null], ["code\n", null],
    ]);

    it('know a timeout', function (float $seconds, bool $valid): void {
        expect(Rules::isTimeout($seconds))->toBe($valid);
    })->with([[0.001, true], [30.0, true], [0.0, false], [-1.0, false], [INF, false], [NAN, false]]);

    it('know an HTTP token', function (string $name, bool $valid): void {
        expect(Rules::isHttpToken($name))->toBe($valid);
    })->with([['X-Api-Key', true], ["a!#$%&'*+.^_`|~1", true], ['', false], ['X Api', false], ['X:Api', false], ["X-Api\n", false]]);
});
