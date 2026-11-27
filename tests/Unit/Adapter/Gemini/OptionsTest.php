<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Gemini\Options;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Preset\ModelOverride;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContent;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// The options of a preset on the generate-content adapter (docs/design/adapters.md, sections 2.3, 4.2, 6.1 and 9):
// its own two, and the capability options every adapter reads.

/**
 * The capabilities of a model as a list of words, in the order of the capability table.
 *
 * @return list<string|int|null>
 */
function generateCapabilityWords(Capabilities $can): array
{
    return [
        $can->toolCalling->value, $can->forcedToolCall->value, $can->parallelToolCalls->value, $can->structuredOutput->value,
        $can->toolsWithStructuredOutput->value, $can->streaming->value, $can->modelListing->value, $can->temperature->value, $can->contextLength,
    ];
}

describe('the capabilities of the generate-content adapter', function (): void {
    it('are the defaults of the format for a preset that sets nothing, also for a model nobody knows, and send nothing', function (): void {
        $transport = new FakeTransport();
        $can = GenerateContent::gateway($transport)->capabilities('a-model-nobody-knows');

        expect(generateCapabilityWords($can))->toBe(['native', 'native', 'native', 'native', 'emulated', 'native', 'native', 'native', null])
            ->and($transport->requests())->toBe([]);
    });

    it('are those of the two kinds of preset entry', function (): void {
        $key = GenerateContent::gateway(new FakeTransport(), GenerateContent::KEY_ENTRY)->capabilities(GenerateContent::MODEL);
        $token = GenerateContent::gateway(new FakeTransport(), GenerateContent::TOKEN_ENTRY)->capabilities(GenerateContent::MODEL);

        expect(generateCapabilityWords($key))->toBe(['native', 'native', 'native', 'native', 'emulated', 'native', 'native', 'native', null])
            ->and(generateCapabilityWords($token))->toBe(['native', 'native', 'native', 'native', 'emulated', 'native', 'none', 'native', null]);
    });

    it('follow every value of every capability option', function (string $option, string|int $value, int $at, string|int $word): void {
        $can = GenerateContent::gateway(new FakeTransport(), [$option => $value])->capabilities(GenerateContent::MODEL);

        expect(generateCapabilityWords($can)[$at])->toBe($word);
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
        ['context_length', 1000000, 8, 1000000],
    ]);

    it('follow a model override of the preset, for the models it names and no other', function (): void {
        $gateway = GenerateContent::gateway(new FakeTransport(), overrides: [new ModelOverride('example-3-*', ['tools_with_structured_output' => 'native', 'temperature' => 'not_accepted'])]);

        expect(generateCapabilityWords($gateway->capabilities('example-3-flash')))->toBe(['native', 'native', 'native', 'native', 'native', 'native', 'native', 'none', null])
            ->and(generateCapabilityWords($gateway->capabilities('example-2-flash')))->toBe(['native', 'native', 'native', 'native', 'emulated', 'native', 'native', 'native', null]);
    });
});

describe('the options of the generate-content adapter', function (): void {
    it('accepts every value of its own two options, and each changes what its name says', function (): void {
        $request = GenerateContent::request()->withResponseFormat(GenerateContent::trip());

        $default = GenerateContent::bodyFor($request);
        $jsonSchema = GenerateContent::bodyFor($request, ['structured_output_field' => 'response_json_schema']);
        $responseFormat = GenerateContent::bodyFor($request, ['structured_output_field' => 'response_format']);
        $omit = GenerateContent::bodyFor($request, ['store_field' => 'omit']);
        $false = GenerateContent::bodyFor($request, ['store_field' => 'false']);

        expect($jsonSchema)->toBe($default)
            ->and($omit)->toBe($default)
            ->and(array_keys($default['generationConfig']))->toBe(['responseMimeType', 'responseJsonSchema'])
            ->and(array_keys($responseFormat['generationConfig']))->toBe(['responseFormat'])
            ->and($default)->not->toHaveKey('store')
            ->and($false['store'])->toBeFalse();
    });

    it('reads its own options for the model of the request, so a model override can set them', function (): void {
        $overrides = [new ModelOverride('example-4-*', ['structured_output_field' => 'response_format', 'store_field' => 'false'])];
        $request = fn(string $model) => new OrganiseYou\LlmGateway\Contract\Request($model, [OrganiseYou\LlmGateway\Contract\Message::user('Hi.')], 30.0, responseFormat: GenerateContent::trip());

        $named = GenerateContent::bodyFor($request('example-4-pro'), overrides: $overrides);
        $other = GenerateContent::bodyFor($request('example-3-pro'), overrides: $overrides);

        expect(array_keys($named['generationConfig']))->toBe(['responseFormat'])
            ->and($named['store'])->toBeFalse()
            ->and(array_keys($other['generationConfig']))->toBe(['responseMimeType', 'responseJsonSchema'])
            ->and($other)->not->toHaveKey('store');
    });

    it('fails as not configured for an option with a value outside its list, whatever is asked, and sends nothing', function (array $options, string $name): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered());
        $gateway = GenerateContent::gateway($transport, $options);

        $errors = [
            Talk::errorOf(fn() => $gateway->capabilities(GenerateContent::MODEL)),
            Talk::errorOf(fn() => $gateway->complete(GenerateContent::request())),
            Talk::failedIn(Talk::events($gateway->stream(GenerateContent::request())))->error,
        ];
        foreach ($errors as $error) {
            expect($error->reason)->toBe(ErrorReason::NotConfigured)
                ->and($error->detail)->toBe('the option ' . $name . ' of the preset has a value outside its list')
                ->and($error->attempts)->toBe(0);
        }
        expect($transport->requests())->toBe([]);
    })->with([
        'the schema field, another word' => [['structured_output_field' => 'response_schema'], 'structured_output_field'],
        'the schema field, the name of the field itself' => [['structured_output_field' => 'responseJsonSchema'], 'structured_output_field'],
        'the schema field, a boolean' => [['structured_output_field' => true], 'structured_output_field'],
        'the schema field, an empty word' => [['structured_output_field' => ''], 'structured_output_field'],
        'the store field, the boolean false' => [['store_field' => false], 'store_field'],
        'the store field, true' => [['store_field' => 'true'], 'store_field'],
        'the store field, a number' => [['store_field' => 0], 'store_field'],
        'the store field, another case' => [['store_field' => 'False'], 'store_field'],
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
        $error = Talk::errorOf(fn() => GenerateContent::gateway($transport, $options)->models(10.0));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the option ' . $name . ' of the preset has a value outside its list')
            ->and($transport->requests())->toBe([]);
    })->with([
        [['structured_output_field' => 'other'], 'structured_output_field'],
        [['store_field' => 'no'], 'store_field'],
        [['model_listing' => 'emulated'], 'model_listing'],
    ]);

    it('fails as not configured for an option whose name it does not know, whatever is asked, without repeating the name', function (string $name): void {
        $transport = new FakeTransport();
        $gateway = GenerateContent::gateway($transport, [$name => 'x']);

        $errors = [
            Talk::errorOf(fn() => $gateway->capabilities(GenerateContent::MODEL)),
            Talk::errorOf(fn() => $gateway->complete(GenerateContent::request())),
            Talk::failedIn(Talk::events($gateway->stream(GenerateContent::request())))->error,
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
        'a name written wrongly' => ['store_feild'],
        'the name of a field of the format' => ['safetySettings'],
        'another case' => ['Store_Field'],
    ]);

    it('refuses the same in the options value itself', function (): void {
        expect(fn() => new Options(['store_field' => 'yes']))->toThrow(InvalidValue::class)
            ->and(fn() => new Options(['other' => 1]))->toThrow(InvalidValue::class)
            ->and((new Options([]))->sendsStoreFalse)->toBeFalse()
            ->and((new Options([]))->schemaInResponseFormat)->toBeFalse()
            ->and((new Options(['store_field' => 'false', 'structured_output_field' => 'response_format', 'streaming' => 'emulated']))->sendsStoreFalse)->toBeTrue()
            ->and(Options::optionOutsideItsList(['structured_output_field' => 'x', 'store_field' => 'y']))->toBe('structured_output_field')
            ->and(Options::hasAnUnknownOption(['context_length' => 5, 'store_field' => 'omit']))->toBeFalse();
    });

    it('gives the whole answer as the events of a stream where the preset says the server does not stream, with one request to the plain method', function (): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered());
        $events = Talk::events(GenerateContent::gateway($transport, ['streaming' => 'emulated'])->stream(GenerateContent::request()));

        expect(Talk::finishedIn($events)->answer->text)->toBe('Hello.')
            ->and($transport->requests()[0]->url)->toBe(GenerateContent::BASE_URL . '/models/example-generate-1:generateContent');
    });

    it('refuses a temperature before anything is sent where the preset says the model takes none, and sends it where it does', function (): void {
        $transport = new FakeTransport();
        $request = new OrganiseYou\LlmGateway\Contract\Request(GenerateContent::MODEL, [OrganiseYou\LlmGateway\Contract\Message::user('Hi.')], 30.0, temperature: 0.5);
        $error = Talk::errorOf(fn() => GenerateContent::gateway($transport, ['temperature' => 'not_accepted'])->complete($request));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($transport->requests())->toBe([])
            ->and(GenerateContent::bodyFor($request)['generationConfig'])->toBe(['temperature' => 0.5]);
    });
});
