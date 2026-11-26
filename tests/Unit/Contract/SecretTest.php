<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Operation;
use OrganiseYou\LlmGateway\Contract\Secret;

describe('Secret', function (): void {
    it('is a final class that is not readonly, so the classes of a credential can hold one', function (): void {
        $reflection = new ReflectionClass(Secret::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeFalse()
            ->and($reflection->getConstructor()?->isPublic())->toBeTrue();
    });

    it('marks the value of its constructor as a sensitive parameter', function (): void {
        $parameter = (new ReflectionMethod(Secret::class, '__construct'))->getParameters()[0];

        expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    });

    it('gives the value back through reveal, every time', function (): void {
        $secret = new Secret(LEAK_MARKER);

        expect($secret->reveal())->toBe(LEAK_MARKER)
            ->and($secret->reveal())->toBe(LEAK_MARKER);
    });

    it('keeps a value with any bytes as it is', function (string $value): void {
        expect((new Secret($value))->reveal())->toBe($value);
    })->with([
        'one character' => ['k'],
        'spaces around' => [' key '],
        'not UTF-8' => ["key\xff\x00"],
        'long' => [str_repeat('k', 10000)],
    ]);

    it('throws when the value is empty', function (): void {
        new Secret('');
    })->throws(InvalidArgumentException::class, 'A secret must not be empty.');

    it('shows a mask as a string, as JSON and in its debug info', function (): void {
        $secret = new Secret(LEAK_MARKER);

        expect((string) $secret)->toBe('[secret]')
            ->and($secret->jsonSerialize())->toBe('[secret]')
            ->and(json_encode(['key' => $secret]))->toBe('{"key":"[secret]"}')
            ->and($secret->__debugInfo())->toBe(['value' => '[secret]']);
    });

    it('throws a logic exception when it is serialized', function (): void {
        serialize(new Secret(LEAK_MARKER));
    })->throws(LogicException::class, 'A secret cannot be serialized.');

    it('throws when it is unserialized, also from a written payload', function (): void {
        unserialize('O:38:"OrganiseYou\LlmGateway\Contract\Secret":0:{}');
    })->throws(LogicException::class, 'A secret cannot be unserialized.');

    it('can be cloned, and the copy reveals the same value', function (): void {
        $secret = new Secret(LEAK_MARKER);
        $copy = clone $secret;

        expect($copy)->not->toBe($secret)
            ->and($copy->reveal())->toBe(LEAK_MARKER)
            ->and($secret->reveal())->toBe(LEAK_MARKER);
    });

    it('does not show the value in any output form, an array cast or a read through reflection', function (): void {
        expectNoMarker(valueForms(new Secret(LEAK_MARKER)));
    });

    it('does not show the value in any output form of a copy or of a list that holds it', function (): void {
        $secret = new Secret(LEAK_MARKER);

        foreach ([clone $secret, ['Authorization' => $secret], (object) ['key' => $secret]] as $holder) {
            expectNoMarker(valueForms($holder));
        }
    });

    it('does not show the value after it was revealed', function (): void {
        $secret = new Secret(LEAK_MARKER);
        $secret->reveal();

        expectNoMarker(valueForms($secret));
    });

    it('holds the value in no property', function (): void {
        $secret = new Secret(LEAK_MARKER);

        foreach ((new ReflectionObject($secret))->getProperties() as $property) {
            expect($property->getValue($secret))->not->toBeString($property->getName() . ' holds a string');
        }
    });

    it('hides the argument of its constructor in the trace of its refusal', function (): void {
        withExceptionIgnoreArgs(false, function (): void {
            try {
                new Secret('');
            } catch (InvalidArgumentException $exception) {
                $frame = $exception->getTrace()[0];

                expect($frame['function'])->toBe('__construct')
                    ->and($frame['class'] ?? null)->toBe(Secret::class)
                    ->and($frame['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class);

                return;
            }

            throw new LogicException('The empty value was accepted.');
        });
    });

    it('does not show the value in the trace of an exception made while a secret is an argument', function (bool $ignoreArgs): void {
        withExceptionIgnoreArgs($ignoreArgs, function (): void {
            $fail = fn(Secret $key, array $headers): RuntimeException => new RuntimeException('failed');

            $secret = new Secret(LEAK_MARKER);
            $exception = $fail($secret, ['Authorization' => $secret]);

            expect($exception->getTrace())->not->toBe([]);
            expectNoMarker(exceptionForms($exception));
        });
    })->with(['arguments kept' => [false], 'arguments left out' => [true]]);
});

describe('Operation', function (): void {
    it('has the four operations and no other', function (): void {
        expect(array_map(fn(Operation $operation): string => $operation->value, Operation::cases()))
            ->toBe(['complete', 'stream', 'models', 'check']);
    });

    it('names its cases after their values', function (): void {
        expect(Operation::Complete->value)->toBe('complete')
            ->and(Operation::Stream->value)->toBe('stream')
            ->and(Operation::Models->value)->toBe('models')
            ->and(Operation::Check->value)->toBe('check');
    });
});
