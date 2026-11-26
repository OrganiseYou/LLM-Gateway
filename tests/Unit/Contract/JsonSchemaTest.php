<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\JsonSchema;

describe('JsonSchema', function (): void {
    it('keeps a schema given as JSON text in canonical form', function (): void {
        $schema = JsonSchema::fromJson('{ "type" : "object", "properties" : {}, "title": "café a/b" }');

        expect($schema->json)->toBe('{"type":"object","properties":{},"title":"café a/b"}');
    });

    it('gives the same canonical text from an array and from JSON', function (): void {
        $fromArray = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ]);
        $fromJson = JsonSchema::fromJson('{"type":"object","properties":{"city":{"type":"string"}},"required":["city"]}');

        expect($fromArray->json)->toBe($fromJson->json);
    });

    it('returns the schema as an array', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","required":["a"]}');

        expect($schema->toArray())->toBe(['type' => 'object', 'required' => ['a']]);
    });

    it('returns the schema as an object with empty objects kept as objects', function (): void {
        $object = JsonSchema::fromJson('{"type":"object","properties":{}}')->toObject();

        expect($object)->toBeInstanceOf(stdClass::class)
            ->and($object->type)->toBe('object')
            ->and($object->properties)->toBeInstanceOf(stdClass::class);
    });

    it('has no public constructor', function (): void {
        expect((new ReflectionClass(JsonSchema::class))->getConstructor()?->isPrivate())->toBeTrue();
    });

    it('rule 1: fromJson throws when the text is not valid JSON', function (): void {
        JsonSchema::fromJson('{"type":"object"');
    })->throws(InvalidArgumentException::class);

    it('rule 1: fromJson throws when the root is not a JSON object', function (string $json): void {
        JsonSchema::fromJson($json);
    })->with([
        'a list' => ['[{"type":"object"}]'],
        'an empty list' => ['[]'],
        'a string' => ['"object"'],
        'a number' => ['1'],
        'null' => ['null'],
        'true' => ['true'],
    ])->throws(InvalidArgumentException::class);

    it('rule 2: fromArray throws when the array is a non-empty list', function (): void {
        JsonSchema::fromArray(untyped(['type', 'object']));
    })->throws(InvalidArgumentException::class, 'The schema must be a JSON object, not a list.');

    it('rule 2: fromArray throws when the array cannot be encoded as JSON', function (): void {
        JsonSchema::fromArray(['type' => 'object', 'title' => "caf\xE9"]);
    })->throws(InvalidArgumentException::class);

    it('rule 3: fromJson throws when the root has no type object', function (string $json): void {
        JsonSchema::fromJson($json);
    })->with([
        'no type' => ['{"properties":{}}'],
        'empty object' => ['{}'],
        'another type' => ['{"type":"string"}'],
        'a list of types' => ['{"type":["object","null"]}'],
    ])->throws(InvalidArgumentException::class);

    it('rule 3: fromArray throws when the root has no type object', function (mixed $schema): void {
        JsonSchema::fromArray($schema);
    })->with([
        'no type' => [['properties' => []]],
        'empty array' => [[]],
        'another type' => [['type' => 'array']],
    ])->throws(InvalidArgumentException::class);

    it('fromArray encodes an empty array as an object under the object-valued keywords, at any depth', function (string $keyword): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            $keyword => [],
            'allOf' => [['type' => 'object', $keyword => []]],
        ]);

        expect($schema->json)
            ->toBe('{"type":"object","' . $keyword . '":{},"allOf":[{"type":"object","' . $keyword . '":{}}]}');
    })->with([
        'properties',
        'patternProperties',
        '$defs',
        'definitions',
        'dependentSchemas',
        'dependentRequired',
        'dependencies',
        '$vocabulary',
    ]);

    it('fromArray encodes a map of schemas as an object when its names are numbers', function (string $keyword): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            $keyword => ['0' => ['type' => 'string'], '1' => ['type' => 'integer']],
        ]);

        expect($schema->json)
            ->toBe('{"type":"object","' . $keyword . '":{"0":{"type":"string"},"1":{"type":"integer"}}}');
    })->with(['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas']);

    it('fromArray encodes a map of name lists as an object when its names are numbers', function (): void {
        $schema = JsonSchema::fromArray(['type' => 'object', 'dependentRequired' => ['0' => ['a'], '1' => []]]);

        expect($schema->json)->toBe('{"type":"object","dependentRequired":{"0":["a"],"1":[]}}');
    });

    it('fromArray encodes an empty array as the empty schema directly inside a map of schemas', function (string $keyword): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            $keyword => ['a' => [], 'b' => ['type' => 'object', $keyword => ['c' => []]]],
        ]);

        expect($schema->json)
            ->toBe('{"type":"object","' . $keyword . '":{"a":{},"b":{"type":"object","' . $keyword . '":{"c":{}}}}}');
    })->with(['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas']);

    it('fromArray treats a property that is named like a keyword as a schema, not as a keyword', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => ['properties' => ['type' => 'array', 'items' => ['type' => 'string']], 'enum' => []],
        ]);

        expect($schema->json)
            ->toBe('{"type":"object","properties":{"properties":{"type":"array","items":{"type":"string"}},"enum":{}}}');
    });

    it('fromArray leaves data under enum, const, default and examples as it is', function (string $keyword): void {
        $schema = JsonSchema::fromArray(['type' => 'object', $keyword => [['properties' => [], 'definitions' => ['a', 'b']]]]);

        expect($schema->json)->toBe('{"type":"object","' . $keyword . '":[{"properties":[],"definitions":["a","b"]}]}');
    })->with(['enum', 'const', 'default', 'examples']);

    it('gives the same schema back from fromArray(fromJson(...)->toArray())', function (string $json): void {
        expect(JsonSchema::fromArray(JsonSchema::fromJson($json)->toArray())->json)->toBe($json);
    })->with([
        'numbered properties' => ['{"type":"object","properties":{"0":{"type":"string"},"1":{"type":"integer"}}}'],
        'an empty property schema' => ['{"type":"object","properties":{"a":{},"b":{"type":"object","properties":{}}}}'],
        'empty maps' => ['{"type":"object","properties":{},"patternProperties":{},"$defs":{},"dependentSchemas":{},"dependentRequired":{}}'],
        'lists that stay lists' => ['{"type":"object","required":[],"dependentRequired":{"a":[]},"enum":[[],"a"],"allOf":[{"type":"object","$defs":{"0":{}}}]}'],
    ]);

    it('fromArray throws for a schema nested deeper than 512 levels, such as an array that holds itself', function (): void {
        $schema = ['type' => 'object', 'properties' => []];
        $schema['properties']['self'] = &$schema;

        JsonSchema::fromArray($schema);
    })->throws(InvalidArgumentException::class, 'The schema is nested deeper than 512 levels.');

    it('toObject keeps an empty object that toArray loses', function (): void {
        $json = '{"type":"object","properties":{},"required":[]}';
        $schema = JsonSchema::fromJson($json);

        expect(json_encode($schema->toObject()))->toBe($json)
            ->and(json_encode($schema->toArray()))->toBe('{"type":"object","properties":[],"required":[]}');
    });

    it('fromArray keeps an empty array as a list everywhere else', function (): void {
        $schema = JsonSchema::fromArray(['type' => 'object', 'required' => [], 'enum' => [[]]]);

        expect($schema->json)->toBe('{"type":"object","required":[],"enum":[[]]}');
    });

    it('does not check whether the schema is a valid JSON Schema', function (): void {
        expect(JsonSchema::fromArray(['type' => 'object', 'properties' => 'nonsense'])->json)
            ->toBe('{"type":"object","properties":"nonsense"}');
    });
});
