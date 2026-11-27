<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\SchemaShape;
use OrganiseYou\LlmGateway\Adapter\Shared\StructuredAnswerChecker;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;

/**
 * Whether the validator of the library takes this answer for this schema.
 */
function schemaAllows(JsonSchema $schema, string $answer): bool
{
    try {
        return (new StructuredAnswerChecker())->check($answer, new ResponseFormat($schema, 'answer'), FinishReason::Stop)->issues === [];
    } catch (GatewayException) {
        // The validator cannot use the schema: it allows nothing.
        return false;
    }
}

/**
 * A schema of a tree: a node holds a list of nodes, through a definition that refers to itself.
 */
function recursiveTreeSchema(): JsonSchema
{
    return JsonSchema::fromArray([
        'type' => 'object',
        'properties' => ['root' => ['$ref' => '#/$defs/node']],
        'required' => ['root'],
        'additionalProperties' => false,
        '$defs' => [
            'node' => [
                'type' => 'object',
                'properties' => [
                    'label' => ['type' => 'string', 'minLength' => 1],
                    'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/node'], 'maxItems' => 5],
                ],
                'required' => ['label', 'children'],
                'additionalProperties' => false,
            ],
        ],
    ]);
}

/**
 * A schema whose property names are keywords, and whose data under "enum", "const", "default" and "examples"
 * looks like schemas.
 */
function schemaWithKeywordsAsNamesAndData(): JsonSchema
{
    return JsonSchema::fromArray([
        'type' => 'object',
        'properties' => [
            'minimum' => ['type' => 'number'],
            'not' => ['type' => 'string'],
            'properties' => ['type' => 'object', 'properties' => ['pattern' => ['type' => 'string']]],
            'required' => ['type' => 'boolean'],
            'additionalProperties' => ['type' => 'boolean'],
            '$ref' => ['type' => 'string'],
            'choice' => ['enum' => [['minimum' => 1, 'properties' => ['a' => ['maxLength' => 2]]], 'plain']],
            'fixed' => ['const' => ['type' => 'object', 'not' => ['minimum' => 3], '$ref' => '#']],
            'preset' => ['type' => 'object', 'default' => ['maxLength' => 4, 'properties' => ['b' => []], 'empty' => []]],
            'shown' => ['type' => 'object', 'examples' => [['uniqueItems' => true, '$dynamicRef' => '#x']]],
        ],
        'required' => ['minimum', 'not'],
    ]);
}

describe('uses', function (): void {
    it('gives the keywords that stand where a schema stands, in the order asked and each once', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'age' => ['type' => 'integer', 'minimum' => 0],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 9], 'uniqueItems' => true],
                'either' => ['anyOf' => [['type' => 'string', 'pattern' => '^a'], ['$ref' => '#/$defs/count']]],
            ],
            'patternProperties' => ['^x-' => ['type' => 'string', 'format' => 'date']],
            'additionalProperties' => ['type' => 'integer', 'minimum' => 3],
            'if' => ['required' => ['age']],
            'then' => ['not' => ['required' => ['tags']]],
            '$defs' => ['count' => ['type' => 'integer', 'multipleOf' => 2]],
        ]);

        expect((new SchemaShape())->uses($schema, ['multipleOf', 'minimum', 'oneOf', 'not', 'format', 'minimum', 'maxLength', 'if', 'type']))
            ->toBe(['multipleOf', 'minimum', 'not', 'format', 'maxLength', 'if', 'type']);
    });

    it('finds a keyword in every kind of place a schema stands at', function (string $json): void {
        expect((new SchemaShape())->uses(JsonSchema::fromJson('{"type":"object",' . $json . '}'), ['minLength']))->toBe(['minLength']);
    })->with([
        'properties' => ['"properties":{"a":{"minLength":1}}'],
        'patternProperties' => ['"patternProperties":{"^a":{"minLength":1}}'],
        '$defs' => ['"$defs":{"a":{"minLength":1}}'],
        'definitions' => ['"definitions":{"a":{"minLength":1}}'],
        'dependentSchemas' => ['"dependentSchemas":{"a":{"minLength":1}}'],
        'dependencies' => ['"dependencies":{"a":{"minLength":1},"b":["a"]}'],
        'items' => ['"items":{"minLength":1}'],
        'items as a list' => ['"items":[true,{"minLength":1}]'],
        'prefixItems' => ['"prefixItems":[{"minLength":1}]'],
        'additionalItems' => ['"additionalItems":{"minLength":1}'],
        'additionalProperties' => ['"additionalProperties":{"minLength":1}'],
        'contains' => ['"contains":{"minLength":1}'],
        'propertyNames' => ['"propertyNames":{"minLength":1}'],
        'not' => ['"not":{"minLength":1}'],
        'if' => ['"if":{"minLength":1}'],
        'then' => ['"then":{"minLength":1}'],
        'else' => ['"else":{"minLength":1}'],
        'allOf' => ['"allOf":[{"minLength":1}]'],
        'anyOf' => ['"anyOf":[{"minLength":1}]'],
        'oneOf' => ['"oneOf":[{"minLength":1}]'],
        'unevaluatedItems' => ['"unevaluatedItems":{"minLength":1}'],
        'unevaluatedProperties' => ['"unevaluatedProperties":{"minLength":1}'],
        'contentSchema' => ['"contentSchema":{"minLength":1}'],
        'six schemas deep' => ['"properties":{"a":{"items":{"anyOf":[{"not":{"properties":{"b":{"minLength":1}}}}]}}}'],
    ]);

    it('does not read the name of a property as a keyword', function (): void {
        expect((new SchemaShape())->uses(
            JsonSchema::fromArray([
                'type' => 'object',
                'properties' => ['minimum' => ['type' => 'number'], 'not' => ['type' => 'string'], 'pattern' => ['type' => 'string']],
                '$defs' => ['maxLength' => ['type' => 'string']],
                'patternProperties' => ['format' => ['type' => 'string']],
                'required' => ['minimum', 'not'],
            ]),
            ['minimum', 'not', 'pattern', 'maxLength', 'format'],
        ))->toBe([]);
    });

    it('does not read what stands under enum, const, default and examples', function (): void {
        expect((new SchemaShape())->uses(
            schemaWithKeywordsAsNamesAndData(),
            ['minimum', 'not', 'maxLength', 'uniqueItems', 'pattern', '$dynamicRef', 'empty', 'a', 'b'],
        ))->toBe([]);
    });

    it('does not read what stands under a keyword that holds no schema', function (): void {
        expect((new SchemaShape())->uses(
            JsonSchema::fromJson('{"type":"object","x-notes":{"minimum":1},"required":["minimum"],"dependentRequired":{"a":["maximum"]}}'),
            ['minimum', 'maximum'],
        ))->toBe([]);
    });

    it('reads the schemas of properties with numbers as names, and no number as a keyword', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => ['0' => ['type' => 'string', 'minLength' => 1], '1' => ['type' => 'integer', 'maximum' => 5]],
        ]);

        expect((new SchemaShape())->uses($schema, ['0', '1', 'maximum', 'minLength']))->toBe(['maximum', 'minLength']);
    });

    it('finds a keyword that is a number where it stands as one', function (): void {
        expect((new SchemaShape())->uses(JsonSchema::fromJson('{"type":"object","0":true,"properties":{"1":{}}}'), ['1', '0']))->toBe(['0']);
    });

    it('does not look at an entry that is not a string or cannot be a name', function (): void {
        expect((new SchemaShape())->uses(
            JsonSchema::fromArray(['type' => 'object', 'minProperties' => 1]),
            [1, null, ['type'], "\0type", 'k' => 'minProperties', true],
        ))->toBe(['minProperties']);
    });

    it('answers for a recursive schema', function (): void {
        expect((new SchemaShape())->uses(recursiveTreeSchema(), ['maxItems', 'minimum', 'minLength', '$ref']))
            ->toBe(['maxItems', 'minLength', '$ref']);
    });
});

describe('without', function (): void {
    it('gives a copy without the keywords at every place a schema stands, and leaves the schema of the caller as it was', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'age' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 150],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 9], 'uniqueItems' => true],
                'either' => ['anyOf' => [['type' => 'string', 'maxLength' => 3], ['$ref' => '#/$defs/count']]],
            ],
            'required' => ['age'],
            '$defs' => ['count' => ['type' => 'integer', 'minimum' => 1]],
        ]);
        $before = $schema->json;

        $copy = (new SchemaShape())->without($schema, ['minimum', 'maximum', 'maxLength', 'uniqueItems']);

        expect($copy->json)->toBe(
            '{"type":"object","properties":{"age":{"type":"integer"},'
            . '"tags":{"type":"array","items":{"type":"string"}},'
            . '"either":{"anyOf":[{"type":"string"},{"$ref":"#/$defs/count"}]}},'
            . '"required":["age"],"$defs":{"count":{"type":"integer"}}}',
        )
            ->and($copy)->not->toBe($schema)
            ->and($schema->json)->toBe($before);
    });

    it('takes a keyword out with the schemas under it', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","not":{"properties":{"a":{"not":{"type":"null"}}}},"properties":{"b":{"not":{"const":1},"type":"integer"}}}');

        expect((new SchemaShape())->without($schema, ['not'])->json)->toBe('{"type":"object","properties":{"b":{"type":"integer"}}}');
    });

    it('leaves a property whose name is a keyword, and its name in required', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'minimum' => ['type' => 'number', 'minimum' => 0],
                'not' => ['type' => 'string'],
                'properties' => ['type' => 'object', 'properties' => ['minimum' => ['type' => 'integer']]],
            ],
            'required' => ['minimum', 'not'],
        ]);

        expect((new SchemaShape())->without($schema, ['minimum', 'not'])->json)->toBe(
            '{"type":"object","properties":{"minimum":{"type":"number"},"not":{"type":"string"},'
            . '"properties":{"type":"object","properties":{"minimum":{"type":"integer"}}}},"required":["minimum","not"]}',
        );
    });

    it('does not touch what stands under enum, const, default and examples', function (): void {
        $schema = schemaWithKeywordsAsNamesAndData();

        $copy = (new SchemaShape())->without($schema, ['minimum', 'not', 'maxLength', 'uniqueItems', '$dynamicRef', 'empty', 'a', 'b', '$ref']);

        expect($copy->json)->toBe($schema->json)
            ->and($copy->json)->toContain('"enum":[{"minimum":1,"properties":{"a":{"maxLength":2}}},"plain"]')
            ->and($copy->json)->toContain('"const":{"type":"object","not":{"minimum":3},"$ref":"#"}')
            ->and($copy->json)->toContain('"default":{"maxLength":4,"properties":{"b":[]},"empty":[]}')
            ->and($copy->json)->toContain('"examples":[{"uniqueItems":true,"$dynamicRef":"#x"}]');
    });

    it('does not touch what stands under a keyword that holds no schema', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","x-notes":{"minimum":1,"list":[],"map":{}},"dependentRequired":{"minimum":["a"]}}');

        expect((new SchemaShape())->without($schema, ['minimum'])->json)->toBe($schema->json);
    });

    it('keeps properties with numbers as names a JSON object', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                '0' => ['type' => 'string', 'minLength' => 1],
                '1' => ['type' => 'object', 'properties' => ['7' => ['type' => 'integer', 'minimum' => 2]], 'required' => ['7']],
            ],
            'required' => ['0', '1'],
            '$defs' => ['0' => ['type' => 'string', 'minLength' => 2]],
        ]);

        expect((new SchemaShape())->without($schema, ['minLength', 'minimum', '0', '7'])->json)->toBe(
            '{"type":"object","properties":{"0":{"type":"string"},'
            . '"1":{"type":"object","properties":{"7":{"type":"integer"}},"required":["7"]}},'
            . '"required":["0","1"],"$defs":{"0":{"type":"string"}}}',
        );
    });

    it('takes out a keyword that is a number where it stands as one', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","0":true,"properties":{"0":{"0":1,"type":"string"}}}');

        expect((new SchemaShape())->without($schema, ['0'])->json)->toBe('{"type":"object","properties":{"0":{"type":"string"}}}');
    });

    it('keeps an empty object an object and an empty list a list', function (): void {
        $schema = JsonSchema::fromJson(
            '{"type":"object","properties":{},"required":[],"items":{},"$defs":{"any":{}},"allOf":[],"anyOf":[{}],'
            . '"additionalProperties":{"minimum":1},"default":{},"examples":[],"enum":[[],{}]}',
        );

        expect((new SchemaShape())->without($schema, ['minimum'])->json)->toBe(
            '{"type":"object","properties":{},"required":[],"items":{},"$defs":{"any":{}},"allOf":[],"anyOf":[{}],'
            . '"additionalProperties":{},"default":{},"examples":[],"enum":[[],{}]}',
        );
    });

    it('gives the same schema text when there is nothing to take out', function (array $keywords): void {
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"price":{"type":"number","minimum":1.0,"multipleOf":0.25},"née":{"const":"a/b ü"}}}');

        expect((new SchemaShape())->without($schema, $keywords)->json)->toBe($schema->json);
    })->with([
        'no keywords' => [[]],
        'keywords the schema does not use' => [['maxItems', 'not']],
        'entries that are no names' => [[1, null, ['minimum'], "\0minimum"]],
    ]);

    it('gives a copy of a recursive schema', function (): void {
        $copy = (new SchemaShape())->without(recursiveTreeSchema(), ['minLength', 'maxItems']);

        expect($copy->json)->toBe(
            '{"type":"object","properties":{"root":{"$ref":"#/$defs/node"}},"required":["root"],"additionalProperties":false,'
            . '"$defs":{"node":{"type":"object","properties":{"label":{"type":"string"},'
            . '"children":{"type":"array","items":{"$ref":"#/$defs/node"}}},"required":["label","children"],"additionalProperties":false}}}',
        );
    });

    it('refuses to take out type, which the root of a schema must keep', function (): void {
        expect(fn() => (new SchemaShape())->without(JsonSchema::fromArray(['type' => 'object']), ['type']))
            ->toThrow(InvalidValue::class, 'The root of the schema must have "type": "object".');
    });
});

describe('isRecursive', function (): void {
    it('is false for a schema without a reference that leads back', function (string $json): void {
        expect((new SchemaShape())->isRecursive(JsonSchema::fromJson($json)))->toBeFalse();
    })->with([
        'no reference' => ['{"type":"object","properties":{"a":{"type":"object","properties":{"b":{"type":"string"}}}}}'],
        'one definition used twice' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"},"b":{"$ref":"#/$defs/x"}},"$defs":{"x":{"type":"string"}}}'],
        'definitions in a row' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"}},"$defs":{"x":{"items":{"$ref":"#/$defs/y"}},"y":{"$ref":"#/$defs/z"},"z":{"type":"string"}}}'],
        'a reference to a schema beside it' => ['{"type":"object","properties":{"a":{"type":"string"},"b":{"$ref":"#/properties/a"}}}'],
        'another document' => ['{"type":"object","properties":{"a":{"$ref":"https://schemas.example/node.json"},"b":{"$ref":"other.json#/$defs/x"}}}'],
        'a place that does not exist' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/missing"},"b":{"$ref":"#missing"}}}'],
        'a place where no schema stands' => ['{"type":"object","x-kept":{"node":{"$ref":"#/x-kept/node"}},"properties":{"a":{"$ref":"#/x-kept/node"},"b":{"$ref":"#/required"}},"required":["a"]}'],
        'a reference that is not text' => ['{"type":"object","properties":{"a":{"$ref":7},"b":{"$ref":null},"c":{"$ref":["#"]}}}'],
        'properties named as the reference keywords' => ['{"type":"object","properties":{"$ref":{"type":"string"},"$dynamicRef":{"type":"string"},"$recursiveRef":{"type":"string"}}}'],
        'references inside data' => ['{"type":"object","properties":{"a":{"const":{"$ref":"#"}},"b":{"enum":[{"$recursiveRef":"#"}]},"c":{"default":{"$ref":"#"},"examples":[{"$dynamicRef":"#n"}]}}}'],
    ]);

    it('is true when a reference leads back to a schema that holds it', function (string $json): void {
        expect((new SchemaShape())->isRecursive(JsonSchema::fromJson($json)))->toBeTrue();
    })->with([
        'the root from a property' => ['{"type":"object","properties":{"child":{"$ref":"#"}}}'],
        'the root from itself' => ['{"type":"object","$ref":"#"}'],
        'a definition that holds itself' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/node"}},"$defs":{"node":{"type":"object","properties":{"next":{"$ref":"#/$defs/node"}}}}}'],
        'a definition nothing uses' => ['{"type":"object","$defs":{"node":{"items":{"$ref":"#/$defs/node"}}}}'],
        'definitions in a ring' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"}},"$defs":{"x":{"items":{"$ref":"#/$defs/y"}},"y":{"anyOf":[{"type":"null"},{"$ref":"#/$defs/z"}]},"z":{"$ref":"#/$defs/x"}}}'],
        'a schema around the reference, by pointer' => ['{"type":"object","properties":{"a":{"type":"object","properties":{"b":{"$ref":"#/properties/a"}}}}}'],
        'an item of a list of schemas' => ['{"type":"object","anyOf":[{"type":"object"},{"properties":{"again":{"$ref":"#/anyOf/1"}}}]}'],
        'an anchor' => ['{"type":"object","properties":{"a":{"$anchor":"node","type":"object","properties":{"next":{"$ref":"#node"}}}}}'],
        'an anchor written as an id' => ['{"type":"object","definitions":{"node":{"$id":"#node","properties":{"next":{"$ref":"#node"}}}}}'],
        'the id of a schema inside' => ['{"type":"object","properties":{"a":{"$id":"https://schemas.example/node","properties":{"next":{"$ref":"https://schemas.example/node"}}}}}'],
        'the nearest id, not the root' => ['{"type":"object","$defs":{"x":{"$id":"https://schemas.example/x","$defs":{"y":{"items":{"$ref":"#/$defs/y"}}}}}}'],
        'a pointer with escaped names' => ['{"type":"object","$defs":{"a/b~c d":{"items":{"$ref":"#/$defs/a~1b~0c%20d"}}}}'],
        'a property with a number as its name' => ['{"type":"object","properties":{"0":{"type":"object","properties":{"1":{"$ref":"#/properties/0"}}}}}'],
        'an id as the oldest drafts wrote it' => ['{"type":"object","definitions":{"node":{"id":"https://schemas.example/node","properties":{"next":{"$ref":"https://schemas.example/node"}}}}}'],
        'an anchor as the oldest drafts wrote it' => ['{"type":"object","definitions":{"node":{"id":"#node","properties":{"next":{"$ref":"#node"}}}}}'],
        'a pointer from the root, under a schema with an id of its own' => ['{"type":"object","properties":{"a":{"$id":"https://schemas.example/a","properties":{"next":{"$ref":"#/properties/a"}}}}}'],
        'a dynamic reference' => ['{"type":"object","properties":{"a":{"$dynamicRef":"#node"}}}'],
        'a recursive reference' => ['{"type":"object","properties":{"a":{"$recursiveRef":"#"}}}'],
    ]);

    it('reads a property with a number as its name from a schema built from an array', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => ['0' => ['type' => 'object', 'properties' => ['1' => ['$ref' => '#/properties/0']]]],
        ]);

        expect((new SchemaShape())->isRecursive($schema))->toBeTrue();
    });

    it('is true for the tree schema', function (): void {
        expect((new SchemaShape())->isRecursive(recursiveTreeSchema()))->toBeTrue();
    });

    // Known limits (G88): a recursion written in one of these ways is not seen.
    it('does not see a recursion it cannot place in the schema', function (string $json): void {
        expect((new SchemaShape())->isRecursive(JsonSchema::fromJson($json)))->toBeFalse()
            ->and((new SchemaShape())->depth(JsonSchema::fromJson($json)))->toBe(2);
    })->with([
        'a relative reference against the address of the root' => ['{"$id":"https://schemas.example/tree.json","type":"object","properties":{"child":{"$ref":"tree.json"}}}'],
        'a relative id' => ['{"$id":"https://schemas.example/a/","type":"object","properties":{"node":{"$id":"node","$ref":"https://schemas.example/a/node"}}}'],
        'a ring through a keyword that holds no schema' => ['{"type":"object","x-kept":{"node":{"properties":{"next":{"$ref":"#/x-kept/node"}}}},"properties":{"a":{"$ref":"#/x-kept/node"}}}'],
    ]);

    it('ends on a chain of 60,000 definitions, with and without the reference that closes the ring', function (): void {
        $definitions = [];
        for ($i = 0; $i < 60_000; $i++) {
            $definitions['d' . $i] = ['$ref' => '#/$defs/d' . ($i + 1)];
        }
        $definitions['d60000'] = ['type' => 'string'];
        $open = JsonSchema::fromArray(['type' => 'object', 'properties' => ['a' => ['$ref' => '#/$defs/d0']], '$defs' => $definitions]);

        $definitions['d60000'] = ['$ref' => '#/$defs/d0'];
        $ring = JsonSchema::fromArray(['type' => 'object', 'properties' => ['a' => ['$ref' => '#/$defs/d0']], '$defs' => $definitions]);

        expect((new SchemaShape())->isRecursive($open))->toBeFalse()
            ->and((new SchemaShape())->isRecursive($ring))->toBeTrue();
    });
});

describe('everyPropertyIsRequired', function (): void {
    it('is true when every name under every properties is in the required beside it', function (string $json): void {
        expect((new SchemaShape())->everyPropertyIsRequired(JsonSchema::fromJson($json)))->toBeTrue();
    })->with([
        'no properties' => ['{"type":"object"}'],
        'empty properties' => ['{"type":"object","properties":{}}'],
        'all required, at every level' => ['{"type":"object","properties":{"a":{"type":"object","properties":{"b":{}},"required":["b"]},"c":{}},"required":["c","a"]}'],
        'required names more than the properties' => ['{"type":"object","properties":{"a":{}},"required":["a","z"]}'],
        'numbers as names' => ['{"type":"object","properties":{"0":{},"10":{}},"required":["0","10"]}'],
        'properties named required and properties' => ['{"type":"object","properties":{"required":{"type":"array"},"properties":{"type":"string"}},"required":["required","properties"]}'],
        'optional-looking data' => ['{"type":"object","properties":{"a":{"enum":[{"properties":{"x":{}}}],"default":{"properties":{"y":{}}}},"b":{"const":{"properties":{"x":{}}},"examples":[{"properties":{"y":{}}}]}},"required":["a","b"]}'],
    ]);

    it('is false when a name under a properties is not in the required beside it', function (string $json): void {
        expect((new SchemaShape())->everyPropertyIsRequired(JsonSchema::fromJson($json)))->toBeFalse();
    })->with([
        'no required' => ['{"type":"object","properties":{"a":{}}}'],
        'one left out' => ['{"type":"object","properties":{"a":{},"b":{}},"required":["a"]}'],
        'inside a property' => ['{"type":"object","properties":{"a":{"type":"object","properties":{"b":{}}}},"required":["a"]}'],
        'inside a definition' => ['{"type":"object","$defs":{"x":{"properties":{"b":{}},"required":[]}}}'],
        'inside a branch' => ['{"type":"object","anyOf":[{"properties":{"b":{}}}]}'],
        'the required of the schema around it does not count' => ['{"type":"object","properties":{"a":{"properties":{"b":{}}}},"required":["a","b"]}'],
        'a number as a name' => ['{"type":"object","properties":{"0":{},"1":{}},"required":["0"]}'],
        'required holds the number, not the name' => ['{"type":"object","properties":{"0":{}},"required":[0]}'],
        'required is not a list' => ['{"type":"object","properties":{"a":{}},"required":"a"}'],
    ]);

    it('reads numbers as names from a schema built from an array', function (): void {
        $properties = ['0' => ['type' => 'string'], '1' => ['type' => 'string']];

        expect((new SchemaShape())->everyPropertyIsRequired(JsonSchema::fromArray(['type' => 'object', 'properties' => $properties, 'required' => ['0', '1']])))->toBeTrue()
            ->and((new SchemaShape())->everyPropertyIsRequired(JsonSchema::fromArray(['type' => 'object', 'properties' => $properties, 'required' => ['1']])))->toBeFalse();
    });

    it('answers for a recursive schema', function (): void {
        expect((new SchemaShape())->everyPropertyIsRequired(recursiveTreeSchema()))->toBeTrue()
            ->and((new SchemaShape())->everyPropertyIsRequired(JsonSchema::fromJson('{"type":"object","properties":{"child":{"$ref":"#"}}}')))->toBeFalse();
    });
});

describe('everyObjectIsClosed', function (): void {
    it('is true when every object has additionalProperties false', function (string $json): void {
        expect((new SchemaShape())->everyObjectIsClosed(JsonSchema::fromJson($json)))->toBeTrue();
    })->with([
        'the root alone' => ['{"type":"object","additionalProperties":false}'],
        'at every level' => ['{"type":"object","additionalProperties":false,"properties":{"a":{"type":["object","null"],"additionalProperties":false},"b":{"type":"array","items":{"properties":{},"additionalProperties":false}}}}'],
        'schemas that are no object' => ['{"type":"object","additionalProperties":false,"properties":{"a":{"type":"string"},"b":{},"c":{"$ref":"#/$defs/d"},"e":{"type":"array","properties":{"x":{}}}},"$defs":{"d":{"enum":[1]}}}'],
        'a property named additionalProperties' => ['{"type":"object","additionalProperties":false,"properties":{"additionalProperties":{"type":"boolean"}}}'],
        'open-looking data' => ['{"type":"object","additionalProperties":false,"properties":{"a":{"enum":[{"type":"object"}],"default":{"type":"object"}},"b":{"const":{"type":"object"},"examples":[{"properties":{}}]}}}'],
        'numbers as names' => ['{"type":"object","additionalProperties":false,"properties":{"0":{"type":"object","additionalProperties":false}}}'],
    ]);

    it('is false when an object does not have it', function (string $json): void {
        expect((new SchemaShape())->everyObjectIsClosed(JsonSchema::fromJson($json)))->toBeFalse();
    })->with([
        'the root' => ['{"type":"object"}'],
        'true' => ['{"type":"object","additionalProperties":true}'],
        'a schema' => ['{"type":"object","additionalProperties":{"type":"string"}}'],
        'a property' => ['{"type":"object","additionalProperties":false,"properties":{"a":{"type":"object"}}}'],
        'an object by its properties' => ['{"type":"object","additionalProperties":false,"properties":{"a":{"properties":{"b":{}}}}}'],
        'an object by its required' => ['{"type":"object","additionalProperties":false,"anyOf":[{"required":["a"]}]}'],
        'an object among other types' => ['{"type":"object","additionalProperties":false,"properties":{"a":{"type":["null","object"]}}}'],
        'a definition' => ['{"type":"object","additionalProperties":false,"$defs":{"x":{"type":"object"}}}'],
        'the items of a list' => ['{"type":"object","additionalProperties":false,"properties":{"a":{"type":"array","items":{"type":"object"}}}}'],
        'only unevaluatedProperties' => ['{"type":"object","unevaluatedProperties":false}'],
        'a number as a name' => ['{"type":"object","additionalProperties":false,"properties":{"0":{"type":"object"}}}'],
    ]);

    it('reads numbers as names from a schema built from an array', function (): void {
        $closed = ['type' => 'object', 'additionalProperties' => false];

        expect((new SchemaShape())->everyObjectIsClosed(JsonSchema::fromArray($closed + ['properties' => ['0' => $closed, '1' => $closed]])))->toBeTrue()
            ->and((new SchemaShape())->everyObjectIsClosed(JsonSchema::fromArray($closed + ['properties' => ['0' => $closed, '1' => ['type' => 'object']]])))->toBeFalse();
    });

    it('answers for a recursive schema', function (): void {
        expect((new SchemaShape())->everyObjectIsClosed(recursiveTreeSchema()))->toBeTrue()
            ->and((new SchemaShape())->everyObjectIsClosed(JsonSchema::fromJson('{"type":"object","additionalProperties":false,"properties":{"child":{"type":"object","properties":{"again":{"$ref":"#"}}}}}')))->toBeFalse();
    });
});

describe('everyObjectHasARequiredProperty', function (): void {
    it('is true when every object names at least one property in required', function (string $json): void {
        expect((new SchemaShape())->everyObjectHasARequiredProperty(JsonSchema::fromJson($json)))->toBeTrue();
    })->with([
        'the root alone' => ['{"type":"object","properties":{"a":{},"b":{}},"required":["b"]}'],
        'at every level' => ['{"type":"object","required":["a"],"properties":{"a":{"type":"object","required":["b"],"properties":{"b":{"type":"array","items":{"type":"object","required":["c"]}}}}}}'],
        'schemas that are no object' => ['{"type":"object","required":["a"],"properties":{"a":{"type":"string"},"b":{},"c":{"type":"array"}}}'],
        'a property named required' => ['{"type":"object","required":["required"],"properties":{"required":{"type":"boolean"}}}'],
        'object-looking data' => ['{"type":"object","required":["a"],"properties":{"a":{"enum":[{"type":"object"}],"default":{"type":"object"}},"b":{"const":{"type":"object"},"examples":[{"type":"object"}]}}}'],
        'numbers as names' => ['{"type":"object","required":["0"],"properties":{"0":{"type":"object","required":["1"],"properties":{"1":{}}}}}'],
    ]);

    it('is false when an object names none', function (string $json): void {
        expect((new SchemaShape())->everyObjectHasARequiredProperty(JsonSchema::fromJson($json)))->toBeFalse();
    })->with([
        'no required' => ['{"type":"object","properties":{"a":{}}}'],
        'an empty required' => ['{"type":"object","properties":{"a":{}},"required":[]}'],
        'a required without a name' => ['{"type":"object","properties":{"a":{}},"required":[0,null]}'],
        'a property' => ['{"type":"object","required":["a"],"properties":{"a":{"type":"object","properties":{"b":{}}}}}'],
        'an object by its properties' => ['{"type":"object","required":["a"],"properties":{"a":{"properties":{"b":{}}}}}'],
        'a definition' => ['{"type":"object","required":["a"],"$defs":{"x":{"type":"object"}}}'],
        'a branch' => ['{"type":"object","required":["a"],"oneOf":[{"type":"object","required":["b"]},{"type":"object"}]}'],
        'a number as a name' => ['{"type":"object","required":["0"],"properties":{"0":{"type":"object","properties":{"1":{}}}}}'],
    ]);

    it('reads numbers as names from a schema built from an array', function (): void {
        $inner = ['type' => 'object', 'properties' => ['5' => ['type' => 'string']]];

        expect((new SchemaShape())->everyObjectHasARequiredProperty(JsonSchema::fromArray(['type' => 'object', 'required' => ['0'], 'properties' => ['0' => $inner + ['required' => ['5']]]])))->toBeTrue()
            ->and((new SchemaShape())->everyObjectHasARequiredProperty(JsonSchema::fromArray(['type' => 'object', 'required' => ['0'], 'properties' => ['0' => $inner]])))->toBeFalse();
    });

    it('answers for a recursive schema', function (): void {
        expect((new SchemaShape())->everyObjectHasARequiredProperty(recursiveTreeSchema()))->toBeTrue()
            ->and((new SchemaShape())->everyObjectHasARequiredProperty(JsonSchema::fromJson('{"type":"object","properties":{"child":{"$ref":"#"}}}')))->toBeFalse();
    });
});

describe('depth', function (): void {
    it('counts the schemas that stand inside one another, the root as 1', function (string $json, int $depth): void {
        expect((new SchemaShape())->depth(JsonSchema::fromJson($json)))->toBe($depth);
    })->with([
        'the root alone' => ['{"type":"object"}', 1],
        'empty properties' => ['{"type":"object","properties":{}}', 1],
        'one property' => ['{"type":"object","properties":{"a":{"type":"string"}}}', 2],
        'the deepest of several' => ['{"type":"object","properties":{"a":{"type":"string"},"b":{"type":"array","items":{"type":"object","properties":{"c":{}}}},"d":{}}}', 4],
        'a branch is a level' => ['{"type":"object","properties":{"a":{"anyOf":[{"type":"string"},{"type":"null"}]}}}', 3],
        'a schema that is true or false is none' => ['{"type":"object","additionalProperties":false,"properties":{"a":true}}', 1],
        'a definition is a level' => ['{"type":"object","$defs":{"x":{"type":"object","properties":{"a":{}}}}}', 3],
        'a reference goes on at the schema it names' => ['{"type":"object","properties":{"a":{"type":"array","items":{"$ref":"#/$defs/x"}}},"$defs":{"x":{"type":"object","properties":{"b":{"type":"array","items":{}}}}}}', 5],
        'references in a row are one level' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"}},"$defs":{"x":{"$ref":"#/$defs/y"},"y":{"$ref":"#/$defs/z"},"z":{"type":"string"}}}', 2],
        'a definition used twice' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"},"b":{"items":{"items":{"$ref":"#/$defs/x"}}}},"$defs":{"x":{"properties":{"c":{}}}}}', 5],
        'a reference that cannot be followed' => ['{"type":"object","properties":{"a":{"$ref":"https://schemas.example/deep.json"},"b":{"$ref":"#/$defs/missing"}}}', 2],
        'keywords as names of properties' => ['{"type":"object","properties":{"properties":{"type":"object","properties":{"items":{"type":"string"}}}}}', 3],
        'data is no schema' => ['{"type":"object","properties":{"a":{"enum":[{"properties":{"x":{"properties":{"y":{}}}}}],"default":{"items":{"items":{}}}},"b":{"const":{"items":{"items":{}}},"examples":[{"items":{"items":{}}}]}}}', 2],
        'numbers as names' => ['{"type":"object","properties":{"0":{"type":"object","properties":{"1":{"type":"object","properties":{"2":{}}}}}}}', 4],
        'the root refers to itself from a property' => ['{"type":"object","properties":{"child":{"$ref":"#"}}}', 2],
        'a ring of definitions, as written' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"}},"$defs":{"x":{"items":{"$ref":"#/$defs/y"}},"y":{"items":{"$ref":"#/$defs/x"}}}}', 4],
    ]);

    it('counts the tree schema as it is written', function (): void {
        // root 1, the definition "node" 2 (and "root", which refers to it), "children" 3, its items 4.
        expect((new SchemaShape())->depth(recursiveTreeSchema()))->toBe(4);
    });

    it('reads numbers as names from a schema built from an array', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => ['0' => ['type' => 'object', 'properties' => ['1' => ['type' => 'string']]], '1' => ['type' => 'string']],
        ]);

        expect((new SchemaShape())->depth($schema))->toBe(3);
    });

    it('counts a schema nested as deep as a schema can be: 255 properties inside one another, 511 levels of JSON', function (): void {
        $nested = static function (int $levels): string {
            $json = '{"type":"string"}';
            for ($i = 0; $i < $levels; $i++) {
                $json = '{"type":"object","properties":{"a":' . $json . '}}';
            }

            return $json;
        };
        $schema = JsonSchema::fromJson($nested(255));
        $shape = new SchemaShape();

        expect($shape->depth($schema))->toBe(256)
            ->and($shape->propertyCount($schema))->toBe(255)
            ->and($shape->uses($schema, ['type', 'minimum']))->toBe(['type'])
            ->and($shape->valuesOf($schema, 'type'))->toHaveCount(256)
            ->and($shape->isRecursive($schema))->toBeFalse()
            ->and($shape->mayTighten($schema, ['type']))->toBeFalse()
            ->and($shape->renamed($schema, 'properties', 'x-properties')->json)->toBe(str_replace('"properties"', '"x-properties"', $nested(255)))
            ->and($shape->without($schema, ['properties'])->json)->toBe('{"type":"object"}')
            ->and(fn() => JsonSchema::fromJson($nested(256)))->toThrow(InvalidValue::class);
    });

    it('follows a chain of 60,000 definitions that each hold the next', function (): void {
        $definitions = [];
        for ($i = 0; $i < 60_000; $i++) {
            $definitions['d' . $i] = ['type' => 'object', 'properties' => ['next' => ['$ref' => '#/$defs/d' . ($i + 1)]]];
        }
        $definitions['d60000'] = ['type' => 'string'];
        $open = JsonSchema::fromArray(['type' => 'object', '$defs' => $definitions]);

        $definitions['d60000'] = ['$ref' => '#/$defs/d0'];
        $ring = JsonSchema::fromArray(['type' => 'object', '$defs' => $definitions]);

        // root 1, "d0" 2, its "next" 3 and with it "d1", …: "d60000" stands at level 60,002.
        expect((new SchemaShape())->depth($open))->toBe(60_002)
            ->and((new SchemaShape())->depth($ring))->toBe(60_002);
    });
});

describe('propertyCount', function (): void {
    it('counts the names under every properties', function (string $json, int $count): void {
        expect((new SchemaShape())->propertyCount(JsonSchema::fromJson($json)))->toBe($count);
    })->with([
        'none' => ['{"type":"object"}', 0],
        'empty properties' => ['{"type":"object","properties":{}}', 0],
        'the root' => ['{"type":"object","properties":{"a":{},"b":{},"c":true}}', 3],
        'every level, definitions and branches' => ['{"type":"object","properties":{"a":{"type":"object","properties":{"b":{},"c":{}}},"d":{"items":{"properties":{"e":{}}}}},"$defs":{"x":{"properties":{"f":{},"g":{}}}},"anyOf":[{"properties":{"h":{}}}]}', 8],
        'a definition counts once, however often it is used' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"},"b":{"$ref":"#/$defs/x"}},"$defs":{"x":{"properties":{"c":{}}}}}', 3],
        'pattern properties are no names' => ['{"type":"object","properties":{"a":{}},"patternProperties":{"^b":{},"^c":{}}}', 1],
        'a property named properties' => ['{"type":"object","properties":{"properties":{"type":"object","properties":{"a":{},"properties":{}}}}}', 3],
        'data is no schema' => ['{"type":"object","properties":{"a":{"enum":[{"properties":{"x":{},"y":{}}}],"default":{"properties":{"x":{}}}},"b":{"const":{"properties":{"x":{}}},"examples":[{"properties":{"x":{}}}]}}}', 2],
        'numbers as names' => ['{"type":"object","properties":{"0":{},"1":{"properties":{"0":{},"7":{}}},"x":{}}}', 5],
        'properties that is no object' => ['{"type":"object","properties":{"a":{"properties":["b","c"]}}}', 1],
    ]);

    it('reads numbers as names from a schema built from an array', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => ['0' => ['type' => 'string'], '1' => ['type' => 'object', 'properties' => ['0' => ['type' => 'string']]]],
        ]);

        expect((new SchemaShape())->propertyCount($schema))->toBe(3);
    });

    it('counts a recursive schema as it is written', function (): void {
        expect((new SchemaShape())->propertyCount(recursiveTreeSchema()))->toBe(3);
    });

    it('answers for an object of 60,000 properties', function (): void {
        $properties = [];
        for ($i = 0; $i < 60_000; $i++) {
            $properties['p' . $i] = ['type' => 'string', 'minLength' => 1];
        }
        $names = array_keys($properties);
        $all = JsonSchema::fromArray(['type' => 'object', 'properties' => $properties, 'required' => $names]);
        $butOne = JsonSchema::fromArray(['type' => 'object', 'properties' => $properties, 'required' => array_slice($names, 0, -1)]);
        $shape = new SchemaShape();

        expect($shape->propertyCount($all))->toBe(60_000)
            ->and($shape->everyPropertyIsRequired($all))->toBeTrue()
            ->and($shape->everyPropertyIsRequired($butOne))->toBeFalse()
            ->and($shape->uses($all, ['minLength', 'maxLength']))->toBe(['minLength'])
            ->and($shape->valuesOf($all, 'minLength'))->toHaveCount(60_000)
            ->and($shape->mayTighten($all, ['minLength']))->toBeFalse()
            ->and($shape->depth($all))->toBe(2);
    });
});

describe('renamed', function (): void {
    it('gives a copy with the keyword under its new name at every place a schema stands, in the same position, and leaves the schema of the caller as it was', function (): void {
        $schema = JsonSchema::fromJson(
            '{"type":"object","oneOf":[{"required":["a"]},{"required":["b"]}],"properties":{"a":{"oneOf":[{"type":"string"},{"type":"null"}],"title":"A"},'
            . '"b":{"type":"array","items":{"oneOf":[{"$ref":"#/$defs/c"}]}}},"$defs":{"c":{"0":1,"oneOf":[{"const":1}],"z":2}}}',
        );
        $before = $schema->json;

        $copy = (new SchemaShape())->renamed($schema, 'oneOf', 'anyOf');

        expect($copy->json)->toBe(
            '{"type":"object","anyOf":[{"required":["a"]},{"required":["b"]}],"properties":{"a":{"anyOf":[{"type":"string"},{"type":"null"}],"title":"A"},'
            . '"b":{"type":"array","items":{"anyOf":[{"$ref":"#/$defs/c"}]}}},"$defs":{"c":{"0":1,"anyOf":[{"const":1}],"z":2}}}',
        )
            ->and($copy)->not->toBe($schema)
            ->and($schema->json)->toBe($before);
    });

    it('leaves a property whose name is the keyword, and its name in required', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'oneOf' => ['type' => 'string', 'oneOf' => [['minLength' => 1]]],
                'anyOf' => ['type' => 'string'],
            ],
            'required' => ['oneOf'],
            '$defs' => ['oneOf' => ['type' => 'integer']],
        ]);

        expect((new SchemaShape())->renamed($schema, 'oneOf', 'anyOf')->json)->toBe(
            '{"type":"object","properties":{"oneOf":{"type":"string","anyOf":[{"minLength":1}]},"anyOf":{"type":"string"}},'
            . '"required":["oneOf"],"$defs":{"oneOf":{"type":"integer"}}}',
        );
    });

    it('does not touch what stands under enum, const, default and examples', function (string $keyword): void {
        $schema = schemaWithKeywordsAsNamesAndData();

        expect((new SchemaShape())->renamed($schema, $keyword, 'x-other')->json)->toBe($schema->json);
    })->with(['minimum', 'not', 'maxLength', 'uniqueItems', '$dynamicRef', '$ref', 'empty', 'a', 'b']);

    it('does not touch what stands under a keyword that holds no schema', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","x-notes":{"oneOf":[],"map":{}},"dependentRequired":{"oneOf":["a"]}}');

        expect((new SchemaShape())->renamed($schema, 'oneOf', 'anyOf')->json)->toBe($schema->json);
    });

    it('keeps properties with numbers as names a JSON object', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                '0' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                '1' => ['type' => 'object', 'properties' => ['7' => ['oneOf' => [['type' => 'integer']]]], 'required' => ['7']],
            ],
            'required' => ['0', '1'],
        ]);

        expect((new SchemaShape())->renamed($schema, 'oneOf', 'anyOf')->json)->toBe(
            '{"type":"object","properties":{"0":{"anyOf":[{"type":"string"},{"type":"null"}]},'
            . '"1":{"type":"object","properties":{"7":{"anyOf":[{"type":"integer"}]}},"required":["7"]}},"required":["0","1"]}',
        )
            ->and((new SchemaShape())->renamed($schema, '0', 'zero')->json)->toBe($schema->json)
            ->and((new SchemaShape())->renamed($schema, '7', 'seven')->json)->toBe($schema->json);
    });

    it('renames a keyword that is a number where it stands as one, and can give a number as the new name', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","0":true,"properties":{"0":{"0":1,"type":"string"}}}');

        expect((new SchemaShape())->renamed($schema, '0', '1')->json)->toBe('{"type":"object","1":true,"properties":{"0":{"1":1,"type":"string"}}}');
    });

    it('keeps an empty object an object and an empty list a list', function (): void {
        $schema = JsonSchema::fromJson(
            '{"type":"object","properties":{},"required":[],"items":{},"$defs":{"any":{"oneOf":[]}},"allOf":[],"oneOf":[{}],'
            . '"additionalProperties":{"oneOf":[{},{"properties":{}}]},"default":{},"examples":[],"enum":[[],{}]}',
        );

        expect((new SchemaShape())->renamed($schema, 'oneOf', 'anyOf')->json)->toBe(
            '{"type":"object","properties":{},"required":[],"items":{},"$defs":{"any":{"anyOf":[]}},"allOf":[],"anyOf":[{}],'
            . '"additionalProperties":{"anyOf":[{},{"properties":{}}]},"default":{},"examples":[],"enum":[[],{}]}',
        );
    });

    it('gives the same schema text when there is nothing to rename', function (string $keyword, string $as): void {
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"price":{"type":"number","minimum":1.0,"oneOf":[{"multipleOf":0.25}]},"née":{"const":"a/b ü"}}}');

        expect((new SchemaShape())->renamed($schema, $keyword, $as)->json)->toBe($schema->json);
    })->with([
        'a keyword the schema does not use' => ['not', 'anyOf'],
        'the same name' => ['oneOf', 'oneOf'],
        'a name no member can have' => ["\0oneOf", 'anyOf'],
    ]);

    it('leaves a schema that has both names as it is, so that the old name still shows', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"a":{"oneOf":[{"type":"string"}],"anyOf":[{"minLength":1}]},"b":{"oneOf":[{"type":"null"}]}}}');

        $copy = (new SchemaShape())->renamed($schema, 'oneOf', 'anyOf');

        expect($copy->json)->toBe('{"type":"object","properties":{"a":{"oneOf":[{"type":"string"}],"anyOf":[{"minLength":1}]},"b":{"anyOf":[{"type":"null"}]}}}')
            ->and((new SchemaShape())->uses($copy, ['oneOf']))->toBe(['oneOf']);
    });

    it('gives a copy of a recursive schema', function (): void {
        expect((new SchemaShape())->renamed(recursiveTreeSchema(), 'maxItems', 'x-most')->json)->toBe(
            '{"type":"object","properties":{"root":{"$ref":"#/$defs/node"}},"required":["root"],"additionalProperties":false,'
            . '"$defs":{"node":{"type":"object","properties":{"label":{"type":"string","minLength":1},'
            . '"children":{"type":"array","items":{"$ref":"#/$defs/node"},"x-most":5}},"required":["label","children"],"additionalProperties":false}}}',
        );
    });

    it('refuses a new name no member can have, and a root that loses its type', function (): void {
        $schema = JsonSchema::fromArray(['type' => 'object']);

        expect(fn() => (new SchemaShape())->renamed($schema, 'oneOf', "\0anyOf"))->toThrow(InvalidValue::class, 'The new name of a keyword cannot start with a NUL byte.')
            ->and(fn() => (new SchemaShape())->renamed($schema, 'type', 'kind'))->toThrow(InvalidValue::class, 'The root of the schema must have "type": "object".');
    });
});

describe('valuesOf', function (): void {
    it('gives the value of the keyword at every place a schema stands, in the order of the schema', function (): void {
        $schema = JsonSchema::fromJson(
            '{"type":"object","properties":{"tags":{"type":"array","minItems":2,"items":{"type":"string","pattern":"^a","enum":["x",["y"],{"z":1}]}},'
            . '"when":{"type":"string","format":"date-time"},"more":{"anyOf":[{"type":"array","minItems":0},{"$ref":"other.json#/a"}]}},'
            . '"$defs":{"list":{"type":"array","minItems":1},"id":{"type":"string","format":"uuid","pattern":"x$"}}}',
        );
        $shape = new SchemaShape();

        expect($shape->valuesOf($schema, 'minItems'))->toBe([2, 0, 1])
            ->and($shape->valuesOf($schema, 'pattern'))->toBe(['^a', 'x$'])
            ->and($shape->valuesOf($schema, 'format'))->toBe(['date-time', 'uuid'])
            ->and($shape->valuesOf($schema, '$ref'))->toBe(['other.json#/a'])
            ->and($shape->valuesOf($schema, 'enum'))->toBe([['x', ['y'], ['z' => 1]]])
            ->and($shape->valuesOf($schema, 'type'))->toBe(['object', 'array', 'string', 'string', 'array', 'array', 'string'])
            ->and($shape->valuesOf($schema, 'maxItems'))->toBe([]);
    });

    it('gives a value as the caller wrote it, whatever its type', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"a":{"minItems":"two"},"b":{"minItems":null},"c":{"minItems":1.5},"d":{"minItems":{}},"e":{"minItems":[]},"f":{"minItems":false}}}');

        expect((new SchemaShape())->valuesOf($schema, 'minItems'))->toBe(['two', null, 1.5, [], [], false]);
    });

    it('does not read the name of a property as a keyword', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => ['minItems' => ['type' => 'integer'], 'pattern' => ['type' => 'string', 'pattern' => '^p'], 'enum' => ['type' => 'array']],
            '$defs' => ['format' => ['type' => 'string']],
            'required' => ['minItems', 'pattern'],
        ]);
        $shape = new SchemaShape();

        expect($shape->valuesOf($schema, 'minItems'))->toBe([])
            ->and($shape->valuesOf($schema, 'pattern'))->toBe(['^p'])
            ->and($shape->valuesOf($schema, 'enum'))->toBe([])
            ->and($shape->valuesOf($schema, 'format'))->toBe([]);
    });

    it('does not read what stands under enum, const, default and examples', function (string $keyword): void {
        expect((new SchemaShape())->valuesOf(schemaWithKeywordsAsNamesAndData(), $keyword))->toBe([]);
    })->with(['minimum', 'not', 'maxLength', 'uniqueItems', '$dynamicRef', '$ref', 'empty', 'a', 'b']);

    it('gives the data under enum, const, default and examples when asked for that keyword', function (): void {
        $shape = new SchemaShape();
        $schema = schemaWithKeywordsAsNamesAndData();

        expect($shape->valuesOf($schema, 'enum'))->toBe([[['minimum' => 1, 'properties' => ['a' => ['maxLength' => 2]]], 'plain']])
            ->and($shape->valuesOf($schema, 'const'))->toBe([['type' => 'object', 'not' => ['minimum' => 3], '$ref' => '#']])
            ->and($shape->valuesOf($schema, 'default'))->toBe([['maxLength' => 4, 'properties' => ['b' => []], 'empty' => []]])
            ->and($shape->valuesOf($schema, 'examples'))->toBe([[['uniqueItems' => true, '$dynamicRef' => '#x']]]);
    });

    it('does not read what stands under a keyword that holds no schema', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","x-notes":{"minItems":3},"dependentRequired":{"minItems":["a"]}}');

        expect((new SchemaShape())->valuesOf($schema, 'minItems'))->toBe([]);
    });

    it('reads the schemas of properties with numbers as names, and no number as a keyword', function (): void {
        $schema = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                '0' => ['type' => 'array', 'minItems' => 2],
                '1' => ['type' => 'object', 'properties' => ['7' => ['type' => 'array', 'minItems' => 0]]],
            ],
        ]);
        $shape = new SchemaShape();

        expect($shape->valuesOf($schema, 'minItems'))->toBe([2, 0])
            ->and($shape->valuesOf($schema, '0'))->toBe([])
            ->and($shape->valuesOf($schema, '7'))->toBe([])
            ->and($shape->valuesOf(JsonSchema::fromJson('{"type":"object","0":"here","properties":{"0":{"0":["there"]}}}'), '0'))->toBe(['here', ['there']]);
    });

    it('gives nothing for a name no member can have', function (): void {
        expect((new SchemaShape())->valuesOf(JsonSchema::fromArray(['type' => 'object']), "\0type"))->toBe([]);
    });

    it('answers for a recursive schema', function (): void {
        $shape = new SchemaShape();

        expect($shape->valuesOf(recursiveTreeSchema(), '$ref'))->toBe(['#/$defs/node', '#/$defs/node'])
            ->and($shape->valuesOf(recursiveTreeSchema(), 'required'))->toBe([['root'], ['label', 'children']]);
    });
});

describe('mayTighten', function (): void {
    it('is true where taking the keywords out makes the validator refuse an answer the schema of the caller allows', function (string $json, array $keywords, string $answer): void {
        $schema = JsonSchema::fromJson($json);
        $shape = new SchemaShape();

        // The proof that the case is real: allowed by the caller's schema, refused by the copy.
        expect(schemaAllows($schema, $answer))->toBeTrue()
            ->and(schemaAllows($shape->without($schema, $keywords), $answer))->toBeFalse()
            ->and($shape->mayTighten($schema, $keywords))->toBeTrue();
    })->with([
        'under not' => ['{"type":"object","properties":{"n":{"not":{"type":"integer","minimum":5}}}}', ['minimum'], '{"n":3}'],
        'under if' => ['{"type":"object","if":{"properties":{"n":{"minimum":5}}},"then":{"required":["x"]}}', ['minimum'], '{"n":3}'],
        'under contains beside maxContains' => ['{"type":"object","properties":{"l":{"type":"array","contains":{"minimum":5},"maxContains":1}}}', ['minimum'], '{"l":[1,2,9]}'],
        'in a branch of oneOf' => ['{"type":"object","properties":{"l":{"oneOf":[{"type":"string","maxLength":3},{"type":"string","minLength":4}]}}}', ['maxLength', 'minLength'], '{"l":"abc"}'],
        'in a definition that a schema under not refers to' => ['{"type":"object","properties":{"n":{"not":{"$ref":"#/$defs/big"}}},"$defs":{"big":{"type":"integer","minimum":5}}}', ['minimum'], '{"n":3}'],
        'deeper under not' => ['{"type":"object","not":{"properties":{"l":{"type":"array","items":{"anyOf":[{"type":"integer","maximum":1}]}}},"required":["l"]}}', ['maximum'], '{"l":[5]}'],
        'a property with a number as its name, under not' => ['{"type":"object","properties":{"0":{"not":{"type":"integer","minimum":5}}}}', ['minimum'], '{"0":3}'],
        'minContains that says none is needed' => ['{"type":"object","properties":{"l":{"type":"array","contains":{"type":"string"},"minContains":0}}}', ['minContains'], '{"l":[1]}'],
        'properties beside additionalProperties' => ['{"type":"object","properties":{"a":{"type":"string"}},"additionalProperties":false}', ['properties'], '{"a":"x"}'],
        'patternProperties beside additionalProperties' => ['{"type":"object","patternProperties":{"^a":{"type":"string"}},"additionalProperties":{"type":"integer"}}', ['patternProperties'], '{"a":"x"}'],
        'prefixItems beside items' => ['{"type":"object","properties":{"l":{"type":"array","prefixItems":[{"type":"string"}],"items":{"type":"integer"}}}}', ['prefixItems'], '{"l":["a",1]}'],
        'a keyword that looks at properties, in a schema with unevaluatedProperties' => ['{"type":"object","allOf":[{"properties":{"a":{"type":"string"}}}],"unevaluatedProperties":false}', ['allOf'], '{"a":"x"}'],
    ]);

    it('is true where writing oneOf as anyOf makes the validator refuse an answer the schema of the caller allows', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"n":{"not":{"oneOf":[{"type":"integer"},{"minimum":0}]}}}}');
        $shape = new SchemaShape();

        expect(schemaAllows($schema, '{"n":3}'))->toBeTrue()
            ->and(schemaAllows($shape->renamed($schema, 'oneOf', 'anyOf'), '{"n":3}'))->toBeFalse()
            ->and($shape->mayTighten($schema, ['oneOf']))->toBeTrue();
    });

    it('is true where the copy is not the one it reasons about, or a reference can be read in two ways', function (string $json, array $keywords, string $answer): void {
        $schema = JsonSchema::fromJson($json);
        $shape = new SchemaShape();

        // The copy as an adapter makes it: the keywords taken out, and "oneOf", when among them, written as "anyOf".
        $copy = $shape->without($schema, array_values(array_diff($keywords, ['oneOf'])));
        $copy = in_array('oneOf', $keywords, true) ? $shape->renamed($copy, 'oneOf', 'anyOf') : $copy;

        expect(schemaAllows($schema, $answer))->toBeTrue()
            ->and(schemaAllows($copy, $answer))->toBeFalse()
            ->and($shape->mayTighten($schema, $keywords))->toBeTrue();
    })->with([
        'an id of the oldest drafts between a reference and its place' => ['{"type":"object","properties":{"a":{"id":"x","$defs":{"d":{"type":"integer"}},"not":{"$ref":"#/$defs/d"}}},"$defs":{"d":{"minimum":5}}}', ['minimum'], '{"a":3}'],
        'an anchor that stands twice' => ['{"type":"object","$defs":{"p":{"$anchor":"x","type":"integer"},"r":{"$id":"http://e.example/r","$defs":{"q":{"$anchor":"x","minimum":5}},"not":{"$ref":"#x"}}},"properties":{"a":{"$ref":"http://e.example/r"}}}', ['minimum'], '{"a":3}'],
        'a relative id that stands twice' => ['{"$id":"http://e.example/","type":"object","$defs":{"p":{"$id":"b","type":"integer"},"c":{"$id":"http://e.example/c/","$defs":{"q":{"$id":"b","minimum":5}},"not":{"$ref":"b"}}},"properties":{"a":{"$ref":"http://e.example/c/"}}}', ['minimum'], '{"a":3}'],
        'anyOf beside the oneOf that was to be renamed' => ['{"type":"object","properties":{"a":{"anyOf":[true],"oneOf":[{"format":"email"},{"minimum":0}]}}}', ['oneOf', 'format'], '{"a":"abc"}'],
        'a reference by anchor into what is taken out' => ['{"type":"object","properties":{"a":{"not":{"$anchor":"n","type":"null"}},"b":{"$ref":"#n"}}}', ['not'], '{"b":null}'],
        'a reference by pointer into what is renamed' => ['{"type":"object","properties":{"a":{"oneOf":[{"type":"null"},{"type":"string"}]},"b":{"$ref":"#/properties/a/oneOf/0"}}}', ['oneOf'], '{"b":null}'],
        'a reference by pointer to below what is taken out' => ['{"type":"object","properties":{"a":{"not":{"properties":{"x":{"type":"null"}}}},"b":{"$ref":"#/properties/a/not/properties/x"}}}', ['not'], '{"b":null}'],
    ]);

    it('is false for a reference with one reading from a turned place to a schema that keeps its keywords', function (string $json): void {
        $schema = JsonSchema::fromJson($json);
        $shape = new SchemaShape();

        expect($shape->mayTighten($schema, ['minimum']))->toBeFalse()
            ->and(schemaAllows($schema, '{"n":3,"m":2}'))->toBeTrue()
            ->and(schemaAllows($shape->without($schema, ['minimum']), '{"n":3,"m":2}'))->toBeTrue();
    })->with([
        'a pointer' => ['{"type":"object","properties":{"n":{"not":{"$ref":"#/$defs/text"}},"m":{"minimum":1}},"$defs":{"text":{"type":"string"}}}'],
        'an anchor' => ['{"type":"object","properties":{"n":{"not":{"$ref":"#text"}},"m":{"minimum":1}},"$defs":{"text":{"$anchor":"text","type":"string"}}}'],
        'an address' => ['{"type":"object","properties":{"n":{"not":{"$ref":"https://schemas.example/text"}},"m":{"minimum":1}},"$defs":{"text":{"$id":"https://schemas.example/text","type":"string"}}}'],
    ]);

    it('is true when in doubt', function (string $json, array $keywords): void {
        expect((new SchemaShape())->mayTighten(JsonSchema::fromJson($json), $keywords))->toBeTrue();
    })->with([
        'a turned place refers to another document' => ['{"type":"object","properties":{"n":{"not":{"$ref":"https://schemas.example/x.json"}},"m":{"minimum":1}}}', ['minimum']],
        'a turned place refers to a place that cannot be found' => ['{"type":"object","properties":{"n":{"oneOf":[{"$ref":"#/x-kept/a"}]},"m":{"minimum":1}},"x-kept":{"a":{"minimum":2}}}', ['minimum']],
        'a turned place and a dynamic reference' => ['{"type":"object","properties":{"n":{"not":{"type":"null"}},"m":{"minimum":1,"$dynamicRef":"#x"}}}', ['minimum']],
        'a turned place and a recursive reference' => ['{"type":"object","if":{"required":["a"]},"properties":{"m":{"minimum":1,"$recursiveRef":"#"}}}', ['minimum']],
        'the definitions go' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"}},"$defs":{"x":{"type":"string"}}}', ['$defs']],
        'a reference goes' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x","minimum":1}},"$defs":{"x":{"type":"string"}}}', ['$ref']],
        'an address goes' => ['{"$id":"https://schemas.example/a","type":"object"}', ['$id']],
        'the draft goes' => ['{"$schema":"http://json-schema.org/draft-07/schema#","type":"object"}', ['$schema']],
        'an anchor goes' => ['{"type":"object","properties":{"a":{"$anchor":"a"}}}', ['$anchor']],
        'minContains that is no number' => ['{"type":"object","properties":{"l":{"contains":{},"minContains":"0"}}}', ['minContains']],
        'items beside additionalItems' => ['{"type":"object","properties":{"l":{"items":[{"type":"string"}],"additionalItems":false}}}', ['items']],
        'a keyword that looks at items, in a schema with unevaluatedItems anywhere' => ['{"type":"object","properties":{"l":{"prefixItems":[{}],"unevaluatedItems":false},"m":{"anyOf":[{"type":"string"}]}}}', ['anyOf']],
        'oneOf itself in a branch of another oneOf that stays' => ['{"type":"object","properties":{"a":{"contains":{"oneOf":[{"type":"string"}]},"maxContains":2}}}', ['oneOf']],
        'a ring of references from a turned place, which ends' => ['{"type":"object","properties":{"root":{"oneOf":[{"type":"null"},{"$ref":"#/$defs/node"}]}},"$defs":{"node":{"type":"object","properties":{"label":{"type":"string","minLength":1},"next":{"$ref":"#/properties/root"}}}}}', ['minLength']],
    ]);

    it('is false where the keywords stand in the open, and the copy still allows the answer', function (string $json, array $keywords, string $answer): void {
        $schema = JsonSchema::fromJson($json);
        $shape = new SchemaShape();

        expect($shape->mayTighten($schema, $keywords))->toBeFalse()
            ->and(schemaAllows($schema, $answer))->toBeTrue()
            ->and(schemaAllows($shape->without($schema, $keywords), $answer))->toBeTrue();
    })->with([
        'a property' => ['{"type":"object","properties":{"n":{"type":"integer","minimum":5,"maximum":9}},"required":["n"]}', ['minimum', 'maximum'], '{"n":7}'],
        'no keyword given' => ['{"type":"object","properties":{"n":{"not":{"minimum":5}}}}', [], '{"n":3}'],
        'entries that are no names' => ['{"type":"object","properties":{"n":{"not":{"minimum":5}}}}', [1, null, ['minimum'], "\0minimum"], '{"n":3}'],
        'keywords the schema does not use' => ['{"type":"object","properties":{"n":{"not":{"minimum":5}},"m":{"oneOf":[{"type":"string"}]}}}', ['maxLength', 'pattern'], '{"n":3,"m":"a"}'],
        'under not, when not goes too' => ['{"type":"object","properties":{"n":{"not":{"type":"integer","minimum":5}}}}', ['minimum', 'not'], '{"n":3}'],
        'under if, when if goes too' => ['{"type":"object","if":{"properties":{"n":{"minimum":5}}},"then":{"required":["x"]}}', ['minimum', 'if'], '{"n":3}'],
        'under contains, when maxContains goes too' => ['{"type":"object","properties":{"l":{"type":"array","contains":{"minimum":5},"maxContains":1}}}', ['minimum', 'maxContains'], '{"l":[1,2,9]}'],
        'under contains without maxContains' => ['{"type":"object","properties":{"l":{"type":"array","contains":{"minimum":5},"minContains":1}}}', ['minimum'], '{"l":[1,2,9]}'],
        'in branches of anyOf and allOf, and under then and else' => ['{"type":"object","anyOf":[{"properties":{"n":{"minimum":5}}}],"allOf":[{"properties":{"m":{"maxLength":2}}}],"if":{"required":["k"]},"then":{"properties":{"k":{"minimum":1}}},"else":{"properties":{"j":{"maxLength":1}}}}', ['minimum', 'maxLength'], '{"n":6,"m":"ab","j":"a"}'],
        'beside not, not under it' => ['{"type":"object","properties":{"n":{"type":"integer","minimum":5,"not":{"const":7}}}}', ['minimum'], '{"n":6}'],
        'a property under not whose name is the keyword' => ['{"type":"object","not":{"properties":{"minimum":{"type":"string"}},"required":["minimum"]},"properties":{"m":{"minimum":1}}}', ['minimum'], '{"m":2}'],
        'data under not' => ['{"type":"object","properties":{"n":{"not":{"const":{"minimum":5},"enum":[{"minimum":5}],"default":{"minimum":1},"examples":[{"minimum":1}]}},"m":{"minimum":1}}}', ['minimum'], '{"n":3,"m":2}'],
        'a definition used in the open, beside a not' => ['{"type":"object","properties":{"n":{"$ref":"#/$defs/big"},"m":{"not":{"type":"null"}}},"$defs":{"big":{"type":"integer","minimum":5}}}', ['minimum'], '{"n":6,"m":1}'],
        'minContains of one or more' => ['{"type":"object","properties":{"l":{"type":"array","contains":{"type":"string"},"minContains":2}}}', ['minContains'], '{"l":["a","b"]}'],
        'properties without additionalProperties beside it' => ['{"type":"object","properties":{"a":{"type":"string"}}}', ['properties'], '{"a":"x"}'],
        'a keyword that looks at properties, when unevaluatedProperties goes too' => ['{"type":"object","allOf":[{"properties":{"a":{"type":"string"}}}],"unevaluatedProperties":false}', ['allOf', 'unevaluatedProperties'], '{"a":"x"}'],
        'properties with numbers as names' => ['{"type":"object","properties":{"0":{"type":"integer","minimum":1},"1":{"not":{"type":"string"}}}}', ['minimum', '0', '1'], '{"0":2,"1":3}'],
    ]);

    it('is false for oneOf written as anyOf with the keywords of its branches taken out, and the copy still allows the answer', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"l":{"oneOf":[{"type":"string","maxLength":3},{"type":"string","minLength":4}]}}}');
        $shape = new SchemaShape();

        $copy = $shape->renamed($shape->without($schema, ['maxLength', 'minLength']), 'oneOf', 'anyOf');

        expect($shape->mayTighten($schema, ['maxLength', 'minLength', 'oneOf']))->toBeFalse()
            ->and(schemaAllows($schema, '{"l":"abc"}'))->toBeTrue()
            ->and(schemaAllows($copy, '{"l":"abc"}'))->toBeTrue();
    });

    it('reads numbers as names from a schema built from an array', function (): void {
        $open = JsonSchema::fromArray(['type' => 'object', 'properties' => ['0' => ['type' => 'integer', 'minimum' => 1], '1' => ['type' => 'string']]]);
        $turned = JsonSchema::fromArray(['type' => 'object', 'properties' => ['0' => ['not' => ['type' => 'integer', 'minimum' => 5]], '1' => ['type' => 'string']]]);

        expect((new SchemaShape())->mayTighten($open, ['minimum']))->toBeFalse()
            ->and((new SchemaShape())->mayTighten($turned, ['minimum']))->toBeTrue();
    });

    it('answers for a recursive schema', function (): void {
        expect((new SchemaShape())->mayTighten(recursiveTreeSchema(), ['minLength', 'maxItems']))->toBeFalse();
    });
});

describe('the helper as a whole', function (): void {
    it('never changes the schema it is asked about', function (): void {
        $shape = new SchemaShape();
        foreach ([recursiveTreeSchema(), schemaWithKeywordsAsNamesAndData()] as $schema) {
            $before = $schema->json;

            $shape->uses($schema, ['minimum', 'not']);
            $shape->without($schema, ['minimum', 'not', 'properties', '$defs']);
            $shape->isRecursive($schema);
            $shape->everyPropertyIsRequired($schema);
            $shape->everyObjectIsClosed($schema);
            $shape->everyObjectHasARequiredProperty($schema);
            $shape->depth($schema);
            $shape->propertyCount($schema);
            $shape->renamed($schema, 'properties', 'x-properties');
            $shape->valuesOf($schema, 'properties');
            $shape->mayTighten($schema, ['minimum', 'not', 'properties']);

            expect($schema->json)->toBe($before);
        }
    });

    it('gives the same answers the second time, and from a second object', function (): void {
        $schema = recursiveTreeSchema();
        $answers = static fn(SchemaShape $shape): array => [
            $shape->uses($schema, ['maxItems', '$ref']),
            $shape->without($schema, ['maxItems'])->json,
            $shape->isRecursive($schema),
            $shape->everyPropertyIsRequired($schema),
            $shape->everyObjectIsClosed($schema),
            $shape->everyObjectHasARequiredProperty($schema),
            $shape->depth($schema),
            $shape->propertyCount($schema),
            $shape->renamed($schema, 'maxItems', 'x-most')->json,
            $shape->valuesOf($schema, '$ref'),
            $shape->mayTighten($schema, ['maxItems']),
        ];
        $shape = new SchemaShape();

        expect($answers($shape))->toBe($answers($shape))->toBe($answers(new SchemaShape()));
    });

    it('has no property and no static that could keep something between two calls', function (): void {
        $class = new ReflectionClass(SchemaShape::class);

        expect($class->getProperties())->toBe([])
            ->and($class->getStaticProperties())->toBe([]);
        foreach ($class->getMethods() as $method) {
            expect($method->getStaticVariables())->toBe([]);
        }
    });
});
