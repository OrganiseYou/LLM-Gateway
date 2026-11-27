<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Anthropic\Options;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Preset\ModelOverride;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// The options of a preset on the Messages adapter (docs/design/adapters.md, sections 2.3, 4.1, 6.1 and 9): its own
// two, and the capability options every adapter reads.

/**
 * The capabilities of a model as a list of words, in the order of the capability table.
 *
 * @return list<string|int|null>
 */
function messagesCapabilityWords(Capabilities $can): array
{
    return [
        $can->toolCalling->value, $can->forcedToolCall->value, $can->parallelToolCalls->value, $can->structuredOutput->value,
        $can->toolsWithStructuredOutput->value, $can->streaming->value, $can->modelListing->value, $can->temperature->value, $can->contextLength,
    ];
}

describe('the capabilities of the Messages adapter', function (): void {
    it('are the defaults of the format for a preset that sets only what it must: everything native, a temperature taken', function (): void {
        $transport = new FakeTransport();
        $can = Messages::gateway($transport)->capabilities('a-model-nobody-knows');

        expect(messagesCapabilityWords($can))->toBe(['native', 'native', 'native', 'native', 'native', 'native', 'native', 'native', null])
            ->and($transport->requests())->toBe([]);
    });

    it('say that no temperature is taken with the options of the shipped entry, and nothing else differs', function (): void {
        $can = Messages::gateway(new FakeTransport(), Messages::CURRENT_VERSION)->capabilities(Messages::MODEL);

        expect(messagesCapabilityWords($can))->toBe(['native', 'native', 'native', 'native', 'native', 'native', 'native', 'none', null]);
    });

    it('follow every value of every capability option', function (string $option, string|int $value, int $at, string|int $word): void {
        $can = Messages::gateway(new FakeTransport(), [...Messages::EARLIER_VERSION, $option => $value])->capabilities(Messages::MODEL);

        expect(messagesCapabilityWords($can)[$at])->toBe($word);
    })->with([
        ['tool_calling', 'native', 0, 'native'],
        ['tool_calling', 'none', 0, 'none'],
        ['forced_tool_call', 'native', 1, 'native'],
        ['forced_tool_call', 'emulated', 1, 'emulated'],
        ['forced_tool_call', 'none', 1, 'none'],
        ['parallel_tool_calls', 'native', 2, 'native'],
        ['parallel_tool_calls', 'none', 2, 'none'],
        ['structured_output', 'native', 3, 'native'],
        ['structured_output', 'emulated', 3, 'emulated'],
        ['structured_output', 'none', 3, 'none'],
        ['tools_with_structured_output', 'native', 4, 'native'],
        ['tools_with_structured_output', 'emulated', 4, 'emulated'],
        ['tools_with_structured_output', 'none', 4, 'none'],
        ['streaming', 'native', 5, 'native'],
        ['streaming', 'emulated', 5, 'emulated'],
        ['model_listing', 'native', 6, 'native'],
        ['model_listing', 'none', 6, 'none'],
        ['temperature', 'accepted', 7, 'native'],
        ['temperature', 'not_accepted', 7, 'none'],
        ['context_length', 200000, 8, 200000],
    ]);

    it('say that a named model cannot be forced to one tool, where a model override says so', function (): void {
        $gateway = Messages::gateway(new FakeTransport(), Messages::CURRENT_VERSION, overrides: [new ModelOverride('example-newest-*', ['forced_tool_call' => 'none'])]);

        expect($gateway->capabilities('example-newest-5')->forcedToolCall)->toBe(Support::None)
            ->and($gateway->capabilities('example-older-4')->forcedToolCall)->toBe(Support::Native);
    });
});

describe('the options of the Messages adapter', function (): void {
    it('takes a version of the API in the form of a date', function (string $version): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered());
        Messages::gateway($transport, ['api_version' => $version, 'default_max_output_tokens' => 1])->complete(Messages::request());

        expect($transport->requests()[0]->headers['anthropic-version'])->toBe($version)
            ->and((new Options(['api_version' => $version, 'default_max_output_tokens' => 1]))->apiVersion)->toBe($version);
    })->with([
        'the current version' => ['2026-10-21'],
        'the earlier version' => ['2023-06-01'],
        'a version of a later day' => ['2027-01-15'],
    ]);

    it('takes a default for the maximum of output tokens of 1 and up', function (int $limit): void {
        expect((new Options(['api_version' => '2026-10-21', 'default_max_output_tokens' => $limit]))->defaultOutputLimit)->toBe($limit);
    })->with([1, 16000, 128000]);

    it('fails as not_configured, with a detail that names the option and never the value, for a value outside its list', function (string $option, mixed $value): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered())->willStream(200, [], ...Messages::saying());
        $gateway = Messages::gateway($transport, [...Messages::EARLIER_VERSION, $option => $value]);

        $errors = [
            Talk::errorOf(fn() => $gateway->capabilities(Messages::MODEL)),
            Talk::errorOf(fn() => $gateway->complete(Messages::request())),
            Talk::failedIn(Talk::events($gateway->stream(Messages::request())))->error,
        ];

        foreach ($errors as $error) {
            expect($error->reason)->toBe(ErrorReason::NotConfigured)
                ->and($error->detail)->toBe('the option ' . $option . ' of the preset has a value outside its list')
                ->and($error->attempts)->toBe(0);
        }
        expect($transport->requests())->toBe([])
            ->and(Options::optionOutsideItsList([...Messages::EARLIER_VERSION, $option => $value]))->toBe(in_array($option, ['api_version', 'default_max_output_tokens'], true) ? $option : null);
    })->with([
        'a version that is no date' => ['api_version', 'latest'],
        'a version with a line end in it' => ['api_version', "2026-10-21\r\nx-other: 1"],
        'a version as a number' => ['api_version', 20261021],
        'a version as a boolean' => ['api_version', true],
        'an empty version' => ['api_version', ''],
        'a default maximum of 0' => ['default_max_output_tokens', 0],
        'a default maximum below 0' => ['default_max_output_tokens', -1],
        'a default maximum as text' => ['default_max_output_tokens', '16000'],
        'a default maximum as a boolean' => ['default_max_output_tokens', true],
        'tool calling emulated' => ['tool_calling', 'emulated'],
        'a forced tool call in another word' => ['forced_tool_call', 'yes'],
        'parallel tool calls emulated' => ['parallel_tool_calls', 'emulated'],
        'structured output as a boolean' => ['structured_output', true],
        'tools with structured output in another word' => ['tools_with_structured_output', 'sometimes'],
        'streaming none' => ['streaming', 'none'],
        'model listing emulated' => ['model_listing', 'emulated'],
        'a temperature in the words of a capability' => ['temperature', 'native'],
        'a context length of 0' => ['context_length', 0],
    ]);

    it('fails as not_configured for an option whose name it does not know, and does not repeat the name', function (string $name): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered())->willReturn(Messages::answered());
        $gateway = Messages::gateway($transport, [...Messages::EARLIER_VERSION, $name => 'x']);

        $errors = [
            Talk::errorOf(fn() => $gateway->capabilities(Messages::MODEL)),
            Talk::errorOf(fn() => $gateway->complete(Messages::request())),
            Talk::errorOf(fn() => $gateway->models(10.0)),
        ];

        foreach ($errors as $error) {
            expect($error->reason)->toBe(ErrorReason::NotConfigured)
                ->and($error->detail)->toBe('the preset has an adapter option this adapter does not know');
        }
        expect($transport->requests())->toBe([])
            ->and(Options::hasAnUnknownOption([$name => 'x']))->toBeTrue();
    })->with([
        'a name written wrongly' => ['default_max_output_token'],
        'an option of another adapter' => ['max_tokens_field'],
        'a field of the format' => ['thinking'],
        'a name in capitals' => ['API_VERSION'],
    ]);

    it('fails as not_configured when the preset does not set one of the two options the adapter needs, for every operation', function (array $options, string $missing): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered())->willReturn(Messages::answered());
        $gateway = Messages::gateway($transport, $options);
        // Also a request that gives its own maximum: a preset that lacks an option can do nothing.
        $withAMaximum = new OrganiseYou\LlmGateway\Contract\Request(Messages::MODEL, [OrganiseYou\LlmGateway\Contract\Message::user('Hi.')], 30.0, maxOutputTokens: 100);

        $errors = [
            Talk::errorOf(fn() => $gateway->capabilities(Messages::MODEL)),
            Talk::errorOf(fn() => $gateway->complete(Messages::request())),
            Talk::errorOf(fn() => $gateway->complete($withAMaximum)),
            Talk::failedIn(Talk::events($gateway->stream(Messages::request())))->error,
            Talk::errorOf(fn() => $gateway->models(10.0)),
        ];

        foreach ($errors as $error) {
            expect($error->reason)->toBe(ErrorReason::NotConfigured)
                ->and($error->detail)->toBe('the preset does not set the option ' . $missing . ', which this adapter needs')
                ->and($error->attempts)->toBe(0);
        }
        expect($transport->requests())->toBe([])
            ->and(Options::missingOption($options))->toBe($missing);
    })->with([
        'no version of the API' => [['default_max_output_tokens' => 16000], 'api_version'],
        'no maximum of output tokens for a request that gives none' => [['api_version' => '2026-10-21'], 'default_max_output_tokens'],
        'neither' => [[], 'api_version'],
        'neither, and a capability option' => [['temperature' => 'not_accepted'], 'api_version'],
    ]);

    it('misses nothing in a preset that sets both', function (): void {
        expect(Options::missingOption(Messages::EARLIER_VERSION))->toBeNull()
            ->and(Options::missingOption(Messages::CURRENT_VERSION))->toBeNull();
    });

    it('refuses to be built from options it would not take', function (array $options): void {
        expect(fn() => new Options($options))->toThrow(InvalidValue::class);
    })->with([
        'no version' => [['default_max_output_tokens' => 16000]],
        'no default for the maximum of output tokens' => [['api_version' => '2026-10-21']],
        'an unknown name' => [[...Messages::EARLIER_VERSION, 'other' => 1]],
        'a value outside its list' => [['api_version' => 'soon', 'default_max_output_tokens' => 16000]],
    ]);

    it('knows its own two options and the nine capability options, and no other name', function (): void {
        $known = [
            'api_version', 'default_max_output_tokens', 'tool_calling', 'forced_tool_call', 'parallel_tool_calls', 'structured_output',
            'tools_with_structured_output', 'streaming', 'model_listing', 'temperature', 'context_length',
        ];

        foreach ($known as $name) {
            expect(Options::hasAnUnknownOption([$name => 'x']))->toBeFalse($name);
        }
    });
});
