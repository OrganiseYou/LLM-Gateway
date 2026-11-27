<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\ModelOverride;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Preset\PresetField;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\RecordingObserver;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Tests\Support\TalkAdapter;
use OrganiseYou\LlmGateway\Transport\AttemptObserver;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\NullAttemptObserver;

// Options per model, the capability options every adapter reads, and a setting that is sent as a header. Everything
// runs on the adapter of the made-up format, which reaches the base only through its protected methods.

/**
 * A preset of the made-up format with options, model overrides and fields of the test's own.
 *
 * @param array<string, bool|int|string> $options
 * @param list<ModelOverride> $overrides
 * @param list<PresetField> $fields
 */
function optionsPreset(array $options = [], array $overrides = [], array $fields = []): Preset
{
    return new Preset(
        id: 'talk-test',
        label: 'Talk (test)',
        description: 'A made-up server for the tests.',
        adapter: TalkAdapter::class,
        baseUrlRule: BaseUrlRule::CustomerMay,
        defaultBaseUrl: Talk::BASE_URL,
        credentialKinds: [CredentialKind::BearerKey, CredentialKind::HeaderKey, CredentialKind::None],
        keyHeader: 'X-Talk-Key',
        fields: $fields,
        dataLocation: 'not stated by the provider',
        dataLocationSource: null,
        sendsNoStoreOption: false,
        adapterOptions: $options,
        modelOverrides: $overrides,
    );
}

/**
 * @param array<string, string> $settings
 */
function optionsGateway(
    FakeTransport $transport,
    Preset $preset,
    array $settings = [],
    #[SensitiveParameter]
    ?Credentials $credentials = null,
    ?AttemptObserver $observer = null,
    ?RetryPolicy $retry = null,
    ?FakeClock $clock = null,
): Gateway {
    return GatewayFactory::create(
        $preset,
        $credentials ?? new BearerKey('key-of-the-test'),
        $transport,
        new GatewayConfig(
            retry: $retry ?? RetryPolicy::none(),
            settings: $settings,
            observer: $observer ?? new NullAttemptObserver(),
            clock: $clock ?? new FakeClock(),
        ),
    );
}

/**
 * What a model can do, as words.
 *
 * @return array<string, int|string|null>
 */
function capabilityWords(Capabilities $can): array
{
    return [
        'tool_calling' => $can->toolCalling->value,
        'forced_tool_call' => $can->forcedToolCall->value,
        'parallel_tool_calls' => $can->parallelToolCalls->value,
        'structured_output' => $can->structuredOutput->value,
        'tools_with_structured_output' => $can->toolsWithStructuredOutput->value,
        'streaming' => $can->streaming->value,
        'model_listing' => $can->modelListing->value,
        'context_length' => $can->contextLength,
        'temperature' => $can->temperature->value,
    ];
}

/**
 * @param array<string, bool|int|string> $options
 * @param list<ModelOverride> $overrides
 * @return array<string, int|string|null>
 */
function capabilityWordsFor(array $options, array $overrides = [], string $model = 'model-a'): array
{
    return capabilityWords(optionsGateway(new FakeTransport(), optionsPreset($options, $overrides))->capabilities($model));
}

function optionsTool(string $name = 'lookup'): ToolDefinition
{
    return new ToolDefinition($name, 'Looks something up.', JsonSchema::fromArray(['type' => 'object']));
}

/**
 * @return list<string> the chunks of a stream of the made-up format that says hello
 */
function optionsStream(): array
{
    return [Talk::event('say', ['piece' => 'Hello.']), Talk::event('end', ['end' => 'done', 'by' => 'model-a-0001'])];
}

const ALL_NATIVE = [
    'tool_calling' => 'native',
    'forced_tool_call' => 'native',
    'parallel_tool_calls' => 'native',
    'structured_output' => 'native',
    'tools_with_structured_output' => 'native',
    'streaming' => 'native',
    'model_listing' => 'native',
    'context_length' => null,
    'temperature' => 'native',
];

describe('the capability options of a preset', function (): void {
    it('leave the defaults of the format as they are when the preset sets none', function (): void {
        expect(capabilityWordsFor([]))->toBe(ALL_NATIVE)
            // The defaults are what the adapter hands in: here the made-up format's own options decide them.
            ->and(capabilityWordsFor(['forced' => 'emulated', 'streams' => false]))
            ->toBe(array_replace(ALL_NATIVE, ['forced_tool_call' => 'emulated', 'streaming' => 'emulated']));
    });

    it('are read over the defaults of the format, each with every word of its list', function (string $option, bool|int|string $value, array $changed): void {
        expect(capabilityWordsFor([$option => $value]))->toBe(array_replace(ALL_NATIVE, $changed));
    })->with([
        ['tool_calling', 'native', ['tool_calling' => 'native']],
        ['forced_tool_call', 'native', ['forced_tool_call' => 'native']],
        ['forced_tool_call', 'emulated', ['forced_tool_call' => 'emulated']],
        ['forced_tool_call', 'none', ['forced_tool_call' => 'none']],
        ['parallel_tool_calls', 'native', ['parallel_tool_calls' => 'native']],
        ['parallel_tool_calls', 'none', ['parallel_tool_calls' => 'none']],
        ['structured_output', 'native', ['structured_output' => 'native']],
        ['structured_output', 'emulated', ['structured_output' => 'emulated']],
        ['tools_with_structured_output', 'native', ['tools_with_structured_output' => 'native']],
        ['tools_with_structured_output', 'emulated', ['tools_with_structured_output' => 'emulated']],
        ['tools_with_structured_output', 'none', ['tools_with_structured_output' => 'none']],
        ['streaming', 'native', ['streaming' => 'native']],
        ['streaming', 'emulated', ['streaming' => 'emulated']],
        ['model_listing', 'native', ['model_listing' => 'native']],
        ['model_listing', 'none', ['model_listing' => 'none']],
        ['temperature', 'accepted', ['temperature' => 'native']],
        ['temperature', 'not_accepted', ['temperature' => 'none']],
        ['context_length', 1, ['context_length' => 1]],
        ['context_length', 128000, ['context_length' => 128000]],
    ]);

    it('raise a capability the format has not by default, as well as lower one', function (): void {
        expect(capabilityWordsFor(['forced' => 'none', 'streams' => false, 'lists_models' => false, 'forced_tool_call' => 'native', 'streaming' => 'native', 'model_listing' => 'native']))
            ->toBe(ALL_NATIVE);
    });

    it('make a forced tool call, parallel tool calls and tools with structured output none when tool calling is none', function (): void {
        $none = ['tool_calling' => 'none', 'forced_tool_call' => 'none', 'parallel_tool_calls' => 'none', 'tools_with_structured_output' => 'none'];

        expect(capabilityWordsFor(['tool_calling' => 'none']))->toBe(array_replace(ALL_NATIVE, $none))
            ->and(capabilityWordsFor(['tool_calling' => 'none'])['structured_output'])->toBe('native')
            // Whatever the other options say, and in whatever order they are written.
            ->and(capabilityWordsFor(['tool_calling' => 'none', 'forced_tool_call' => 'native', 'parallel_tool_calls' => 'native', 'tools_with_structured_output' => 'emulated']))
            ->toBe(array_replace(ALL_NATIVE, $none))
            ->and(capabilityWordsFor(['forced_tool_call' => 'emulated', 'parallel_tool_calls' => 'native', 'tool_calling' => 'none']))
            ->toBe(array_replace(ALL_NATIVE, $none));
    });

    it('make tools with structured output none when structured output is none, and leave the tools as they are', function (): void {
        $none = ['structured_output' => 'none', 'tools_with_structured_output' => 'none'];

        expect(capabilityWordsFor(['structured_output' => 'none']))->toBe(array_replace(ALL_NATIVE, $none))
            ->and(capabilityWordsFor(['structured_output' => 'none', 'tools_with_structured_output' => 'native']))->toBe(array_replace(ALL_NATIVE, $none))
            ->and(capabilityWordsFor(['tools_with_structured_output' => 'emulated', 'structured_output' => 'none']))->toBe(array_replace(ALL_NATIVE, $none));
    });

    it('make them none through an override for one model, over a preset that says native', function (): void {
        $overrides = [new ModelOverride('old-*', ['tool_calling' => 'none'])];
        $options = ['forced_tool_call' => 'native', 'parallel_tool_calls' => 'native', 'tools_with_structured_output' => 'native'];

        expect(capabilityWordsFor($options, $overrides, 'old-1'))
            ->toBe(array_replace(ALL_NATIVE, ['tool_calling' => 'none', 'forced_tool_call' => 'none', 'parallel_tool_calls' => 'none', 'tools_with_structured_output' => 'none']))
            ->and(capabilityWordsFor($options, $overrides, 'new-1'))->toBe(ALL_NATIVE);
    });

    it('are not_configured for a value outside the list of its option, with the option in the detail and never the value', function (string $option, bool|int|string $value): void {
        $transport = new FakeTransport();
        $gateway = optionsGateway($transport, optionsPreset([$option => $value]));

        $error = Talk::errorOf(fn() => $gateway->capabilities('model-a'));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the option ' . $option . ' of the preset has a value outside its list')
            ->and($error->attempts)->toBe(0)
            ->and($error->httpStatus)->toBeNull()
            ->and($error->traceId)->toMatch('/^[0-9a-f]{32}$/D')
            ->and($transport->requests())->toBe([]);
        if (is_string($value) && $value !== '') {
            expect(implode(' ', exceptionForms($error)))->not->toContain($value);
        }
    })->with([
        'tool calling cannot be emulated' => ['tool_calling', 'emulated'],
        'tool calling: a word of no list' => ['tool_calling', 'value-of-no-list'],
        'tool calling: a boolean' => ['tool_calling', true],
        'tool calling: another case of letters' => ['tool_calling', 'Native'],
        'tool calling: the empty text' => ['tool_calling', ''],
        'a forced tool call: a word of no list' => ['forced_tool_call', 'value-of-no-list'],
        'a forced tool call: a number' => ['forced_tool_call', 1],
        'parallel tool calls cannot be emulated' => ['parallel_tool_calls', 'emulated'],
        'structured output: a word of no list' => ['structured_output', 'value-of-no-list'],
        'structured output: a boolean' => ['structured_output', false],
        'tools with structured output: a word of no list' => ['tools_with_structured_output', 'value-of-no-list'],
        'streaming is never none' => ['streaming', 'none'],
        'streaming: a boolean' => ['streaming', true],
        'model listing cannot be emulated' => ['model_listing', 'emulated'],
        'temperature takes its own two words, not native' => ['temperature', 'native'],
        'temperature, not none either' => ['temperature', 'none'],
        'temperature cannot be emulated' => ['temperature', 'emulated'],
        'a context length of 0' => ['context_length', 0],
        'a context length below 0' => ['context_length', -1],
        'a context length as text' => ['context_length', '128000'],
        'a context length as a boolean' => ['context_length', true],
    ]);

    it('are not_configured for a value outside its list also where another option would make the capability none', function (): void {
        $error = Talk::errorOf(fn() => optionsGateway(new FakeTransport(), optionsPreset(['tool_calling' => 'none', 'forced_tool_call' => 'value-of-no-list']))->capabilities('model-a'));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the option forced_tool_call of the preset has a value outside its list');
    });

    it('end a call as not_configured before anything is sent, with the trace id of the call, in complete(), stream() and for a value of an override', function (): void {
        $transport = new FakeTransport();
        $gateway = optionsGateway($transport, optionsPreset([], [new ModelOverride('model-a', ['streaming' => 'none'])]));

        $error = Talk::errorOf(fn() => $gateway->complete(Talk::request(traceId: 'trace-of-the-call')));
        $failed = Talk::failedIn(Talk::events($gateway->stream(Talk::request(traceId: 'trace-of-the-stream'))));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the option streaming of the preset has a value outside its list')
            ->and($error->traceId)->toBe('trace-of-the-call')
            ->and($error->attempts)->toBe(0)
            ->and($failed->error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($failed->error->traceId)->toBe('trace-of-the-stream')
            ->and($transport->requests())->toBe([])
            // Another model of the same preset is not touched by it.
            ->and($gateway->capabilities('model-b')->streaming)->toBe(Support::Native);
    });

    it('do not look at an option that is not a capability option: its name and its value are the adapter\'s own', function (): void {
        expect(capabilityWordsFor(['flavour' => 'value-of-no-list', 'max_tool_name' => -5, 'toolcalling' => 'none']))->toBe(ALL_NATIVE);
    });

    it('decide what a request is refused for, before anything is sent', function (): void {
        $transport = new FakeTransport();
        $gateway = optionsGateway($transport, optionsPreset(['forced_tool_call' => 'none', 'temperature' => 'not_accepted']));

        $forced = Talk::errorOf(fn() => $gateway->complete(Talk::request()->withTools([optionsTool()])->withToolChoice(ToolChoice::tool('lookup'))));
        $warm = Talk::errorOf(fn() => $gateway->complete(new Request('model-a', [Message::user('Say hello.')], 30.0, temperature: 0.2)));

        expect($forced->reason)->toBe(ErrorReason::NotSupported)
            ->and($forced->detail)->toBe('this model cannot be made to call one named tool')
            ->and($warm->reason)->toBe(ErrorReason::NotSupported)
            ->and($warm->detail)->toBe('this model takes no temperature')
            ->and($transport->requests())->toBe([]);
    });

    it('decide the route of a request: emulated streaming is one plain request, emulated structured output goes in the system text', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"ok":true}'));
        $gateway = optionsGateway($transport, optionsPreset(['streaming' => 'emulated', 'structured_output' => 'emulated']));

        $events = Talk::events($gateway->stream(Talk::request()->withResponseFormat(new ResponseFormat(JsonSchema::fromArray(['type' => 'object']), 'verdict'))));
        $body = json_decode($transport->requests()[0]->body, true);

        expect(Talk::finishedIn($events)->answer->structured?->isUsable())->toBeTrue()
            ->and($transport->requests())->toHaveCount(1)
            ->and($body['stream'])->toBeFalse()
            ->and($body['shape'])->toBeNull()
            ->and($body['lines'][0]['who'])->toBe('system');
    });
});

describe('the capabilities of one model', function (): void {
    it('come from the first that speaks: an override that fits, then the options of the preset, then the defaults of the format', function (): void {
        $preset = optionsPreset(
            ['streaming' => 'emulated', 'context_length' => 32000],
            [new ModelOverride('small-*', ['streaming' => 'native', 'forced_tool_call' => 'none'])],
        );
        $gateway = optionsGateway(new FakeTransport(), $preset);

        expect(capabilityWords($gateway->capabilities('small-1')))
            ->toBe(array_replace(ALL_NATIVE, ['streaming' => 'native', 'forced_tool_call' => 'none', 'context_length' => 32000]))
            ->and($gateway->capabilities('small-1')->streaming)->toBe(Support::Native)
            ->and($gateway->capabilities('small-1')->forcedToolCall)->toBe(Support::None)
            // A model the preset does not know: the options of the preset, and the defaults for the rest.
            ->and($gateway->capabilities('a-model-nobody-listed')->streaming)->toBe(Support::Emulated)
            ->and($gateway->capabilities('a-model-nobody-listed')->forcedToolCall)->toBe(Support::Native)
            ->and($gateway->capabilities('a-model-nobody-listed')->contextLength)->toBe(32000);
    });

    it('take every override that fits, in the order of the list: a later one wins from an earlier one', function (): void {
        $general = new ModelOverride('small-*', ['forced_tool_call' => 'none', 'temperature' => 'not_accepted']);
        $exact = new ModelOverride('small-2', ['forced_tool_call' => 'emulated']);

        $generalFirst = optionsGateway(new FakeTransport(), optionsPreset([], [$general, $exact]));
        $exactFirst = optionsGateway(new FakeTransport(), optionsPreset([], [$exact, $general]));

        expect($generalFirst->capabilities('small-2')->forcedToolCall)->toBe(Support::Emulated)
            ->and($generalFirst->capabilities('small-2')->temperature)->toBe(Support::None)
            ->and($generalFirst->capabilities('small-1')->forcedToolCall)->toBe(Support::None)
            ->and($exactFirst->capabilities('small-2')->forcedToolCall)->toBe(Support::None)
            ->and($generalFirst->capabilities('large-1')->forcedToolCall)->toBe(Support::Native)
            ->and($generalFirst->capabilities('large-1')->temperature)->toBe(Support::Native);
    });

    it('compare the pattern of an override with the whole name, in the case it is written', function (): void {
        $gateway = optionsGateway(new FakeTransport(), optionsPreset([], [new ModelOverride('small-1', ['tool_calling' => 'none'])]));

        expect($gateway->capabilities('small-1')->toolCalling)->toBe(Support::None)
            ->and($gateway->capabilities('small-10')->toolCalling)->toBe(Support::Native)
            ->and($gateway->capabilities('Small-1')->toolCalling)->toBe(Support::Native);
    });

    it('are raised by a host with a copy of the preset, for every model no override speaks for', function (): void {
        $preset = optionsPreset(['streaming' => 'emulated', 'forced_tool_call' => 'none'], [new ModelOverride('old-*', ['streaming' => 'emulated'])]);
        $raised = optionsGateway(new FakeTransport(), $preset->withAdapterOptions(['streaming' => 'native']));

        expect($raised->capabilities('model-a')->streaming)->toBe(Support::Native)
            ->and($raised->capabilities('model-a')->forcedToolCall)->toBe(Support::None)
            ->and($raised->capabilities('old-1')->streaming)->toBe(Support::Emulated)
            ->and(optionsGateway(new FakeTransport(), $preset)->capabilities('model-a')->streaming)->toBe(Support::Emulated);
    });

    it('are asked without anything being sent, also with overrides, a setting that is sent as a header and a retry policy', function (): void {
        $transport = new FakeTransport();
        $observer = new RecordingObserver();
        $gateway = optionsGateway(
            $transport,
            optionsPreset(
                ['streaming' => 'emulated', 'model_listing' => 'none'],
                [new ModelOverride('small-*', ['tool_calling' => 'none']), new ModelOverride('*', ['context_length' => 8000])],
                [new PresetField('workspace', 'Workspace', false, 'ws_[a-z0-9]+', 'X-Talk-Workspace')],
            ),
            ['workspace' => 'ws_1a2b'],
            observer: $observer,
            retry: new RetryPolicy([1.0, 1.0], 60.0),
        );

        foreach (['small-1', 'model-a', 'a-model-nobody-listed', 'small-1'] as $model) {
            expect($gateway->capabilities($model))->toBeInstanceOf(Capabilities::class);
        }

        expect($transport->requests())->toBe([])
            ->and($observer->started)->toBe([])
            ->and($observer->finished)->toBe([])
            ->and($gateway->capabilities('small-1')->toolCalling)->toBe(Support::None)
            ->and($gateway->capabilities('model-a')->contextLength)->toBe(8000);
    });

    it('are asked without anything being sent when an option is outside its list', function (): void {
        $transport = new FakeTransport();
        $observer = new RecordingObserver();
        $gateway = optionsGateway($transport, optionsPreset(['structured_output' => 'value-of-no-list']), observer: $observer);

        expect(fn() => $gateway->capabilities('model-a'))->toThrow(GatewayException::class)
            ->and($transport->requests())->toBe([])
            ->and($observer->started)->toBe([]);
    });
});

describe('an option an adapter reads', function (): void {
    it('is, with a model, the option as the overrides of the preset say it for that model', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        $gateway = optionsGateway($transport, optionsPreset(['max_tool_name' => 64], [new ModelOverride('small-*', ['max_tool_name' => 3])]));

        $small = Talk::errorOf(fn() => $gateway->complete(new Request('small-1', [Message::user('Say hello.')], 30.0, tools: [optionsTool('lookup')])));
        $other = $gateway->complete(new Request('model-a', [Message::user('Say hello.')], 30.0, tools: [optionsTool('lookup')]));

        expect($small->reason)->toBe(ErrorReason::Rejected)
            ->and($small->detail)->toBe('a tool name is longer than this format allows')
            ->and($small->attempts)->toBe(0)
            ->and($other->text)->toBe('Hello.')
            ->and($transport->requests())->toHaveCount(1);
    });

    it('is, without a model, the option of the preset itself, whatever an override says', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"items":[]}'));
        $gateway = optionsGateway($transport, optionsPreset(['lists_models' => true], [new ModelOverride('*', ['lists_models' => false])]));

        expect($gateway->models(10.0))->toBe([])
            ->and($transport->requests())->toHaveCount(1);
    });

    it('is null when the preset does not set it, and a value of false or 0 is a value', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"items":[]}'))->willReturn(Talk::answered());

        // Not set: the adapter falls back to its own default, here "the models can be listed".
        expect(optionsGateway($transport, optionsPreset())->models(10.0))->toBe([]);

        // false is not "not set": the models cannot be listed. 0 is not "not set": the adapter's "no limit".
        $gateway = optionsGateway($transport, optionsPreset(['lists_models' => false, 'max_tool_name' => 0]));
        $error = Talk::errorOf(fn() => $gateway->models(10.0));
        $answer = $gateway->complete(Talk::request()->withTools([optionsTool('a-rather-long-tool-name')]));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($answer->text)->toBe('Hello.')
            ->and($transport->requests())->toHaveCount(2);
    });
});

describe('a setting that is sent as a header', function (): void {
    it('is added to the request of a completion, a stream and the model list, beside the headers of the adapter', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(Talk::answered())
            ->willStream(200, [], ...optionsStream())
            ->willReturn(new HttpResponse(200, [], '{"items":[]}'));
        $gateway = optionsGateway(
            $transport,
            optionsPreset(fields: [new PresetField('workspace', 'Workspace', false, 'ws_[a-z0-9]+', 'X-Talk-Workspace')]),
            ['workspace' => 'ws_1a2b'],
        );

        $gateway->complete(Talk::request());
        Talk::events($gateway->stream(Talk::request()));
        $gateway->models(10.0);

        expect($transport->requests())->toHaveCount(3);
        foreach ($transport->requests() as $request) {
            expect($request->headers['X-Talk-Workspace'] ?? null)->toBe('ws_1a2b')
                ->and($request->allHeaders()['X-Talk-Workspace'] ?? null)->toBe('ws_1a2b')
                ->and(array_keys($request->secretHeaders))->toBe(['Authorization'])
                ->and($request->url)->toStartWith(Talk::BASE_URL . '/');
        }
        expect($transport->requests()[0]->headers)->toBe(['Content-Type' => 'application/json', 'X-Talk-Workspace' => 'ws_1a2b'])
            ->and($transport->requests()[2]->headers)->toBe(['Accept' => 'application/json', 'X-Talk-Workspace' => 'ws_1a2b']);
    });

    it('is sent with every attempt of a call, and stands in the attempt report: it is no secret', function (): void {
        $clock = new FakeClock();
        $observer = new RecordingObserver();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(503))->willReturn(Talk::answered());
        $gateway = optionsGateway(
            $transport,
            optionsPreset(fields: [new PresetField('workspace', 'Workspace', false, 'ws_[a-z0-9]+', 'X-Talk-Workspace')]),
            ['workspace' => 'ws_1a2b'],
            observer: $observer,
            retry: new RetryPolicy([1.0], 60.0),
            clock: $clock,
        );

        expect($gateway->complete(Talk::request())->attempts)->toBe(2)
            ->and($transport->requests()[0]->headers['X-Talk-Workspace'])->toBe('ws_1a2b')
            ->and($transport->requests()[1]->headers['X-Talk-Workspace'])->toBe('ws_1a2b')
            ->and($observer->started)->toHaveCount(2)
            ->and($observer->started[1]->request->toArray()['headers']['X-Talk-Workspace'])->toBe('ws_1a2b')
            ->and($observer->finished[1]->request->toArray()['headers'])->toBe([
                'Content-Type' => 'application/json',
                'X-Talk-Workspace' => 'ws_1a2b',
                'Authorization' => '[secret]',
            ]);
    });

    it('is not sent when the customer left the setting out or empty', function (array $settings): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        $gateway = optionsGateway(
            $transport,
            optionsPreset(fields: [new PresetField('workspace', 'Workspace', false, 'ws_[a-z0-9]+', 'X-Talk-Workspace')]),
            $settings,
        );

        $gateway->complete(Talk::request());

        expect($transport->requests()[0]->allHeaders())->toBe(['Content-Type' => 'application/json', 'Authorization' => 'Bearer key-of-the-test']);
    })->with([
        'left out' => [[]],
        'empty' => [['workspace' => '']],
    ]);

    it('is sent for every field that names a header, and not for a field that names none', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        $gateway = optionsGateway(
            $transport,
            optionsPreset(fields: [
                new PresetField('workspace', 'Workspace', false, 'ws_[a-z0-9]+', 'X-Talk-Workspace'),
                new PresetField('dialect', 'Dialect', false, '[a-z]+'),
                new PresetField('project', 'Project', false, '[a-z0-9-]+', 'X-Talk-Project'),
                new PresetField('team', 'Team', false, '[a-z]+', 'X-Talk-Team'),
            ]),
            ['workspace' => 'ws_1a2b', 'dialect' => 'plain', 'project' => 'proj-7'],
            new HeaderKey('key-of-the-test'),
        );

        $gateway->complete(Talk::request());

        expect($transport->requests()[0]->headers)->toBe(['Content-Type' => 'application/json', 'X-Talk-Workspace' => 'ws_1a2b', 'X-Talk-Project' => 'proj-7'])
            ->and(array_keys($transport->requests()[0]->secretHeaders))->toBe(['X-Talk-Key'])
            ->and($transport->requests()[0]->secretHeaders['X-Talk-Key']->reveal())->toBe('key-of-the-test');
    });

    it('is not_configured when its value is not visible ASCII: no gateway is built and nothing is sent', function (string $value): void {
        $transport = new FakeTransport();
        $observer = new RecordingObserver();
        // The pattern lets everything through: the rule of a header value does not lean on it.
        $preset = optionsPreset(fields: [new PresetField('workspace', 'Workspace', false, '(?s).+', 'X-Talk-Workspace')]);

        $error = Talk::errorOf(fn() => optionsGateway($transport, $preset, ['workspace' => $value], observer: $observer));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the setting workspace is sent as a header and must hold only visible ASCII characters')
            ->and($error->attempts)->toBe(0)
            ->and($error->httpStatus)->toBeNull()
            ->and($transport->requests())->toBe([])
            ->and($observer->started)->toBe([])
            // The key is named, the value is nowhere.
            ->and(str_contains(implode(' ', exceptionForms($error)), $value))->toBeFalse();
    })->with([
        'a space' => ['team one'],
        'a space at the end' => ['ws_1a2b '],
        'a tab' => ["ws\t1a2b"],
        'a letter outside ASCII' => ['wörkspace'],
        'a line break and a second header' => ["ws_1a2b\r\nX-Talk-Key: another-value"],
        'a line break alone' => ["ws_1a2b\n"],
        'a null byte' => ["ws\0_1a2b"],
        'the delete character' => ["ws\x7f1a2b"],
        'a byte that is no character' => ["ws\xff1a2b"],
    ]);

    it('is checked the same way for a gateway that is built without the factory, and when the host asks for the base URL', function (): void {
        $preset = optionsPreset(fields: [new PresetField('workspace', 'Workspace', false, '.+', 'X-Talk-Workspace')]);
        $config = new GatewayConfig(retry: RetryPolicy::none(), settings: ['workspace' => 'team one']);

        $direct = Talk::errorOf(fn() => new TalkAdapter($preset, $config, new BearerKey('key-of-the-test'), new FakeTransport()));
        $asked = Talk::errorOf(fn() => GatewayFactory::resolveBaseUrl($preset, $config));

        expect($direct->reason)->toBe(ErrorReason::NotConfigured)
            ->and($direct->detail)->toBe('the setting workspace is sent as a header and must hold only visible ASCII characters')
            ->and($asked->reason)->toBe(ErrorReason::NotConfigured)
            ->and($asked->detail)->toBe($direct->detail);
    });

    it('takes every visible ASCII character, and holds a setting that is not sent as a header to its pattern alone', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        $visible = implode('', array_map(chr(...), range(0x21, 0x7e)));
        $gateway = optionsGateway(
            $transport,
            optionsPreset(fields: [
                new PresetField('workspace', 'Workspace', false, '.+', 'X-Talk-Workspace'),
                new PresetField('note', 'Note', false, '.+'),
            ]),
            ['workspace' => $visible, 'note' => 'téam one'],
        );

        $gateway->complete(Talk::request());

        expect($transport->requests()[0]->headers['X-Talk-Workspace'])->toBe($visible);
    });

    it('is held to the pattern of its field first, as every setting is', function (): void {
        $preset = optionsPreset(fields: [new PresetField('workspace', 'Workspace', false, 'ws_[a-z0-9]+', 'X-Talk-Workspace')]);
        $error = Talk::errorOf(fn() => optionsGateway(new FakeTransport(), $preset, ['workspace' => 'something-else']));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the setting workspace does not have the form this preset asks for');
    });

    it('is still required when its field says so', function (): void {
        $preset = optionsPreset(fields: [new PresetField('workspace', 'Workspace', true, 'ws_[a-z0-9]+', 'X-Talk-Workspace')]);
        $error = Talk::errorOf(fn() => optionsGateway(new FakeTransport(), $preset));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the setting workspace is required');
    });

    it('never replaces a header of the adapter: such a request is refused as a fault in code, and nothing is sent', function (string $header): void {
        $transport = new FakeTransport();
        $observer = new RecordingObserver();
        // The made-up adapter sets X-Trace itself when the preset asks for it.
        $gateway = optionsGateway(
            $transport,
            optionsPreset(['sends_trace' => true], [], [new PresetField('workspace', 'Workspace', false, 'ws_[a-z0-9]+', $header)]),
            ['workspace' => 'ws_1a2b'],
            observer: $observer,
        );

        expect(fn() => $gateway->complete(Talk::request()))
            ->toThrow(LogicException::class, 'An adapter must not set a header that a field of its preset fills: the base adds it.')
            ->and($transport->requests())->toBe([])
            ->and($observer->started)->toBe([]);
    })->with([['X-Trace'], ['x-trace'], ['X-TRACE']]);

    it('never replaces a secret header of the adapter either', function (string $header): void {
        $transport = new FakeTransport();
        $observer = new RecordingObserver();
        // The made-up adapter sets the secret header X-Talk-Seal itself when the preset asks for it.
        $preset = optionsPreset(['sends_seal' => true], [], [new PresetField('workspace', 'Workspace', false, 'ws_[a-z0-9]+', $header)]);
        $gateway = optionsGateway($transport, $preset, ['workspace' => 'ws_1a2b'], observer: $observer);

        expect(fn() => $gateway->complete(Talk::request()))
            ->toThrow(LogicException::class, 'An adapter must not set a header that a field of its preset fills: the base adds it.')
            ->and($transport->requests())->toBe([])
            ->and($observer->started)->toBe([]);

        // Without the setting the request goes out with the secret header of the adapter, and it stays a secret.
        $transport->willReturn(Talk::answered());
        optionsGateway($transport, $preset)->complete(Talk::request());

        expect(array_keys($transport->requests()[0]->secretHeaders))->toBe(['X-Talk-Seal', 'Authorization'])
            ->and($transport->requests()[0]->headers)->toBe(['Content-Type' => 'application/json']);
    })->with([['X-Talk-Seal'], ['x-talk-seal']]);

    it('leaves a request as the adapter built it when the setting that would clash is not filled in', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        $gateway = optionsGateway(
            $transport,
            optionsPreset(['sends_trace' => true], [], [new PresetField('workspace', 'Workspace', false, 'ws_[a-z0-9]+', 'X-Trace')]),
        );

        $gateway->complete(Talk::request());

        expect($transport->requests()[0]->headers)->toBe(['Content-Type' => 'application/json', 'X-Trace' => 'trace-1']);
    });

    it('never changes where a request goes or how it signs in', function (): void {
        $plain = (new FakeTransport())->willReturn(Talk::answered());
        $withHeader = (new FakeTransport())->willReturn(Talk::answered());

        optionsGateway($plain, optionsPreset())->complete(Talk::request());
        optionsGateway(
            $withHeader,
            optionsPreset(fields: [new PresetField('workspace', 'Workspace', false, '.+', 'X-Talk-Workspace')]),
            ['workspace' => 'other.example.org/path?x=1#y'],
        )->complete(Talk::request());

        expect($withHeader->requests()[0]->url)->toBe($plain->requests()[0]->url)
            ->and($withHeader->requests()[0]->method)->toBe($plain->requests()[0]->method)
            ->and($withHeader->requests()[0]->body)->toBe($plain->requests()[0]->body)
            ->and($withHeader->requests()[0]->secretHeaders['Authorization']->reveal())->toBe('Bearer key-of-the-test')
            ->and(array_diff_key($withHeader->requests()[0]->allHeaders(), $plain->requests()[0]->allHeaders()))->toBe(['X-Talk-Workspace' => 'other.example.org/path?x=1#y']);
    });
});
