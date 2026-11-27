<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\StructuredIssueKind;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContent;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// Structured output in the generate-content adapter (docs/design/adapters.md, sections 4.2 and 5.9, row A3;
// decision G74): the caller's schema goes out as it is, in the field the preset names, and the answer is always
// checked against that schema in full by the library, whatever the provider did with it.

/** A schema with keywords the provider file says the provider ignores, and with keywords it does not list. */
const GENERATE_FULL_SCHEMA = '{"type":"object","properties":{"city":{"type":"string","minLength":3,"maxLength":20,"pattern":"^[A-Z]"},"days":{"type":"integer","minimum":1,"maximum":30,"multipleOf":1},"tags":{"type":"array","items":{"type":"string"},"uniqueItems":true,"minItems":0},"kind":{"oneOf":[{"const":"short"},{"const":"long"}]}},"required":["city","days"],"additionalProperties":false,"not":{"required":["nothing"]}}';

describe('the schema of a response format in a request of the generate-content adapter', function (): void {
    it('goes out as plain JSON Schema with the JSON mime type, by default', function (): void {
        $body = GenerateContent::bodyFor(GenerateContent::request()->withResponseFormat(GenerateContent::trip()));

        expect($body['generationConfig'])->toBe([
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string'], 'days' => ['type' => 'integer']],
                'required' => ['city', 'days'],
                'additionalProperties' => false,
            ],
        ])
            ->and($body)->not->toHaveKey('systemInstruction');
    });

    it('goes out under the text of a response format where the preset names that field', function (): void {
        $body = GenerateContent::bodyFor(GenerateContent::request()->withResponseFormat(GenerateContent::trip()), ['structured_output_field' => 'response_format']);

        expect($body['generationConfig'])->toBe([
            'responseFormat' => ['text' => [
                'mimeType' => 'APPLICATION_JSON',
                'schema' => [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string'], 'days' => ['type' => 'integer']],
                    'required' => ['city', 'days'],
                    'additionalProperties' => false,
                ],
            ]],
        ]);
    });

    it('never goes out in the third form the pages show, and neither the name nor the description of the format is sent as a field', function (array $options): void {
        $transport = (new OrganiseYou\LlmGateway\Testing\FakeTransport())->willReturn(GenerateContent::answered());
        GenerateContent::gateway($transport, $options)->complete(GenerateContent::request()->withResponseFormat(GenerateContent::trip()));
        $sent = $transport->requests()[0]->body;

        expect($sent)->not->toContain('responseSchema')
            ->and($sent)->not->toContain('"trip"')
            ->and($sent)->not->toContain('A trip to one city.');
    })->with([[[]], [['structured_output_field' => 'response_format']]]);

    it('is the schema of the caller as it is: nothing is taken out, nothing is rewritten, and no request leaves the native mode for its keywords', function (array $options, string $schema): void {
        $body = GenerateContent::bodyFor(GenerateContent::request()->withResponseFormat(GenerateContent::trip($schema)), $options);
        $sent = $body['generationConfig']['responseJsonSchema'] ?? $body['generationConfig']['responseFormat']['text']['schema'];

        expect($sent)->toBe(json_decode($schema, true))
            ->and($body)->not->toHaveKey('systemInstruction');
    })->with([
        'keywords that only narrow' => [[], GENERATE_FULL_SCHEMA],
        'the same, in the other field' => [['structured_output_field' => 'response_format'], GENERATE_FULL_SCHEMA],
        'a recursive schema' => [[], '{"type":"object","properties":{"name":{"type":"string"},"children":{"type":"array","items":{"$ref":"#"}}},"required":["name"]}'],
        'definitions and references' => [[], '{"type":"object","properties":{"city":{"$ref":"#/$defs/city"}},"$defs":{"city":{"type":"string"}}}'],
        'an object that is not closed' => [[], '{"type":"object","properties":{"city":{"type":"string"}}}'],
    ]);

    it('goes into the system text, with the tools sent, when the request has tools and the preset does not say both go together', function (array $options): void {
        $request = GenerateContent::request(OrganiseYou\LlmGateway\Contract\Message::system('Be brief.'), OrganiseYou\LlmGateway\Contract\Message::user('Where to?'))
            ->withTools([GenerateContent::lookup()])
            ->withResponseFormat(GenerateContent::trip());
        $body = GenerateContent::bodyFor($request, $options);

        expect($body)->not->toHaveKey('generationConfig')
            ->and($body['tools'][0]['functionDeclarations'][0]['name'])->toBe('lookup')
            ->and($body['toolConfig'])->toBe(['functionCallingConfig' => ['mode' => 'AUTO']])
            ->and($body['systemInstruction']['parts'][0]['text'])->toStartWith("Be brief.\n\n")
            ->and($body['systemInstruction']['parts'][0]['text'])->toContain('"additionalProperties":false');
    })->with([
        'the default of the format' => [[]],
        'the entry with a key' => [GenerateContent::KEY_ENTRY],
        'the entry with a token' => [GenerateContent::TOKEN_ENTRY],
        'the other schema field' => [['structured_output_field' => 'response_format']],
    ]);

    it('goes out as a field beside the tools where the preset says both go together, and then leaves the mode of the tools to the default of the format', function (): void {
        $request = GenerateContent::request()->withTools([GenerateContent::lookup()])->withResponseFormat(GenerateContent::trip());
        $body = GenerateContent::bodyFor($request, ['tools_with_structured_output' => 'native']);
        $forced = GenerateContent::bodyFor($request->withToolChoice(OrganiseYou\LlmGateway\Contract\ToolChoice::required()), ['tools_with_structured_output' => 'native']);

        expect(array_keys($body))->toBe(['contents', 'tools', 'generationConfig'])
            ->and($body['generationConfig']['responseMimeType'])->toBe('application/json')
            ->and($forced['toolConfig'])->toBe(['functionCallingConfig' => ['mode' => 'ANY']]);
    });

    it('goes into the system text for every request where the preset says structured output is emulated', function (): void {
        $body = GenerateContent::bodyFor(GenerateContent::request()->withResponseFormat(GenerateContent::trip()), ['structured_output' => 'emulated']);

        expect($body)->not->toHaveKey('generationConfig')
            ->and($body['systemInstruction']['parts'][0]['text'])->toContain('"additionalProperties":false');
    });
});

describe('the answer to a request with a response format, in the generate-content adapter', function (): void {
    it('is still checked against the full schema of the caller, with the keywords the provider ignores', function (string $text, bool $usable, ?string $path): void {
        $request = GenerateContent::request()->withResponseFormat(GenerateContent::trip(GENERATE_FULL_SCHEMA));
        $whole = GenerateContent::answerTo(GenerateContent::answered([GenerateContent::text($text)]), request: $request);
        $pieces = str_split($text, 9);
        $streamed = Talk::finishedIn(GenerateContent::eventsOf(GenerateContent::saying(...$pieces), request: $request))->answer;

        foreach ([$whole, $streamed] as $answer) {
            expect($answer->structured)->not->toBeNull();
            assert($answer->structured !== null);
            expect($answer->structured->isUsable())->toBe($usable)
                ->and($answer->text)->toBe($text);
            if (! $usable) {
                expect($answer->structured->issues[0]->kind)->toBe(StructuredIssueKind::SchemaMismatch)
                    ->and($answer->structured->issues[0]->path)->toBe($path);
            }
        }
    })->with([
        'an answer that fits' => ['{"city":"Paris","days":3,"tags":["a","b"],"kind":"short"}', true, null],
        'a text shorter than the minimum' => ['{"city":"Pa","days":3}', false, '/city'],
        'a text that does not fit the pattern' => ['{"city":"paris","days":3}', false, '/city'],
        'a number above the maximum' => ['{"city":"Paris","days":31}', false, '/days'],
        'a list with the same item twice' => ['{"city":"Paris","days":3,"tags":["a","a"]}', false, '/tags'],
        'a value outside the union' => ['{"city":"Paris","days":3,"kind":"medium"}', false, '/kind'],
        'a property the schema does not allow' => ['{"city":"Paris","days":3,"more":1}', false, '/'],
        'a missing property' => ['{"city":"Paris"}', false, '/'],
    ]);

    it('is checked the same on the emulated route and with the other schema field', function (array $options): void {
        $request = GenerateContent::request()->withResponseFormat(GenerateContent::trip(GENERATE_FULL_SCHEMA));
        $good = GenerateContent::answerTo(GenerateContent::answered([GenerateContent::text('{"city":"Paris","days":3}')]), $options, $request);
        $bad = GenerateContent::answerTo(GenerateContent::answered([GenerateContent::text('{"city":"Pa","days":3}')]), $options, $request);

        expect($good->structured?->isUsable())->toBeTrue()
            ->and($good->structured?->data)->toBe(['city' => 'Paris', 'days' => 3])
            ->and($bad->structured?->isUsable())->toBeFalse()
            ->and($bad->structured?->data)->toBe(['city' => 'Pa', 'days' => 3]);
    })->with([
        'emulated' => [['structured_output' => 'emulated']],
        'the other field' => [['structured_output_field' => 'response_format']],
    ]);

    it('is returned with its reasons, never thrown, when it is no JSON, was cut off or was withheld', function (array $parts, string $finish, StructuredIssueKind $kind, FinishReason $reason): void {
        $request = GenerateContent::request()->withResponseFormat(GenerateContent::trip());
        $answer = GenerateContent::answerTo(GenerateContent::answered(array_values($parts), $finish), request: $request);

        expect($answer->finishReason)->toBe($reason)
            ->and($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->issues[0]->kind)->toBe($kind);
    })->with([
        'no JSON' => [[GenerateContent::text('I would say Paris.')], 'STOP', StructuredIssueKind::NotJson, FinishReason::Stop],
        'cut off' => [[GenerateContent::text('{"city":"Par')], 'MAX_TOKENS', StructuredIssueKind::CutOff, FinishReason::Length],
        'cut off before any text' => [[], 'FINISH_REASON_MAX_TOKENS', StructuredIssueKind::CutOff, FinishReason::Length],
    ]);

    it('has no structured result when the answer holds a tool call', function (): void {
        $request = GenerateContent::request()->withTools([GenerateContent::lookup()])->withResponseFormat(GenerateContent::trip());
        $answer = GenerateContent::answerTo(GenerateContent::answered([GenerateContent::call()]), request: $request);

        expect($answer->structured)->toBeNull()
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls);
    });
});
