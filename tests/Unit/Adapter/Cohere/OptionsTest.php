<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Cohere\Options;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Preset\ModelOverride;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatV2;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// The options of a preset on the Chat v2 adapter (docs/design/adapters.md, sections 2.3, 4.3, 6.1 and 9): its own
// one, `schema_key`, and the capability options every adapter reads.

/**
 * The capabilities of a model as a list of words, in the order of the capability table.
 *
 * @return list<string|int|null>
 */
function chatV2CapabilityWords(Capabilities $can): array
{
    return [
        $can->toolCalling->value, $can->forcedToolCall->value, $can->parallelToolCalls->value, $can->structuredOutput->value,
        $can->toolsWithStructuredOutput->value, $can->streaming->value, $can->modelListing->value, $can->temperature->value, $can->contextLength,
    ];
}

describe('the capabilities of the Chat v2 adapter', function (): void {
    it('are the defaults of the format for a preset that sets nothing, also for a model nobody knows, and send nothing', function (): void {
        $transport = new FakeTransport();
        $can = ChatV2::gateway($transport)->capabilities('a-model-nobody-knows');

        expect(chatV2CapabilityWords($can))->toBe(['native', 'emulated', 'native', 'native', 'emulated', 'native', 'native', 'native', null])
            ->and($transport->requests())->toBe([]);
    });

    it('follow every value of every capability option', function (string $option, string|int $value, int $at, string|int $word): void {
        $can = ChatV2::gateway(new FakeTransport(), [$option => $value])->capabilities(ChatV2::MODEL);

        expect(chatV2CapabilityWords($can)[$at])->toBe($word);
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
        ['context_length', 256000, 8, 256000],
    ]);

    it('follow a model override of the preset, for the models it names and no other', function (): void {
        $gateway = ChatV2::gateway(new FakeTransport(), overrides: [new ModelOverride('example-old-*', ['forced_tool_call' => 'none', 'structured_output' => 'emulated'])]);

        expect(chatV2CapabilityWords($gateway->capabilities('example-old-08-2024')))->toBe(['native', 'none', 'native', 'emulated', 'emulated', 'native', 'native', 'native', null])
            ->and(chatV2CapabilityWords($gateway->capabilities('example-new-03-2025')))->toBe(['native', 'emulated', 'native', 'native', 'emulated', 'native', 'native', 'native', null]);
    });
});

describe('the options of the Chat v2 adapter', function (): void {
    it('accepts both values of its own option, and each names the member that holds the schema of a response format', function (): void {
        $request = ChatV2::request()->withResponseFormat(ChatV2::trip());

        $default = ChatV2::bodyFor($request);
        $reference = ChatV2::bodyFor($request, ['schema_key' => 'json_schema']);
        $guide = ChatV2::bodyFor($request, ['schema_key' => 'schema']);

        expect($reference)->toBe($default)
            ->and(array_keys($default['response_format']))->toBe(['type', 'json_schema'])
            ->and(array_keys($guide['response_format']))->toBe(['type', 'schema'])
            ->and($guide['response_format']['type'])->toBe('json_object')
            ->and($guide['response_format']['schema'])->toBe($default['response_format']['json_schema']);
    });

    it('reads its own option for the model of the request, so a model override can set it', function (): void {
        $overrides = [new ModelOverride('example-guide-*', ['schema_key' => 'schema'])];
        $request = fn(string $model) => new Request($model, [Message::user('Hi.')], 30.0, responseFormat: ChatV2::trip());

        expect(array_keys(ChatV2::bodyFor($request('example-guide-1'), overrides: $overrides)['response_format']))->toBe(['type', 'schema'])
            ->and(array_keys(ChatV2::bodyFor($request('example-other-1'), overrides: $overrides)['response_format']))->toBe(['type', 'json_schema']);
    });

    it('fails as not configured for an option with a value outside its list, whatever is asked, and sends nothing', function (array $options, string $name): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered());
        $gateway = ChatV2::gateway($transport, $options);

        $errors = [
            Talk::errorOf(fn() => $gateway->capabilities(ChatV2::MODEL)),
            Talk::errorOf(fn() => $gateway->complete(ChatV2::request())),
            Talk::failedIn(Talk::events($gateway->stream(ChatV2::request())))->error,
        ];
        foreach ($errors as $error) {
            expect($error->reason)->toBe(ErrorReason::NotConfigured)
                ->and($error->detail)->toBe('the option ' . $name . ' of the preset has a value outside its list')
                ->and($error->attempts)->toBe(0);
        }
        expect($transport->requests())->toBe([]);
    })->with([
        'the schema key, another word' => [['schema_key' => 'schema_definition'], 'schema_key'],
        'the schema key, another case' => [['schema_key' => 'Schema'], 'schema_key'],
        'the schema key, a boolean' => [['schema_key' => true], 'schema_key'],
        'the schema key, a number' => [['schema_key' => 0], 'schema_key'],
        'the schema key, an empty word' => [['schema_key' => ''], 'schema_key'],
        'tool calling' => [['tool_calling' => 'emulated'], 'tool_calling'],
        'a forced call' => [['forced_tool_call' => 'yes'], 'forced_tool_call'],
        'parallel calls' => [['parallel_tool_calls' => 'emulated'], 'parallel_tool_calls'],
        'structured output' => [['structured_output' => 'strict'], 'structured_output'],
        'tools with structured output' => [['tools_with_structured_output' => true], 'tools_with_structured_output'],
        'streaming' => [['streaming' => 'none'], 'streaming'],
        'the temperature' => [['temperature' => 'none'], 'temperature'],
        'the context length' => [['context_length' => 0], 'context_length'],
    ]);

    it('fails as not configured for the model list too, when an option of the preset is outside its list', function (array $options, string $name): void {
        $transport = new FakeTransport();
        $error = Talk::errorOf(fn() => ChatV2::gateway($transport, $options)->models(10.0));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the option ' . $name . ' of the preset has a value outside its list')
            ->and($transport->requests())->toBe([]);
    })->with([
        [['schema_key' => 'other'], 'schema_key'],
        [['model_listing' => 'emulated'], 'model_listing'],
    ]);

    it('fails as not configured for an option whose name it does not know, whatever is asked, without repeating the name', function (string $name): void {
        $transport = new FakeTransport();
        $gateway = ChatV2::gateway($transport, [$name => 'x']);

        $errors = [
            Talk::errorOf(fn() => $gateway->capabilities(ChatV2::MODEL)),
            Talk::errorOf(fn() => $gateway->complete(ChatV2::request())),
            Talk::failedIn(Talk::events($gateway->stream(ChatV2::request())))->error,
            Talk::errorOf(fn() => $gateway->models(10.0)),
        ];
        foreach ($errors as $error) {
            expect($error->reason)->toBe(ErrorReason::NotConfigured)
                ->and($error->detail)->toBe('the preset has an adapter option this adapter does not know');
        }
        expect($transport->requests())->toBe([]);
    })->with([
        'an option of another adapter' => ['max_tokens_field'],
        'another option of another adapter' => ['api_version'],
        'the schema option of another adapter' => ['json_schema_key'],
        'a name written wrongly' => ['schema_kye'],
        'the name of a field of the format' => ['strict_tools'],
        'another case' => ['Schema_Key'],
    ]);

    it('refuses the same in the options value itself', function (): void {
        expect(fn() => new Options(['schema_key' => 'yes']))->toThrow(InvalidValue::class)
            ->and(fn() => new Options(['other' => 1]))->toThrow(InvalidValue::class)
            ->and((new Options([]))->schemaKey)->toBe('json_schema')
            ->and((new Options(['schema_key' => 'json_schema']))->schemaKey)->toBe('json_schema')
            ->and((new Options(['schema_key' => 'schema', 'streaming' => 'emulated']))->schemaKey)->toBe('schema')
            ->and(Options::optionOutsideItsList(['schema_key' => 'x', 'streaming' => 'y']))->toBe('schema_key')
            ->and(Options::hasAnUnknownOption(['context_length' => 5, 'schema_key' => 'schema']))->toBeFalse();
    });

    it('gives the whole answer as the events of a stream where the preset says the server does not stream, with one request that asks for no stream', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered());
        $events = Talk::events(ChatV2::gateway($transport, ['streaming' => 'emulated'])->stream(ChatV2::request()));

        expect(Talk::finishedIn($events)->answer->text)->toBe('Hello.')
            ->and($transport->requests())->toHaveCount(1)
            ->and(ChatV2::sent($transport)['stream'])->toBeFalse();
    });

    it('refuses a temperature before anything is sent where the preset says the model takes none, and sends it where it does', function (): void {
        $transport = new FakeTransport();
        $request = new Request(ChatV2::MODEL, [Message::user('Hi.')], 30.0, temperature: 0.5);
        $error = Talk::errorOf(fn() => ChatV2::gateway($transport, ['temperature' => 'not_accepted'])->complete($request));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($transport->requests())->toBe([])
            ->and(ChatV2::bodyFor($request)['temperature'])->toBe(0.5);
    });

    it('refuses tools, and a response format, before anything is sent where the preset says the model has neither', function (): void {
        $transport = new FakeTransport();
        $withTools = new Request(ChatV2::MODEL, [Message::user('Hi.')], 30.0, [ChatV2::lookup()]);
        $withFormat = ChatV2::request()->withResponseFormat(ChatV2::trip());

        expect(Talk::errorOf(fn() => ChatV2::gateway($transport, ['tool_calling' => 'none'])->complete($withTools))->reason)->toBe(ErrorReason::NotSupported)
            ->and(Talk::errorOf(fn() => ChatV2::gateway($transport, ['structured_output' => 'none'])->complete($withFormat))->reason)->toBe(ErrorReason::NotSupported)
            ->and(Talk::errorOf(fn() => ChatV2::gateway($transport, ['tools_with_structured_output' => 'none'])->complete($withTools->withResponseFormat(ChatV2::trip())))->reason)->toBe(ErrorReason::NotSupported)
            ->and($transport->requests())->toBe([]);
    });
});
