<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Anthropic\OutputSchema;
use OrganiseYou\LlmGateway\Adapter\Shared\SchemaShape;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\StructuredIssueKind;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// Structured output in the Messages adapter (docs/design/adapters.md, section 5.9, row A2): the native mode gets
// the caller's schema or a looser copy of it, a schema the mode cannot take goes into the system text for that one
// request, and the answer is always checked against the caller's whole schema.

/** A trip whose days have a least and a most: two keywords the native mode refuses. */
const TRIP_WITH_LIMITS = '{"type":"object","properties":{"city":{"type":"string","minLength":2},"days":{"type":"integer","minimum":1,"maximum":30}},"required":["city","days"],"additionalProperties":false}';

/** A place that holds places: a schema that refers back to itself. */
const PLACE_IN_PLACES = '{"type":"object","properties":{"city":{"type":"string"},"inside":{"$ref":"#"}},"required":["city"],"additionalProperties":false}';

/**
 * What the adapter sends for a request with this response format, and the answer it gives when the server says
 * this text: [the body, the answer].
 *
 * @param array<string, bool|int|string> $options
 * @return array{array<string, mixed>, Answer}
 */
function messagesStructured(string $schema, string $says, array $options = Messages::CURRENT_VERSION, bool $withTools = false, bool $asStream = false): array
{
    $transport = new FakeTransport();
    $request = new Request(
        Messages::MODEL,
        [Message::system('You are a travel desk.'), Message::user('Plan a trip.')],
        30.0,
        $withTools ? [Messages::lookup()] : [],
        responseFormat: new ResponseFormat(JsonSchema::fromJson($schema), 'trip', 'A trip to one city.'),
    );
    $gateway = Messages::gateway($transport, $options);

    if ($asStream) {
        $transport->willStream(200, [], ...Messages::saying(substr($says, 0, 5), substr($says, 5)));
        $answer = Talk::finishedIn(Talk::events($gateway->stream($request)))->answer;
    } else {
        $transport->willReturn(Messages::answered([Messages::text($says)]));
        $answer = $gateway->complete($request);
    }

    return [Messages::sent($transport), $answer];
}

describe('a schema with a constraint keyword the native mode refuses', function (): void {
    it('is sent without that keyword, and the answer is still checked against the whole schema of the caller', function (bool $asStream): void {
        // Days below the least the caller asked for: the copy that was sent allows it, the caller's schema does not.
        [$body, $answer] = messagesStructured(TRIP_WITH_LIMITS, '{"city":"Paris","days":0}', asStream: $asStream);

        expect($body['output_config'])->toBe(['format' => ['type' => 'json_schema', 'schema' => [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string'], 'days' => ['type' => 'integer']],
            'required' => ['city', 'days'],
            'additionalProperties' => false,
        ]]])
            ->and($body['system'])->toBe('You are a travel desk.')
            ->and($answer->structured)->not->toBeNull()
            ->and($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->data)->toBe(['city' => 'Paris', 'days' => 0])
            ->and($answer->structured?->issues[0]->kind)->toBe(StructuredIssueKind::SchemaMismatch)
            ->and($answer->structured?->issues[0]->path)->toBe('/days');
    })->with(['through complete()' => [false], 'through stream()' => [true]]);

    it('gives a usable result for an answer that keeps to the whole schema', function (): void {
        [, $answer] = messagesStructured(TRIP_WITH_LIMITS, '{"city":"Paris","days":3}');

        expect($answer->structured?->isUsable())->toBeTrue()
            ->and($answer->structured?->data)->toBe(['city' => 'Paris', 'days' => 3]);
    });

    it('checks every keyword that was taken out', function (string $says, string $path): void {
        [, $answer] = messagesStructured(TRIP_WITH_LIMITS, $says);

        expect($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->issues[0]->path)->toBe($path);
    })->with([
        'a text that is too short' => ['{"city":"P","days":3}', '/city'],
        'a number that is too large' => ['{"city":"Paris","days":31}', '/days'],
    ]);

    it('never changes the schema of the caller', function (): void {
        $format = Messages::trip(TRIP_WITH_LIMITS);
        $before = $format->schema->json;
        $transport = (new FakeTransport())->willReturn(Messages::answered([Messages::text('{"city":"Paris","days":3}')]));

        Messages::gateway($transport)->complete(new Request(Messages::MODEL, [Message::user('Plan a trip.')], 30.0, responseFormat: $format));

        expect($format->schema->json)->toBe($before)
            ->and($before)->toContain('"minimum":1');
    });
});

describe('a schema the native mode cannot take', function (): void {
    it('takes the emulated route when it is recursive: the schema goes into the system text and no format is sent', function (bool $asStream): void {
        [$body, $answer] = messagesStructured(PLACE_IN_PLACES, '{"city":"Paris","inside":{"city":"Montmartre"}}', asStream: $asStream);

        expect(array_key_exists('output_config', $body))->toBeFalse()
            ->and($body['system'])->toStartWith("You are a travel desk.\n\nAnswer with exactly one JSON object")
            ->and($body['system'])->toContain('A trip to one city.')
            ->and($body['system'])->toEndWith("JSON Schema:\n" . PLACE_IN_PLACES)
            ->and($answer->structured?->isUsable())->toBeTrue()
            ->and($answer->structured?->data)->toBe(['city' => 'Paris', 'inside' => ['city' => 'Montmartre']]);
    })->with(['through complete()' => [false], 'through stream()' => [true]]);

    it('still checks the answer of the emulated route against the schema of the caller', function (): void {
        [, $answer] = messagesStructured(PLACE_IN_PLACES, '{"city":"Paris","inside":{"town":"Montmartre"}}');

        expect($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->issues[0]->kind)->toBe(StructuredIssueKind::SchemaMismatch);
    });

    it('keeps the capability at native: the emulated route is for that one request', function (): void {
        $gateway = Messages::gateway(new FakeTransport(), Messages::CURRENT_VERSION);

        expect($gateway->capabilities(Messages::MODEL)->structuredOutput->value)->toBe('native');
    });

    it('takes the emulated route for every shape the mode refuses, and the native route otherwise', function (string $schema, bool $native): void {
        [$body] = messagesStructured($schema, '{}');

        expect(array_key_exists('output_config', $body))->toBe($native)
            ->and(str_contains($body['system'], 'JSON Schema:'))->toBe(! $native);
    })->with([
        'a closed object' => ['{"type":"object","properties":{"city":{"type":"string"}},"additionalProperties":false}', true],
        'an object that is not closed' => ['{"type":"object","properties":{"city":{"type":"string"}}}', false],
        'an object closed with a schema, not with false' => ['{"type":"object","properties":{"city":{"type":"string"}},"additionalProperties":{"type":"string"}}', false],
        'a nested object that is not closed' => ['{"type":"object","properties":{"where":{"type":"object","properties":{"city":{"type":"string"}}}},"additionalProperties":false}', false],
        'a ring of definitions' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/a"}},"additionalProperties":false,"$defs":{"a":{"type":"object","properties":{"b":{"$ref":"#/$defs/a"}},"additionalProperties":false}}}', false],
        'definitions without a ring' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/city"}},"additionalProperties":false,"$defs":{"city":{"type":"string"}}}', true],
        'a reference to another document' => ['{"type":"object","properties":{"a":{"$ref":"https://schemas.example.test/city.json"}},"additionalProperties":false}', false],
        'a reference to a file' => ['{"type":"object","properties":{"a":{"$ref":"city.json#/city"}},"additionalProperties":false}', false],
        'an enum with a value that is an object' => ['{"type":"object","properties":{"a":{"enum":["x",{"y":1}]}},"additionalProperties":false}', false],
        'an enum with a value that is a list' => ['{"type":"object","properties":{"a":{"enum":[["x"]]}},"additionalProperties":false}', false],
        'an enum of scalars' => ['{"type":"object","properties":{"a":{"enum":["x",1,true,null]}},"additionalProperties":false}', true],
        'a simple pattern' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"^[A-Z][a-z]+$"}},"additionalProperties":false}', true],
        'a pattern with a lookahead' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"^(?=.*[0-9]).+$"}},"additionalProperties":false}', false],
        'a pattern with a negative lookbehind' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"(?<!a)b"}},"additionalProperties":false}', false],
        'a pattern with a backreference' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"^(a|b)\\\\1$"}},"additionalProperties":false}', false],
        'a pattern with a named backreference' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"^(?<q>a|b)\\\\k<q>$"}},"additionalProperties":false}', false],
        'a pattern with a named backreference in the other spelling' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"^(?P<q>a|b)(?P=q)$"}},"additionalProperties":false}', false],
        'a pattern with a backreference written with g' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"^(a|b)\\\\g{1}$"}},"additionalProperties":false}', false],
        'a pattern with a named group and no reference back to it' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"^(?<q>a|b)[kg]+$"}},"additionalProperties":false}', true],
        'a refused keyword under if, where taking it out would tighten' => ['{"type":"object","properties":{"n":{"type":"integer","if":{"minimum":5},"then":{"multipleOf":5}}},"additionalProperties":false}', false],
        'oneOf beside anyOf, which cannot be written as one anyOf' => ['{"type":"object","properties":{"n":{"oneOf":[{"type":"string"},{"type":"integer"}],"anyOf":[{"type":"string"},{"type":"integer"}]}},"additionalProperties":false}', false],
    ]);
});

describe('the copy of a schema that is sent', function (): void {
    it('is the schema of the caller, byte for byte, when it uses nothing the mode refuses', function (string $schema): void {
        expect(OutputSchema::forTheNativeMode(JsonSchema::fromJson($schema))?->json)->toBe($schema);
    })->with([
        'the plain trip' => ['{"type":"object","properties":{"city":{"type":"string"},"days":{"type":"integer"}},"required":["city","days"],"additionalProperties":false}'],
        'every accepted keyword' => ['{"type":"object","properties":{"a":{"anyOf":[{"type":"string","format":"date"},{"type":"null"}]},"b":{"allOf":[{"$ref":"#/$defs/n"}]},"c":{"const":"x","default":"x"},"d":{"type":"array","items":{"type":"number"},"minItems":1},"e":{"type":"array","items":{"type":"boolean"},"minItems":0}},"required":["a"],"additionalProperties":false,"$defs":{"n":{"type":"integer"}},"definitions":{}}'],
        'properties that are named like refused keywords' => ['{"type":"object","properties":{"minimum":{"type":"integer"},"not":{"type":"string"},"oneOf":{"type":"string"},"maxItems":{"type":"integer"}},"additionalProperties":false}'],
        'refused keywords as data, not as keywords' => ['{"type":"object","properties":{"a":{"enum":["minimum","not"],"default":"minimum"},"b":{"const":"oneOf","examples":["maxLength"]}},"additionalProperties":false}'],
    ]);

    it('loses every constraint keyword the mode refuses, and nothing else', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","description":"A trip.","properties":{"days":{"type":"integer","minimum":1,"maximum":30,"multipleOf":1,"description":"How long."},"city":{"type":"string","minLength":1,"maxLength":80,"pattern":"^[A-Z]"},"tags":{"type":"array","items":{"type":"string","maxLength":9},"maxItems":5,"uniqueItems":true},"minimum":{"type":"number","exclusiveMinimum":0}},"required":["city"],"additionalProperties":false}');

        expect(OutputSchema::forTheNativeMode($schema)?->json)->toBe(
            '{"type":"object","description":"A trip.","properties":{"days":{"type":"integer","description":"How long."},"city":{"type":"string","pattern":"^[A-Z]"},"tags":{"type":"array","items":{"type":"string"}},"minimum":{"type":"number","exclusiveMinimum":0}},"required":["city"],"additionalProperties":false}',
        );
    });

    it('keeps a least number of items of 0 or 1, and takes the keyword out everywhere when one value is another', function (string $schema, string $sent): void {
        expect(OutputSchema::forTheNativeMode(JsonSchema::fromJson($schema))?->json)->toBe($sent);
    })->with([
        '0 and 1' => [
            '{"type":"object","properties":{"a":{"type":"array","minItems":0},"b":{"type":"array","minItems":1}},"additionalProperties":false}',
            '{"type":"object","properties":{"a":{"type":"array","minItems":0},"b":{"type":"array","minItems":1}},"additionalProperties":false}',
        ],
        '1 and 2' => [
            '{"type":"object","properties":{"a":{"type":"array","minItems":1},"b":{"type":"array","minItems":2}},"additionalProperties":false}',
            '{"type":"object","properties":{"a":{"type":"array"},"b":{"type":"array"}},"additionalProperties":false}',
        ],
        'a value that is no whole number' => [
            '{"type":"object","properties":{"a":{"type":"array","minItems":"1"}},"additionalProperties":false}',
            '{"type":"object","properties":{"a":{"type":"array"}},"additionalProperties":false}',
        ],
    ]);

    it('writes oneOf as anyOf, and takes not out with everything under it, in one step with the constraint keywords', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"v":{"oneOf":[{"type":"string","minLength":3},{"type":"integer","minimum":0}]},"n":{"type":"integer","not":{"minimum":5,"const":7}}},"additionalProperties":false}');
        $sent = OutputSchema::forTheNativeMode($schema);

        expect($sent?->json)->toBe('{"type":"object","properties":{"v":{"anyOf":[{"type":"string"},{"type":"integer"}]},"n":{"type":"integer"}},"additionalProperties":false}')
            // What the helper said of the caller's schema: with not and oneOf among the keywords, nothing tightens.
            ->and((new SchemaShape())->mayTighten($schema, ['minLength', 'minimum', 'not', 'oneOf']))->toBeFalse()
            // Without not among them it would: the least under not would be taken out and not would stay.
            ->and((new SchemaShape())->mayTighten($schema, ['minLength', 'minimum', 'oneOf']))->toBeTrue();
    });

    it('is never sent when it could refuse an answer the schema of the caller allows', function (string $schema): void {
        expect(OutputSchema::forTheNativeMode(JsonSchema::fromJson($schema)))->toBeNull();
    })->with([
        'a refused keyword under if' => ['{"type":"object","properties":{"n":{"if":{"minimum":5},"then":{"type":"integer"}}},"additionalProperties":false}'],
        'a refused keyword under contains beside maxContains' => ['{"type":"object","properties":{"n":{"type":"array","contains":{"minimum":5},"maxContains":1}},"additionalProperties":false}'],
        'oneOf beside anyOf' => ['{"type":"object","properties":{"n":{"oneOf":[{"type":"string"}],"anyOf":[{"type":"string"}]}},"additionalProperties":false}'],
        'a reference into a schema under not' => ['{"type":"object","properties":{"a":{"not":{"$defs":{"x":{"type":"string"}}}},"b":{"$ref":"#/properties/a/not/$defs/x"}},"additionalProperties":false}'],
    ]);

    it('asks the shape rules of the copy, not of the schema of the caller', function (): void {
        // The object under not is open and refers back; with not taken out, the copy is closed and has no ring.
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"a":{"type":"string","not":{"type":"object","properties":{"again":{"$ref":"#"}}}}},"additionalProperties":false}');

        expect((new SchemaShape())->isRecursive($schema))->toBeTrue()
            ->and((new SchemaShape())->everyObjectIsClosed($schema))->toBeFalse()
            ->and(OutputSchema::forTheNativeMode($schema)?->json)->toBe('{"type":"object","properties":{"a":{"type":"string"}},"additionalProperties":false}');
    });
});

describe('a response format beside the other fields of a request', function (): void {
    it('is sent with the tools in one request, where the server takes both', function (): void {
        [$body, $answer] = messagesStructured(TRIP_WITH_LIMITS, '{"city":"Paris","days":3}', withTools: true);

        expect(array_keys($body))->toBe(['model', 'max_tokens', 'system', 'messages', 'tools', 'output_config'])
            ->and($body['system'])->toBe('You are a travel desk.')
            ->and($answer->structured?->isUsable())->toBeTrue();
    });

    it('goes into the system text beside tools, and the tools are sent, where the server does not take both', function (): void {
        [$body, $answer] = messagesStructured(TRIP_WITH_LIMITS, '{"city":"Paris","days":0}', [...Messages::CURRENT_VERSION, 'tools_with_structured_output' => 'emulated'], withTools: true);
        [$alone] = messagesStructured(TRIP_WITH_LIMITS, '{}', [...Messages::CURRENT_VERSION, 'tools_with_structured_output' => 'emulated']);

        expect(array_keys($body))->toBe(['model', 'max_tokens', 'system', 'messages', 'tools'])
            ->and($body['system'])->toEndWith("JSON Schema:\n" . TRIP_WITH_LIMITS)
            ->and($answer->structured?->isUsable())->toBeFalse()
            // Without tools the same server gets the schema as a field.
            ->and(array_key_exists('output_config', $alone))->toBeTrue();
    });

    it('goes into the system text where the preset says structured output is emulated, with the whole schema', function (): void {
        [$body, $answer] = messagesStructured(TRIP_WITH_LIMITS, "```json\n{\"city\":\"Paris\",\"days\":3}\n```", [...Messages::CURRENT_VERSION, 'structured_output' => 'emulated']);

        expect(array_key_exists('output_config', $body))->toBeFalse()
            ->and($body['system'])->toEndWith("JSON Schema:\n" . TRIP_WITH_LIMITS)
            ->and($answer->structured?->isUsable())->toBeTrue();
    });

    it('is the only system text when the request has no system message', function (): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered([Messages::text('{"city":"Paris"}')]));
        Messages::gateway($transport)->complete(new Request(Messages::MODEL, [Message::user('Plan a trip.')], 30.0, responseFormat: Messages::trip(PLACE_IN_PLACES)));

        expect(Messages::sent($transport)['system'])->toStartWith('Answer with exactly one JSON object');
    });

    it('sends neither the name nor the description of the format on the native route: the format has no member for them', function (): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered([Messages::text('{"city":"Paris","days":3}')]));
        Messages::gateway($transport)->complete(new Request(Messages::MODEL, [Message::user('Plan a trip.')], 30.0, responseFormat: Messages::trip()));

        expect(str_contains($transport->requests()[0]->body, 'A trip to one city.'))->toBeFalse()
            ->and(str_contains($transport->requests()[0]->body, '"trip"'))->toBeFalse();
    });

    it('gives no structured result for an answer that asks for a tool call', function (): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered([Messages::call()], 'tool_use'));
        $answer = Messages::gateway($transport)->complete(new Request(Messages::MODEL, [Message::user('Plan a trip.')], 30.0, [Messages::lookup()], responseFormat: Messages::trip()));

        expect($answer->structured)->toBeNull()
            ->and($answer->toolCalls)->toHaveCount(1);
    });

    it('says "cut off" for a structured answer that ended at the token limit, and "not JSON" for a refusal', function (): void {
        $request = new Request(Messages::MODEL, [Message::user('Plan a trip.')], 30.0, responseFormat: Messages::trip());
        $cutOff = Messages::answerTo(Messages::answered([Messages::text('{"city":"Par')], 'max_tokens'), request: $request);
        $refused = Messages::answerTo(Messages::answered([Messages::text('I cannot help with that.')], 'refusal'), request: $request);

        expect($cutOff->structured?->issues[0]->kind)->toBe(StructuredIssueKind::CutOff)
            ->and($refused->structured?->isUsable())->toBeFalse()
            ->and($refused->structured?->issues[0]->kind)->toBe(StructuredIssueKind::NotJson);
    });
});
