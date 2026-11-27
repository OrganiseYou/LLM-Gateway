<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\Support;

/**
 * @param array<string, mixed> $overrides
 */
function makeCapabilities(array $overrides = []): Capabilities
{
    return new Capabilities(...array_merge([
        'toolCalling' => Support::Native,
        'forcedToolCall' => Support::Native,
        'parallelToolCalls' => Support::Native,
        'structuredOutput' => Support::Native,
        'toolsWithStructuredOutput' => Support::Native,
        'streaming' => Support::Native,
        'modelListing' => Support::Native,
        'contextLength' => null,
    ], $overrides));
}

describe('Support', function (): void {
    it('has native, emulated and none', function (): void {
        expect(array_map(fn(Support $support): string => $support->value, Support::cases()))
            ->toBe(['native', 'emulated', 'none'])
            ->and(Support::Native->value)->toBe('native')
            ->and(Support::Emulated->value)->toBe('emulated')
            ->and(Support::None->value)->toBe('none');
    });

    it('is available unless none', function (): void {
        expect(Support::Native->isAvailable())->toBeTrue()
            ->and(Support::Emulated->isAvailable())->toBeTrue()
            ->and(Support::None->isAvailable())->toBeFalse();
    });
});

describe('Capabilities', function (): void {
    it('keeps every field', function (): void {
        $capabilities = new Capabilities(
            toolCalling: Support::Native,
            forcedToolCall: Support::Emulated,
            parallelToolCalls: Support::None,
            structuredOutput: Support::Emulated,
            toolsWithStructuredOutput: Support::None,
            streaming: Support::Emulated,
            modelListing: Support::None,
            contextLength: 128000,
        );

        expect($capabilities->toolCalling)->toBe(Support::Native)
            ->and($capabilities->forcedToolCall)->toBe(Support::Emulated)
            ->and($capabilities->parallelToolCalls)->toBe(Support::None)
            ->and($capabilities->structuredOutput)->toBe(Support::Emulated)
            ->and($capabilities->toolsWithStructuredOutput)->toBe(Support::None)
            ->and($capabilities->streaming)->toBe(Support::Emulated)
            ->and($capabilities->modelListing)->toBe(Support::None)
            ->and($capabilities->contextLength)->toBe(128000);
    });

    it('has exactly the nine fields of the capability list', function (): void {
        $names = array_map(
            fn(ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(Capabilities::class))->getProperties(),
        );

        expect($names)->toBe([
            'toolCalling',
            'forcedToolCall',
            'parallelToolCalls',
            'structuredOutput',
            'toolsWithStructuredOutput',
            'streaming',
            'modelListing',
            'contextLength',
            'temperature',
        ]);
    });

    it('takes a temperature unless it is told otherwise: the ninth argument has a default', function (): void {
        $positional = new Capabilities(Support::Native, Support::Native, Support::Native, Support::Native, Support::Native, Support::Native, Support::Native, null);
        $parameter = (new ReflectionMethod(Capabilities::class, '__construct'))->getParameters()[8];

        expect($positional->temperature)->toBe(Support::Native)
            ->and(makeCapabilities()->temperature)->toBe(Support::Native)
            ->and($parameter->getName())->toBe('temperature')
            ->and($parameter->isDefaultValueAvailable())->toBeTrue()
            ->and($parameter->getDefaultValue())->toBe(Support::Native);
    });

    it('accepts temperature as native or none', function (Support $temperature): void {
        expect(makeCapabilities(['temperature' => $temperature])->temperature)->toBe($temperature);
    })->with([Support::Native, Support::None]);

    it('throws when temperature is emulated', function (): void {
        expect(fn() => makeCapabilities(['temperature' => Support::Emulated]))
            ->toThrow(InvalidArgumentException::class, 'A temperature is native or none; it cannot be emulated.');
    });

    it('lets a model without a temperature have every other capability', function (): void {
        $capabilities = makeCapabilities(['temperature' => Support::None]);

        expect($capabilities->toolCalling)->toBe(Support::Native)
            ->and($capabilities->structuredOutput)->toBe(Support::Native)
            ->and($capabilities->temperature->isAvailable())->toBeFalse();
    });

    it('allows a context length that is not known', function (): void {
        expect(makeCapabilities(['contextLength' => null])->contextLength)->toBeNull();
    });

    it('accepts a context length of 1', function (): void {
        expect(makeCapabilities(['contextLength' => 1])->contextLength)->toBe(1);
    });

    it('throws when contextLength is below 1', function (int $contextLength): void {
        makeCapabilities(['contextLength' => $contextLength]);
    })->with([0, -1])->throws(InvalidArgumentException::class);

    it('throws when toolCalling is none while forcedToolCall is not none', function (Support $forced): void {
        makeCapabilities([
            'toolCalling' => Support::None,
            'forcedToolCall' => $forced,
            'parallelToolCalls' => Support::None,
            'toolsWithStructuredOutput' => Support::None,
        ]);
    })->with([Support::Native, Support::Emulated])->throws(InvalidArgumentException::class);

    it('throws when toolCalling is none while parallelToolCalls is not none', function (Support $parallel): void {
        makeCapabilities([
            'toolCalling' => Support::None,
            'forcedToolCall' => Support::None,
            'parallelToolCalls' => $parallel,
            'toolsWithStructuredOutput' => Support::None,
        ]);
    })->with([Support::Native, Support::Emulated])->throws(InvalidArgumentException::class);

    it('throws when structuredOutput is none while toolsWithStructuredOutput is not none', function (Support $both): void {
        makeCapabilities([
            'structuredOutput' => Support::None,
            'toolsWithStructuredOutput' => $both,
        ]);
    })->with([Support::Native, Support::Emulated])->throws(InvalidArgumentException::class);

    it('accepts no tool calling when forced and parallel tool calls are none too', function (): void {
        $capabilities = makeCapabilities([
            'toolCalling' => Support::None,
            'forcedToolCall' => Support::None,
            'parallelToolCalls' => Support::None,
            'toolsWithStructuredOutput' => Support::None,
        ]);

        expect($capabilities->toolCalling->isAvailable())->toBeFalse()
            ->and($capabilities->structuredOutput)->toBe(Support::Native);
    });

    it('accepts no structured output when tools with structured output is none too', function (): void {
        $capabilities = makeCapabilities([
            'structuredOutput' => Support::None,
            'toolsWithStructuredOutput' => Support::None,
        ]);

        expect($capabilities->structuredOutput->isAvailable())->toBeFalse()
            ->and($capabilities->toolCalling)->toBe(Support::Native);
    });

    it('throws when streaming is none', function (): void {
        makeCapabilities(['streaming' => Support::None]);
    })->throws(InvalidArgumentException::class);

    it('accepts streaming as native or emulated', function (Support $streaming): void {
        expect(makeCapabilities(['streaming' => $streaming])->streaming)->toBe($streaming);
    })->with([Support::Native, Support::Emulated]);

    it('throws when modelListing is emulated', function (): void {
        makeCapabilities(['modelListing' => Support::Emulated]);
    })->throws(InvalidArgumentException::class);

    it('accepts modelListing as native or none', function (Support $listing): void {
        expect(makeCapabilities(['modelListing' => $listing])->modelListing)->toBe($listing);
    })->with([Support::Native, Support::None]);

    it('accepts tool calling without a forced tool call', function (): void {
        $capabilities = makeCapabilities(['forcedToolCall' => Support::None]);

        expect($capabilities->forcedToolCall)->toBe(Support::None);
    });
});
