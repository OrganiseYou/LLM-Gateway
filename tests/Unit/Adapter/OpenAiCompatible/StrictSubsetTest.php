<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\StrictSubset;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;

// The structured-output rows of the chat-completions adapter (docs/design/adapters.md, section 5.9): the strict flag
// is true only when the schema of the caller already fits the documented subset, the schema is never changed, and
// the native mode is never left for the emulation. The limits come from docs/providers/openai.md (the standard
// subset) and docs/providers/azure-openai.md (the narrow one).

/**
 * A closed object with these properties, all of them required.
 *
 * @param array<string, mixed> $properties
 * @param array<string, mixed> $more keywords beside them
 * @return array<string, mixed>
 */
function closedObject(array $properties, array $more = []): array
{
    return ['type' => 'object', 'properties' => $properties, 'required' => array_map(strval(...), array_keys($properties)), 'additionalProperties' => false, ...$more];
}

/**
 * A schema of closed objects inside one another, this many schemas deep; the innermost holds a string.
 *
 * @return array<string, mixed>
 */
function objectsInside(int $levels): array
{
    $schema = ['type' => 'string'];
    for ($level = 1; $level < $levels; $level++) {
        $schema = closedObject(['inner' => $schema]);
    }

    return $schema;
}

/**
 * A closed object with this many string properties.
 *
 * @return array<string, mixed>
 */
function objectWithProperties(int $count): array
{
    $properties = [];
    for ($at = 0; $at < $count; $at++) {
        $properties['p' . $at] = ['type' => 'string'];
    }

    return closedObject($properties);
}

/**
 * @param array<string, mixed> $schema
 */
function fitsTheSubset(array $schema, bool $narrow = false): bool
{
    return (new StrictSubset())->fits(JsonSchema::fromArray($schema), $narrow);
}

describe('the standard subset', function (): void {
    it('takes a schema of closed objects whose properties are all required', function (array $schema): void {
        expect(fitsTheSubset($schema))->toBeTrue();
    })->with([
        'two properties' => [closedObject(['city' => ['type' => 'string'], 'days' => ['type' => 'integer']])],
        'no properties' => [closedObject([])],
        'an optional value as a union with null' => [closedObject(['days' => ['type' => ['integer', 'null']]])],
        'an object in a list' => [closedObject(['stops' => ['type' => 'array', 'items' => closedObject(['city' => ['type' => 'string']])]])],
        'a choice below the root' => [closedObject(['stop' => ['anyOf' => [closedObject(['city' => ['type' => 'string']]), ['type' => 'null']]]])],
        'a definition, also one that refers to itself' => [closedObject(['tree' => ['$ref' => '#/$defs/node']], ['$defs' => ['node' => closedObject(['children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/node']]])]])],
        'the limits this subset accepts' => [closedObject([
            'code' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$', 'format' => 'uuid'],
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'exclusiveMinimum' => 0, 'multipleOf' => 1],
            'stops' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 5],
            'kind' => ['type' => 'string', 'enum' => ['city', 'country']],
            'fixed' => ['const' => 'trip'],
        ])],
        'a refused keyword as the name of a property' => [closedObject(['not' => ['type' => 'string'], 'if' => ['type' => 'string'], 'allOf' => ['type' => 'string'], 'anyOf' => ['type' => 'string']])],
        'a refused keyword inside a value' => [closedObject(['mode' => ['enum' => [['not' => 1], ['allOf' => []], ['description' => 'a value']]], 'fixed' => ['const' => ['else' => 1, 'properties' => ['open' => 1], 'minLength' => 2]]])],
    ]);

    it('does not take a schema that uses a keyword the subset refuses', function (string $keyword, mixed $value): void {
        expect(fitsTheSubset(closedObject(['city' => ['type' => 'string']], [$keyword => $value])))->toBeFalse()
            // Nor anywhere below the root.
            ->and(fitsTheSubset(closedObject(['stop' => closedObject(['city' => ['type' => 'string']], [$keyword => $value])])))->toBeFalse();
    })->with([
        'allOf' => ['allOf', [['type' => 'object']]],
        'not' => ['not', ['type' => 'null']],
        'dependentRequired' => ['dependentRequired', ['city' => ['city']]],
        'dependentSchemas' => ['dependentSchemas', ['city' => ['type' => 'object']]],
        'if' => ['if', ['type' => 'object']],
        'then' => ['then', ['type' => 'object']],
        'else' => ['else', ['type' => 'object']],
    ]);

    it('does not take a schema with an optional property, an open object or a choice at its root', function (array $schema): void {
        expect(fitsTheSubset($schema))->toBeFalse();
    })->with([
        'an optional property' => [['type' => 'object', 'properties' => ['city' => ['type' => 'string'], 'days' => ['type' => 'integer']], 'required' => ['city'], 'additionalProperties' => false]],
        'no required at all' => [['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'additionalProperties' => false]],
        'an optional property below the root' => [closedObject(['stop' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'additionalProperties' => false]])],
        'an object that is not closed' => [['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']]],
        'an object that allows more' => [['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city'], 'additionalProperties' => true]],
        'an object with a schema for what is more' => [['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city'], 'additionalProperties' => ['type' => 'string']]],
        'an open object in a list' => [closedObject(['stops' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']]]])],
        'a choice at the root' => [closedObject(['city' => ['type' => 'string']], ['anyOf' => [closedObject(['city' => ['type' => 'string']])]])],
    ]);

    it('takes a schema of 10 levels and not of 11, of 5000 properties and not of 5001', function (): void {
        expect(fitsTheSubset(objectsInside(10)))->toBeTrue()
            ->and(fitsTheSubset(objectsInside(11)))->toBeFalse()
            ->and(fitsTheSubset(objectWithProperties(5000)))->toBeTrue()
            ->and(fitsTheSubset(objectWithProperties(5001)))->toBeFalse();
    });

    it('counts the characters of every property name, definition name, enum value and const value together: 120000 at most', function (): void {
        $name = fn(string $letter, int $length): string => str_repeat($letter, $length);

        // Three names of 40000 letters are the limit; one letter more is over it.
        expect(fitsTheSubset(closedObject([$name('a', 40000) => ['type' => 'string'], $name('b', 40000) => ['type' => 'string'], $name('c', 40000) => ['type' => 'string']])))->toBeTrue()
            ->and(fitsTheSubset(closedObject([$name('a', 40000) => ['type' => 'string'], $name('b', 40000) => ['type' => 'string'], $name('c', 40001) => ['type' => 'string']])))->toBeFalse()
            // A name of a definition, a value of an enum and a value of a const count as a property name does.
            ->and(fitsTheSubset(closedObject([$name('a', 40000) => ['$ref' => '#/$defs/' . $name('d', 40000)]], ['$defs' => [$name('d', 40000) => ['type' => 'string', 'enum' => [$name('e', 20000)], 'const' => $name('f', 20000)]]])))->toBeTrue()
            ->and(fitsTheSubset(closedObject([$name('a', 40000) => ['$ref' => '#/$defs/' . $name('d', 40000)]], ['$defs' => [$name('d', 40000) => ['type' => 'string', 'enum' => [$name('e', 20000)], 'const' => $name('f', 20001)]]])))->toBeFalse()
            ->and(fitsTheSubset(closedObject(['kind' => ['type' => 'string', 'enum' => [$name('e', 60000), $name('g', 60001)]]])))->toBeFalse()
            ->and(fitsTheSubset(closedObject(['kind' => ['const' => $name('f', 120000)]])))->toBeFalse()
            ->and(fitsTheSubset(closedObject(['kind' => ['type' => 'string']], ['$defs' => [$name('d', 119996) => ['type' => 'string']]])))->toBeTrue()
            ->and(fitsTheSubset(closedObject(['kind' => ['type' => 'string']], ['$defs' => [$name('d', 119997) => ['type' => 'string']]])))->toBeFalse();
    });

    it('counts characters, not bytes', function (): void {
        // 60000 letters of two bytes each, twice: 120000 characters in 240000 bytes.
        expect(fitsTheSubset(closedObject([str_repeat('é', 60000) => ['type' => 'string'], str_repeat('ü', 60000) => ['type' => 'string']])))->toBeTrue()
            ->and(fitsTheSubset(closedObject([str_repeat('é', 60000) => ['type' => 'string'], str_repeat('ü', 60001) => ['type' => 'string']])))->toBeFalse();
    });

    it('takes 1000 enum values in the whole schema and not 1001', function (): void {
        $twoEnums = fn(int $first, int $second): array => closedObject([
            'a' => ['type' => 'integer', 'enum' => range(1, $first)],
            'b' => ['type' => 'integer', 'enum' => range(1, $second)],
        ]);

        expect(fitsTheSubset($twoEnums(600, 400)))->toBeTrue()
            ->and(fitsTheSubset($twoEnums(600, 401)))->toBeFalse();
    });

    it('takes one enum of more than 250 strings only when it holds 15000 characters at most', function (): void {
        $enum = fn(int $values, int $length): array => closedObject(['kind' => ['type' => 'string', 'enum' => array_map(fn(int $at): string => str_pad((string) $at, $length, 'x'), range(1, $values))]]);

        expect(fitsTheSubset($enum(251, 59)))->toBeTrue()      // 14809 characters
            ->and(fitsTheSubset($enum(251, 60)))->toBeFalse()   // 15060 characters
            ->and(fitsTheSubset($enum(250, 100)))->toBeTrue()   // 25000 characters, in an enum that is not a large one
            ->and(fitsTheSubset($enum(300, 50)))->toBeTrue();   // 15000 characters: the limit itself
    });
});

describe('what neither subset names as accepted', function (): void {
    it('is not in the subset: strict is a promise, and it is given only for what a page says is accepted', function (string $keyword, mixed $value): void {
        $schema = closedObject(['value' => ['type' => ['string', 'number', 'array'], $keyword => $value]]);

        expect(fitsTheSubset($schema, false))->toBeFalse()
            ->and(fitsTheSubset($schema, true))->toBeFalse();
    })->with([
        'minLength' => ['minLength', 1],
        'maxLength' => ['maxLength', 9],
        'uniqueItems' => ['uniqueItems', true],
        'oneOf' => ['oneOf', [['type' => 'string'], ['type' => 'number']]],
        'prefixItems' => ['prefixItems', [['type' => 'string']]],
        'contains' => ['contains', ['type' => 'string']],
        'minContains' => ['minContains', 1],
        'maxContains' => ['maxContains', 9],
        'propertyNames' => ['propertyNames', ['type' => 'string']],
        'minProperties' => ['minProperties', 1],
        'maxProperties' => ['maxProperties', 9],
        'unevaluatedItems' => ['unevaluatedItems', false],
        'default' => ['default', 'a'],
        'examples' => ['examples', ['a']],
        'description' => ['description', 'What the value is for.'],
        'title' => ['title', 'Value'],
        'nullable' => ['nullable', true],
        '$comment' => ['$comment', 'a note'],
        'a keyword of next year' => ['x-next-year', 1],
        'a format the page does not list' => ['format', 'uri'],
        'a format that is no word' => ['format', ['date']],
        'a reference to another document' => ['$ref', 'https://schemas.example.test/value.json'],
        'a reference to a file' => ['$ref', 'value.json#/$defs/value'],
        'a reference to an older place of definitions' => ['$ref', '#/definitions/value'],
        'a reference into a property' => ['$ref', '#/properties/value'],
        'a reference that is no text' => ['$ref', 7],
        'a list of schemas for the items' => ['items', [['type' => 'string']]],
        'true for the items' => ['items', true],
        'an empty choice' => ['anyOf', []],
        'a choice that holds true' => ['anyOf', [true]],
    ]);

    it('is not in the subset at the root or in a definition either', function (array $schema): void {
        expect(fitsTheSubset($schema, false))->toBeFalse()
            ->and(fitsTheSubset($schema, true))->toBeFalse();
    })->with([
        'a description at the root' => [closedObject(['city' => ['type' => 'string']], ['description' => 'A trip.'])],
        'the older place of definitions' => [closedObject(['city' => ['type' => 'string']], ['definitions' => ['city' => ['type' => 'string']]])],
        'a dialect at the root' => [closedObject(['city' => ['type' => 'string']], ['$schema' => 'https://json-schema.org/draft/2020-12/schema'])],
        'patternProperties' => [closedObject(['city' => ['type' => 'string']], ['patternProperties' => ['^x-' => ['type' => 'string']]])],
        'oneOf inside a definition' => [closedObject(['stop' => ['$ref' => '#/$defs/stop']], ['$defs' => ['stop' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]]]])],
        'a type the page does not list' => [closedObject(['city' => ['type' => 'text']])],
        'a list of types with one the page does not list' => [closedObject(['city' => ['type' => ['string', 'any']]])],
        'true as the schema of a property' => [['type' => 'object', 'properties' => ['city' => true], 'required' => ['city'], 'additionalProperties' => false]],
        'an object closed with an empty schema' => [['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city'], 'additionalProperties' => ['not' => []]]],
    ]);

    it('takes a reference to the schema itself and to one of its definitions', function (): void {
        $tree = closedObject(['children' => ['type' => 'array', 'items' => ['$ref' => '#']]]);
        $named = closedObject(['stop' => ['$ref' => '#/$defs/stop']], ['$defs' => ['stop' => closedObject(['city' => ['type' => 'string']])]]);

        expect(fitsTheSubset($tree))->toBeTrue()
            ->and(fitsTheSubset($named))->toBeTrue()
            ->and(fitsTheSubset($named, true))->toBeTrue();
    });
});

describe('the narrow subset', function (): void {
    it('takes what the standard subset takes without a limit on a string, a number, an object or a list', function (array $schema): void {
        expect(fitsTheSubset($schema, true))->toBeTrue();
    })->with([
        'two properties' => [closedObject(['city' => ['type' => 'string'], 'days' => ['type' => 'integer']])],
        'an enum, a const and a union with null' => [closedObject(['kind' => ['type' => 'string', 'enum' => ['city', 'country']], 'fixed' => ['const' => 'trip'], 'days' => ['type' => ['integer', 'null']]])],
        'a definition that refers to itself' => [closedObject(['tree' => ['$ref' => '#/$defs/node']], ['$defs' => ['node' => closedObject(['children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/node']]])]])],
        'a refused keyword as the name of a property' => [closedObject(['pattern' => ['type' => 'string'], 'format' => ['type' => 'string'], 'minimum' => ['type' => 'integer'], 'maxItems' => ['type' => 'integer']])],
    ]);

    it('does not take a schema that uses a limit this subset refuses, and the standard subset takes it', function (string $keyword, mixed $value): void {
        $schema = closedObject(['value' => ['type' => ['string', 'number', 'array'], $keyword => $value]]);

        expect(fitsTheSubset($schema, true))->toBeFalse()
            ->and(fitsTheSubset($schema, false))->toBeTrue();
    })->with([
        'pattern' => ['pattern', '^a'],
        'format' => ['format', 'date'],
        'minimum' => ['minimum', 1],
        'maximum' => ['maximum', 9],
        'multipleOf' => ['multipleOf', 2],
        'minItems' => ['minItems', 1],
        'maxItems' => ['maxItems', 9],
    ]);

    it('takes the two limits on a number that its page does not refuse', function (): void {
        expect(fitsTheSubset(closedObject(['days' => ['type' => 'integer', 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 31]]), true))->toBeTrue();
    });

    it('does not take the two keywords about properties that it refuses either', function (string $keyword, mixed $value): void {
        expect(fitsTheSubset(closedObject(['city' => ['type' => 'string']], [$keyword => $value]), true))->toBeFalse();
    })->with([
        'patternProperties' => ['patternProperties', ['^x-' => ['type' => 'string']]],
        'unevaluatedProperties' => ['unevaluatedProperties', false],
    ]);

    it('refuses what the standard subset refuses', function (array $schema): void {
        expect(fitsTheSubset($schema, true))->toBeFalse();
    })->with([
        'allOf' => [closedObject(['city' => ['type' => 'string']], ['allOf' => [['type' => 'object']]])],
        'an optional property' => [['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'additionalProperties' => false]],
        'an open object' => [['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']]],
        'a choice at the root' => [closedObject(['city' => ['type' => 'string']], ['anyOf' => [closedObject(['city' => ['type' => 'string']])]])],
    ]);

    it('takes a schema of 5 levels and not of 6, of 100 properties and not of 101', function (): void {
        expect(fitsTheSubset(objectsInside(5), true))->toBeTrue()
            ->and(fitsTheSubset(objectsInside(6), true))->toBeFalse()
            ->and(fitsTheSubset(objectsInside(6), false))->toBeTrue()
            ->and(fitsTheSubset(objectWithProperties(100), true))->toBeTrue()
            ->and(fitsTheSubset(objectWithProperties(101), true))->toBeFalse()
            ->and(fitsTheSubset(objectWithProperties(101), false))->toBeTrue();
    });
});

describe('the response format the adapter sends', function (): void {
    it('is the schema of the caller, byte for byte, whether it fits or not, with strict as the subset says', function (array $schema, array $options, ?bool $strict): void {
        $format = new ResponseFormat(JsonSchema::fromArray($schema), 'trip');
        $sent = ChatCompletions::bodyFor(ChatCompletions::request()->withResponseFormat($format), $options);

        expect($sent['response_format']['json_schema']['schema'])->toBe($format->schema->toArray())
            ->and($sent['response_format']['json_schema']['strict'] ?? null)->toBe($strict)
            // Never the emulation in its place: the capability says native, and native it is.
            ->and($sent['messages'])->toBe([['role' => 'user', 'content' => 'Say hello.']]);
    })->with([
        'fits the standard subset' => [closedObject(['city' => ['type' => 'string', 'pattern' => '^[A-Z]']]), ['json_schema_strict' => 'when_fits'], true],
        'fits the standard subset and not the narrow one' => [closedObject(['city' => ['type' => 'string', 'pattern' => '^[A-Z]']]), ['json_schema_strict' => 'when_fits', 'strict_subset' => 'narrow'], false],
        'uses a keyword no page names' => [closedObject(['city' => ['type' => 'string', 'description' => 'Where to.']]), ['json_schema_strict' => 'when_fits'], false],
        'fits the narrow subset' => [closedObject(['city' => ['type' => 'string']]), ['json_schema_strict' => 'when_fits', 'strict_subset' => 'narrow'], true],
        'has an optional property' => [['type' => 'object', 'properties' => ['city' => ['type' => 'string']]], ['json_schema_strict' => 'when_fits'], false],
        'uses a keyword the subset refuses' => [closedObject(['city' => ['type' => 'string']], ['not' => ['required' => ['country']]]), ['json_schema_strict' => 'when_fits'], false],
        'is too deep' => [objectsInside(11), ['json_schema_strict' => 'when_fits'], false],
        'does not fit, and strict is always false' => [['type' => 'object', 'properties' => ['city' => ['type' => 'string']]], ['json_schema_strict' => 'false'], false],
        'fits, and strict is always false' => [closedObject(['city' => ['type' => 'string']]), ['json_schema_strict' => 'false'], false],
        'does not fit, and strict is not sent' => [closedObject(['city' => ['type' => 'string']], ['allOf' => [['type' => 'object']]]), [], null],
        'fits, and strict is not sent' => [closedObject(['city' => ['type' => 'string']]), ['json_schema_strict' => 'omit'], null],
    ]);

    it('keeps an empty object of the schema an object', function (): void {
        $format = new ResponseFormat(JsonSchema::fromJson('{"type":"object","properties":{},"additionalProperties":false,"$defs":{}}'), 'nothing');
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered(['role' => 'assistant', 'content' => '{}']));
        ChatCompletions::gateway($transport)->complete(ChatCompletions::request()->withResponseFormat($format));

        expect($transport->requests()[0]->body)->toContain('"schema":{"type":"object","properties":{},"additionalProperties":false,"$defs":{}}');
    });

    it('checks the answer against the whole schema, also the part the strict subset does not know', function (): void {
        $format = new ResponseFormat(JsonSchema::fromArray(closedObject(['city' => ['type' => 'string', 'minLength' => 3]])), 'trip');
        $answer = ChatCompletions::answerTo(
            ChatCompletions::answered(['role' => 'assistant', 'content' => '{"city":"P"}']),
            ['json_schema_strict' => 'when_fits', 'strict_subset' => 'narrow'],
            ChatCompletions::request()->withResponseFormat($format),
        );

        expect($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->issues[0]->path)->toBe('/city');
    });
});
