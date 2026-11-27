<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Cohere\OutputSchema;
use OrganiseYou\LlmGateway\Adapter\Shared\SchemaShape;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\StructuredIssueKind;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatV2;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// Structured output in the Chat v2 adapter (docs/design/adapters.md, section 5.9, row A4): the native mode gets
// the caller's schema or a looser copy of it, a schema the mode cannot take goes into the system text for that one
// request, tools beside a response format always do, and the answer is always checked against the caller's whole
// schema.

/** A trip whose days have a least and a most: two keywords the native mode refuses. */
const CHAT_V2_TRIP_WITH_LIMITS = '{"type":"object","properties":{"city":{"type":"string","minLength":2},"days":{"type":"integer","minimum":1,"maximum":30}},"required":["city","days"],"additionalProperties":false}';

/** A trip that is put together from two schemas: a keyword the native mode refuses and that cannot be taken out. */
const CHAT_V2_TRIP_OF_TWO = '{"type":"object","allOf":[{"properties":{"city":{"type":"string"}}},{"properties":{"days":{"type":"integer"}}}],"required":["city","days"]}';

/**
 * What the adapter sends for a request with this response format, and the answer it gives when the server says
 * this text: [the body, the answer].
 *
 * @param array<string, bool|int|string> $options
 * @return array{array<string, mixed>, Answer}
 */
function chatV2Structured(string $schema, string $says, array $options = [], bool $withTools = false, bool $asStream = false): array
{
    $transport = new FakeTransport();
    $request = new Request(
        ChatV2::MODEL,
        [Message::system('You are a travel desk.'), Message::user('Plan a trip.')],
        30.0,
        $withTools ? [ChatV2::lookup()] : [],
        responseFormat: new ResponseFormat(JsonSchema::fromJson($schema), 'trip', 'A trip to one city.'),
    );
    $gateway = ChatV2::gateway($transport, $options);

    if ($asStream) {
        $transport->willStream(200, [], ...ChatV2::saying(substr($says, 0, 5), substr($says, 5)));
        $answer = Talk::finishedIn(Talk::events($gateway->stream($request)))->answer;
    } else {
        $transport->willReturn(ChatV2::answered([ChatV2::text($says)]));
        $answer = $gateway->complete($request);
    }

    return [ChatV2::sent($transport), $answer];
}

/**
 * The system texts of a body, in their order.
 *
 * @param array<string, mixed> $body
 * @return list<string>
 */
function chatV2SystemTexts(array $body): array
{
    $texts = [];
    foreach ($body['messages'] as $message) {
        if ($message['role'] === 'system') {
            $texts[] = $message['content'];
        }
    }

    return $texts;
}

describe('a schema with a constraint keyword the native mode of the Chat v2 adapter refuses', function (): void {
    it('is sent without that keyword, and the answer is still checked against the whole schema of the caller', function (bool $asStream): void {
        // Days below the least the caller asked for: the copy that was sent allows it, the caller's schema does not.
        [$body, $answer] = chatV2Structured(CHAT_V2_TRIP_WITH_LIMITS, '{"city":"Paris","days":0}', asStream: $asStream);

        expect($body['response_format'])->toBe(['type' => 'json_object', 'json_schema' => [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string'], 'days' => ['type' => 'integer']],
            'required' => ['city', 'days'],
            'additionalProperties' => false,
        ]])
            ->and(chatV2SystemTexts($body))->toBe(['You are a travel desk.'])
            ->and($answer->structured)->not->toBeNull()
            ->and($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->data)->toBe(['city' => 'Paris', 'days' => 0])
            ->and($answer->structured?->issues[0]->kind)->toBe(StructuredIssueKind::SchemaMismatch)
            ->and($answer->structured?->issues[0]->path)->toBe('/days');
    })->with(['through complete()' => [false], 'through stream()' => [true]]);

    it('gives a usable result for an answer that keeps to the whole schema', function (): void {
        [, $answer] = chatV2Structured(CHAT_V2_TRIP_WITH_LIMITS, '{"city":"Paris","days":3}');

        expect($answer->structured?->isUsable())->toBeTrue()
            ->and($answer->structured?->data)->toBe(['city' => 'Paris', 'days' => 3]);
    });

    it('checks every keyword that was taken out', function (string $says, string $path): void {
        [, $answer] = chatV2Structured(CHAT_V2_TRIP_WITH_LIMITS, $says);

        expect($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->issues[0]->path)->toBe($path);
    })->with([
        'a text that is too short' => ['{"city":"P","days":3}', '/city'],
        'a number that is too large' => ['{"city":"Paris","days":31}', '/days'],
    ]);

    it('never changes the schema of the caller', function (): void {
        $format = ChatV2::trip(CHAT_V2_TRIP_WITH_LIMITS);
        $before = $format->schema->json;
        $transport = (new FakeTransport())->willReturn(ChatV2::answered([ChatV2::text('{"city":"Paris","days":3}')]));

        ChatV2::gateway($transport)->complete(new Request(ChatV2::MODEL, [Message::user('Plan a trip.')], 30.0, responseFormat: $format));

        expect($format->schema->json)->toBe($before)
            ->and($before)->toContain('"minimum":1');
    });
});

describe('a schema the native mode of the Chat v2 adapter cannot take', function (): void {
    it('takes the emulated route when it uses allOf: the schema goes into the system text and no format is sent', function (bool $asStream): void {
        [$body, $answer] = chatV2Structured(CHAT_V2_TRIP_OF_TWO, '{"city":"Paris","days":3}', asStream: $asStream);
        $system = chatV2SystemTexts($body);

        expect(array_key_exists('response_format', $body))->toBeFalse()
            ->and($system)->toHaveCount(2)
            ->and($system[0])->toBe('You are a travel desk.')
            ->and($system[1])->toStartWith('Answer with exactly one JSON object')
            ->and($system[1])->toContain('A trip to one city.')
            ->and($system[1])->toEndWith("JSON Schema:\n" . CHAT_V2_TRIP_OF_TWO)
            ->and($answer->structured?->isUsable())->toBeTrue()
            ->and($answer->structured?->data)->toBe(['city' => 'Paris', 'days' => 3]);
    })->with(['through complete()' => [false], 'through stream()' => [true]]);

    it('still checks the answer of the emulated route against the schema of the caller', function (): void {
        [, $answer] = chatV2Structured(CHAT_V2_TRIP_OF_TWO, '{"city":"Paris","days":"three"}');

        expect($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->issues[0]->kind)->toBe(StructuredIssueKind::SchemaMismatch);
    });

    it('keeps the capability at native: the emulated route is for that one request', function (): void {
        expect(ChatV2::gateway(new FakeTransport())->capabilities(ChatV2::MODEL)->structuredOutput->value)->toBe('native');
    });

    it('takes the emulated route for every shape the mode refuses, and the native route otherwise', function (string $schema, bool $native): void {
        [$body] = chatV2Structured($schema, '{}');

        expect(array_key_exists('response_format', $body))->toBe($native)
            ->and(count(chatV2SystemTexts($body)))->toBe($native ? 1 : 2);
    })->with([
        'an object with a required property' => ['{"type":"object","properties":{"city":{"type":"string"}},"required":["city"]}', true],
        'an object without a required property' => ['{"type":"object","properties":{"city":{"type":"string"}}}', false],
        'an object with an empty list of required properties' => ['{"type":"object","properties":{"city":{"type":"string"}},"required":[]}', false],
        'a nested object without a required property' => ['{"type":"object","properties":{"where":{"type":"object","properties":{"city":{"type":"string"}}}},"required":["where"]}', false],
        'a nested object with one' => ['{"type":"object","properties":{"where":{"type":"object","properties":{"city":{"type":"string"}},"required":["city"]}},"required":["where"]}', true],
        'an object in a list, without a required property' => ['{"type":"object","properties":{"stops":{"type":"array","items":{"type":"object","properties":{"city":{"type":"string"}}}}},"required":["stops"]}', false],
        'allOf' => ['{"type":"object","properties":{"a":{"allOf":[{"type":"string"}]}},"required":["a"]}', false],
        'anyOf' => ['{"type":"object","properties":{"a":{"anyOf":[{"type":"string"},{"type":"null"}]}},"required":["a"]}', true],
        'a recursive schema, which the mode takes' => ['{"type":"object","properties":{"city":{"type":"string"},"inside":{"$ref":"#"}},"required":["city"]}', true],
        'definitions and a reference' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/city"}},"required":["a"],"$defs":{"city":{"type":"string"}}}', true],
        'a pattern without an anchor' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"[A-Z][a-z]+"}},"required":["a"]}', true],
        'a pattern that starts with an anchor' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"^[A-Z]"}},"required":["a"]}', false],
        'a pattern that ends with an anchor' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"[a-z]+$"}},"required":["a"]}', false],
        'a pattern with a lookahead' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"(?=.*[0-9]).+"}},"required":["a"]}', false],
        'a pattern with a negative lookahead' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"a(?!b)"}},"required":["a"]}', false],
        'a pattern with a negated class, which holds the mark of an anchor' => ['{"type":"object","properties":{"a":{"type":"string","pattern":"[^0-9]+"}},"required":["a"]}', false],
        'a pattern that is no text' => ['{"type":"object","properties":{"a":{"type":"string","pattern":7}},"required":["a"]}', false],
        'a property that is named pattern' => ['{"type":"object","properties":{"pattern":{"type":"string","enum":["^a$"]}},"required":["pattern"]}', true],
        'a refused keyword under if, where taking it out would tighten' => ['{"type":"object","properties":{"n":{"type":"integer","if":{"minimum":5},"then":{"multipleOf":5}}},"required":["n"]}', false],
        'oneOf beside anyOf, which cannot be written as one anyOf' => ['{"type":"object","properties":{"n":{"oneOf":[{"type":"string"},{"type":"integer"}],"anyOf":[{"type":"string"},{"type":"integer"}]}},"required":["n"]}', false],
    ]);
});

describe('the copy of a schema that the Chat v2 adapter sends', function (): void {
    it('is the schema of the caller, byte for byte, when it uses nothing the mode refuses', function (string $schema): void {
        expect(OutputSchema::forTheNativeMode(JsonSchema::fromJson($schema))?->json)->toBe($schema);
    })->with([
        'the plain trip' => ['{"type":"object","properties":{"city":{"type":"string"},"days":{"type":"integer"}},"required":["city","days"],"additionalProperties":false}'],
        'every accepted keyword' => ['{"type":"object","properties":{"a":{"anyOf":[{"type":"string","format":"date"},{"type":"null"}]},"b":{"$ref":"#/$defs/n"},"c":{"const":"x"},"d":{"type":"array","items":{"type":"number"}},"e":{"enum":["x",1]},"f":{"type":"string","format":"date-time"},"g":{"type":"string","format":"uuid"},"h":{"type":"string","format":"time","pattern":"[0-9:]+"}},"required":["a"],"additionalProperties":false,"$defs":{"n":{"type":"integer"}}}'],
        'properties that are named like refused keywords' => ['{"type":"object","properties":{"minimum":{"type":"integer"},"not":{"type":"string"},"oneOf":{"type":"string"},"allOf":{"type":"string"},"format":{"type":"string"},"maxItems":{"type":"integer"}},"required":["not"]}'],
        'refused keywords as data, not as keywords' => ['{"type":"object","properties":{"a":{"enum":["minimum","not","^$"],"default":"minimum"},"b":{"const":"oneOf","examples":["maxLength"]}},"required":["a"]}'],
    ]);

    it('loses every constraint keyword the mode refuses, and nothing else', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","description":"A trip.","properties":{"days":{"type":"integer","minimum":1,"maximum":30,"multipleOf":1,"description":"How long."},"city":{"type":"string","minLength":1,"maxLength":80,"pattern":"[A-Z]"},"tags":{"type":"array","items":{"type":"string","maxLength":9},"minItems":1,"maxItems":5,"uniqueItems":true},"minimum":{"type":"number","exclusiveMinimum":0}},"required":["city"],"additionalProperties":false}');

        expect(OutputSchema::forTheNativeMode($schema)?->json)->toBe(
            '{"type":"object","description":"A trip.","properties":{"days":{"type":"integer","multipleOf":1,"description":"How long."},"city":{"type":"string","pattern":"[A-Z]"},"tags":{"type":"array","items":{"type":"string"}},"minimum":{"type":"number","exclusiveMinimum":0}},"required":["city"],"additionalProperties":false}',
        );
    });

    it('keeps a format of the four the mode accepts, and takes the keyword out everywhere when one value is another', function (string $schema, string $sent): void {
        expect(OutputSchema::forTheNativeMode(JsonSchema::fromJson($schema))?->json)->toBe($sent);
    })->with([
        'the four' => [
            '{"type":"object","properties":{"a":{"type":"string","format":"date-time"},"b":{"type":"string","format":"uuid"},"c":{"type":"string","format":"date"},"d":{"type":"string","format":"time"}},"required":["a"]}',
            '{"type":"object","properties":{"a":{"type":"string","format":"date-time"},"b":{"type":"string","format":"uuid"},"c":{"type":"string","format":"date"},"d":{"type":"string","format":"time"}},"required":["a"]}',
        ],
        'a date and an e-mail address' => [
            '{"type":"object","properties":{"a":{"type":"string","format":"date"},"b":{"type":"string","format":"email"}},"required":["a"]}',
            '{"type":"object","properties":{"a":{"type":"string"},"b":{"type":"string"}},"required":["a"]}',
        ],
        'a value in another case' => [
            '{"type":"object","properties":{"a":{"type":"string","format":"Date"}},"required":["a"]}',
            '{"type":"object","properties":{"a":{"type":"string"}},"required":["a"]}',
        ],
        'a value that is no text' => [
            '{"type":"object","properties":{"a":{"type":"string","format":["date"]}},"required":["a"]}',
            '{"type":"object","properties":{"a":{"type":"string"}},"required":["a"]}',
        ],
    ]);

    it('writes oneOf as anyOf, and takes not out with everything under it, in one step with the constraint keywords', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"v":{"oneOf":[{"type":"string","minLength":3},{"type":"integer","minimum":0}]},"n":{"type":"integer","not":{"minimum":5,"const":7}}},"required":["v"]}');
        $sent = OutputSchema::forTheNativeMode($schema);

        expect($sent?->json)->toBe('{"type":"object","properties":{"v":{"anyOf":[{"type":"string"},{"type":"integer"}]},"n":{"type":"integer"}},"required":["v"]}')
            // What the helper said of the caller's schema: with not and oneOf among the keywords, nothing tightens.
            ->and((new SchemaShape())->mayTighten($schema, ['minLength', 'minimum', 'not', 'oneOf']))->toBeFalse()
            // Without not among them it would: the least under not would be taken out and not would stay.
            ->and((new SchemaShape())->mayTighten($schema, ['minLength', 'minimum', 'oneOf']))->toBeTrue();
    });

    it('is never sent when it could refuse an answer the schema of the caller allows', function (string $schema): void {
        expect((new SchemaShape())->mayTighten(JsonSchema::fromJson($schema), (new SchemaShape())->uses(JsonSchema::fromJson($schema), ['minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'uniqueItems', 'not', 'format', 'oneOf'])))->toBeTrue()
            ->and(OutputSchema::forTheNativeMode(JsonSchema::fromJson($schema)))->toBeNull();
    })->with([
        'a refused keyword under if' => ['{"type":"object","properties":{"n":{"if":{"minimum":5},"then":{"type":"integer"}}},"required":["n"]}'],
        'a refused format under if' => ['{"type":"object","properties":{"n":{"if":{"format":"email"},"then":{"type":"string"}}},"required":["n"]}'],
        'a refused keyword under contains beside maxContains' => ['{"type":"object","properties":{"n":{"type":"array","contains":{"minimum":5},"maxContains":1}},"required":["n"]}'],
        'oneOf beside anyOf' => ['{"type":"object","properties":{"n":{"oneOf":[{"type":"string"}],"anyOf":[{"type":"string"}]}},"required":["n"]}'],
        'a reference into a schema under not' => ['{"type":"object","properties":{"a":{"not":{"$defs":{"x":{"type":"string"}}}},"b":{"$ref":"#/properties/a/not/$defs/x"}},"required":["a"]}'],
    ]);

    it('asks the shape rules of the copy, not of the schema of the caller', function (): void {
        // Under not: an object without a required property, allOf and an anchored pattern. With not taken out, the
        // copy holds none of the three.
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"a":{"type":"string","not":{"type":"object","properties":{"x":{"allOf":[{"type":"string","pattern":"^x$"}]}}}}},"required":["a"]}');

        expect((new SchemaShape())->everyObjectHasARequiredProperty($schema))->toBeFalse()
            ->and((new SchemaShape())->uses($schema, ['allOf']))->toBe(['allOf'])
            ->and((new SchemaShape())->valuesOf($schema, 'pattern'))->toBe(['^x$'])
            ->and(OutputSchema::forTheNativeMode($schema)?->json)->toBe('{"type":"object","properties":{"a":{"type":"string"}},"required":["a"]}');
    });

    it('needs no rule of its own for the top level: a schema of the contract is always an object there', function (string $schema): void {
        expect(fn() => JsonSchema::fromJson($schema))->toThrow(OrganiseYou\LlmGateway\Contract\InvalidValue::class);
    })->with([
        'a list' => ['{"type":"array","items":{"type":"string"}}'],
        'no type' => ['{"properties":{"city":{"type":"string"}},"required":["city"]}'],
        'two types' => ['{"type":["object","null"],"properties":{"city":{"type":"string"}},"required":["city"]}'],
    ]);

    it('reads the values of format and the content of pattern only where a schema stands', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"format":{"type":"string","const":"email"},"pattern":{"type":"string","default":"^a$","examples":["(?=x)"]}},"required":["format"]}');

        expect((new SchemaShape())->valuesOf($schema, 'format'))->toBe([])
            ->and((new SchemaShape())->valuesOf($schema, 'pattern'))->toBe([])
            ->and(OutputSchema::forTheNativeMode($schema)?->json)->toBe($schema->json);
    });
});

describe('a response format beside the other fields of a request of the Chat v2 adapter', function (): void {
    it('goes into the system text beside tools, and the tools are sent: the format refuses the pair', function (bool $asStream): void {
        [$body, $answer] = chatV2Structured(CHAT_V2_TRIP_WITH_LIMITS, '{"city":"Paris","days":0}', withTools: true, asStream: $asStream);
        [$alone] = chatV2Structured(CHAT_V2_TRIP_WITH_LIMITS, '{}');
        $system = chatV2SystemTexts($body);

        expect(array_keys($body))->toBe(['model', 'messages', 'tools', 'stream'])
            ->and($body['tools'][0]['function']['name'])->toBe('lookup')
            ->and($system)->toHaveCount(2)
            ->and($system[0])->toBe('You are a travel desk.')
            // The whole schema of the caller, with the keywords the native mode would have lost.
            ->and($system[1])->toEndWith("JSON Schema:\n" . CHAT_V2_TRIP_WITH_LIMITS)
            ->and($body['messages'][2])->toBe(['role' => 'user', 'content' => 'Plan a trip.'])
            ->and(ChatV2::json($body))->not->toContain('strict_tools')
            // The answer is checked all the same.
            ->and($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->issues[0]->path)->toBe('/days')
            // Without tools the same server gets the schema as a field.
            ->and(array_key_exists('response_format', $alone))->toBeTrue();
    })->with(['through complete()' => [false], 'through stream()' => [true]]);

    it('is sent with the tools in one request only where the preset says the server takes both', function (): void {
        [$body, $answer] = chatV2Structured(CHAT_V2_TRIP_WITH_LIMITS, '{"city":"Paris","days":3}', ['tools_with_structured_output' => 'native'], withTools: true);

        expect(array_keys($body))->toBe(['model', 'messages', 'tools', 'response_format', 'stream'])
            ->and(chatV2SystemTexts($body))->toBe(['You are a travel desk.'])
            ->and($answer->structured?->isUsable())->toBeTrue();
    });

    it('goes into the system text where the preset says structured output is emulated, with the whole schema', function (): void {
        [$body, $answer] = chatV2Structured(CHAT_V2_TRIP_WITH_LIMITS, "```json\n{\"city\":\"Paris\",\"days\":3}\n```", ['structured_output' => 'emulated']);

        expect(array_key_exists('response_format', $body))->toBeFalse()
            ->and(chatV2SystemTexts($body)[1])->toEndWith("JSON Schema:\n" . CHAT_V2_TRIP_WITH_LIMITS)
            ->and($answer->structured?->isUsable())->toBeTrue();
    });

    it('is the only system text, and the first message, when the request has no system message', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered([ChatV2::text('{"city":"Paris","days":3}')]));
        ChatV2::gateway($transport)->complete(new Request(ChatV2::MODEL, [Message::user('Plan a trip.')], 30.0, responseFormat: ChatV2::trip(CHAT_V2_TRIP_OF_TWO)));
        $messages = ChatV2::sent($transport)['messages'];

        expect($messages[0]['role'])->toBe('system')
            ->and($messages[0]['content'])->toStartWith('Answer with exactly one JSON object')
            ->and($messages[1])->toBe(['role' => 'user', 'content' => 'Plan a trip.']);
    });

    it('sends the type the format has for JSON, and neither a name, a description nor a flag for exact adherence on the native route', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered([ChatV2::text('{"city":"Paris","days":3}')]));
        ChatV2::gateway($transport)->complete(new Request(ChatV2::MODEL, [Message::user('Plan a trip.')], 30.0, responseFormat: ChatV2::trip()));
        $sent = $transport->requests()[0]->body;

        expect(ChatV2::sent($transport)['response_format']['type'])->toBe('json_object')
            ->and(str_contains($sent, 'A trip to one city.'))->toBeFalse()
            ->and(str_contains($sent, '"trip"'))->toBeFalse()
            ->and(str_contains($sent, 'strict'))->toBeFalse();
    });

    it('gives no structured result for an answer that asks for a tool call', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered(null, [ChatV2::call()]));
        $answer = ChatV2::gateway($transport)->complete(new Request(ChatV2::MODEL, [Message::user('Plan a trip.')], 30.0, [ChatV2::lookup()], responseFormat: ChatV2::trip()));

        expect($answer->structured)->toBeNull()
            ->and($answer->toolCalls)->toHaveCount(1);
    });

    it('says "cut off" for a structured answer that ended at the token limit, and "not JSON" for an answer in which the model declines', function (): void {
        $request = new Request(ChatV2::MODEL, [Message::user('Plan a trip.')], 30.0, responseFormat: ChatV2::trip());
        $cutOff = ChatV2::answerTo(ChatV2::answered([ChatV2::text('{"city":"Par')], finish: 'MAX_TOKENS'), request: $request);
        $declined = ChatV2::answerTo(ChatV2::answered([ChatV2::text('I am sorry, but I cannot help with that.')]), request: $request);

        expect($cutOff->structured?->issues[0]->kind)->toBe(StructuredIssueKind::CutOff)
            ->and($declined->structured?->isUsable())->toBeFalse()
            ->and($declined->structured?->issues[0]->kind)->toBe(StructuredIssueKind::NotJson);
    });
});
