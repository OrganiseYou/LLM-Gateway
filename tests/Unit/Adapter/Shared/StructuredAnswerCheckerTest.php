<?php

declare(strict_types=1);

use Opis\JsonSchema\Exceptions\ParseException;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\LoopGuardContext;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\LoopGuardValidator;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\SchemaCheckTooCostly;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\SchemaReferenceLoop;
use OrganiseYou\LlmGateway\Adapter\Shared\StructuredAnswerChecker;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\StructuredIssueKind;
use OrganiseYou\LlmGateway\Contract\StructuredResult;

/**
 * A response format for a verdict: a city as text and a number of people, both required, nothing else allowed.
 */
function verdictFormat(): ResponseFormat
{
    return new ResponseFormat(JsonSchema::fromArray([
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'minLength' => 2],
            'people' => ['type' => 'integer', 'minimum' => 0],
        ],
        'required' => ['city', 'people'],
        'additionalProperties' => false,
    ]), 'verdict');
}

function formatOf(string $schemaJson): ResponseFormat
{
    return new ResponseFormat(JsonSchema::fromJson($schemaJson));
}

function checked(string $text, ?ResponseFormat $format = null, FinishReason $finishReason = FinishReason::Stop): StructuredResult
{
    return (new StructuredAnswerChecker())->check($text, $format ?? verdictFormat(), $finishReason);
}

/**
 * The gateway exception a check ends in.
 */
function schemaRefusal(string $schemaJson, string $text): GatewayException
{
    try {
        checked($text, formatOf($schemaJson));
    } catch (GatewayException $refusal) {
        return $refusal;
    }

    throw new LogicException('The check did not refuse the schema.');
}

/**
 * How much the memory in use grew at its highest while the code ran, in bytes.
 *
 * @param callable(): void $run
 */
function memoryGrowthOf(callable $run): int
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $run();

    return memory_get_peak_usage() - $before;
}

/**
 * A schema for a filter: "and", "or" and "not" over a list of filters, or one comparison. Three of its four
 * branches refer back to the filter.
 */
function filterSchema(): string
{
    $over = fn(string $op): string => '{"type":"object","properties":{"op":{"const":"' . $op . '"},"args":{"type":"array","items":{"$ref":"#/$defs/expr"}}},"required":["op","args"],"additionalProperties":false}';

    return '{"type":"object","properties":{"filter":{"$ref":"#/$defs/expr"}},"required":["filter"],"$defs":{'
        . '"expr":{"anyOf":[{"$ref":"#/$defs/and"},{"$ref":"#/$defs/or"},{"$ref":"#/$defs/not"},{"$ref":"#/$defs/cmp"}]},'
        . '"and":' . $over('and') . ',"or":' . $over('or') . ',"not":' . $over('not') . ','
        . '"cmp":{"type":"object","properties":{"op":{"const":"eq"},"field":{"type":"string"},"value":{"type":"string"}},"required":["op","field","value"],"additionalProperties":false}}}';
}

/**
 * A filter of "not" around "not", so many times, around one comparison whose value is a text or, wrongly, a number.
 */
function filterAnswer(int $depth, bool $wrong): string
{
    $leaf = $wrong ? '{"op":"eq","field":"a","value":1}' : '{"op":"eq","field":"a","value":"b"}';

    return '{"filter":' . str_repeat('{"op":"not","args":[', $depth) . $leaf . str_repeat(']}', $depth) . '}';
}

/**
 * A schema of an object with "next", which reaches itself again through so many references.
 */
function chainSchema(int $hops): string
{
    $defs = [];
    for ($hop = 0; $hop < $hops - 1; $hop++) {
        $defs['h' . $hop] = ['$ref' => $hop === $hops - 2 ? '#' : '#/$defs/h' . ($hop + 1)];
    }

    return (string) json_encode([
        'type' => 'object',
        'properties' => ['next' => ['$ref' => $hops > 1 ? '#/$defs/h0' : '#']],
        '$defs' => (object) $defs,
    ]);
}

/**
 * Objects with "next" inside each other, so many deep, around a last value.
 */
function chainAnswer(int $depth, string $last): string
{
    return str_repeat('{"next":', $depth) . $last . str_repeat('}', $depth);
}

/**
 * Calls a private static method of the checker. Used for the two places that nothing outside the validator can
 * reach in a test: an error raised outside the validator while it runs, and an error that is not the schema's.
 */
function checkerInternal(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(StructuredAnswerChecker::class, $method))->invoke(null, ...$arguments);
}

/**
 * Records every time PHP opens an address of the given scheme, for as long as it replaces the real wrapper.
 */
final class RecordingStreamWrapper
{
    /** @var list<string> */
    public static array $opened = [];

    /** @var resource|null */
    public $context;

    public function stream_open(string $path): bool
    {
        self::$opened[] = $path;

        return false;
    }

    public function url_stat(string $path): false
    {
        self::$opened[] = $path;

        return false;
    }
}

describe('the structured-answer checker', function (): void {
    it('is final, has no constructor argument and keeps nothing', function (): void {
        $reflection = new ReflectionClass(StructuredAnswerChecker::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->getProperties())->toBe([])
            ->and($reflection->getConstructor())->toBeNull();
    });

    it('gives the same result for the same input, whatever was checked before', function (): void {
        $checker = new StructuredAnswerChecker();
        $first = $checker->check('{"city":"Utrecht","people":3}', verdictFormat(), FinishReason::Stop);
        $checker->check('{"city":5}', formatOf('{"type":"object","properties":{"city":{"type":"integer"}}}'), FinishReason::Stop);
        $again = $checker->check('{"city":"Utrecht","people":3}', verdictFormat(), FinishReason::Stop);

        expect($again)->toEqual($first)
            ->and($again->isUsable())->toBeTrue();
    });
});

describe('step 1: trim, and take what is inside one code fence', function (): void {
    it('accepts a valid answer with whitespace around it', function (): void {
        $result = checked("\n\t {\"city\":\"Utrecht\",\"people\":3} \r\n");

        expect($result->isUsable())->toBeTrue()
            ->and($result->data)->toBe(['city' => 'Utrecht', 'people' => 3]);
    });

    it('takes the JSON out of one Markdown code fence', function (string $text): void {
        $result = checked($text);

        expect($result->isUsable())->toBeTrue()
            ->and($result->issues)->toBe([])
            ->and($result->data)->toBe(['city' => 'Utrecht', 'people' => 3]);
    })->with([
        'a fence with a language tag' => ["```json\n{\"city\":\"Utrecht\",\"people\":3}\n```"],
        'a fence without a language tag' => ["```\n{\"city\":\"Utrecht\",\"people\":3}\n```"],
        'a fence with a tag in upper case' => ["```JSON\n{\"city\":\"Utrecht\",\"people\":3}\n```"],
        'a fence with whitespace around it' => ["\n\n  ```json\n{\"city\":\"Utrecht\",\"people\":3}\n```  \n"],
        'a fence with blank lines inside' => ["```json\n\n  {\"city\":\"Utrecht\",\"people\":3}\n\n```"],
        'a fence with Windows line ends' => ["```json\r\n{\"city\":\"Utrecht\",\"people\":3}\r\n```\r\n"],
        'a fence of four backticks' => ["````json\n{\"city\":\"Utrecht\",\"people\":3}\n````"],
        'a fence of tildes' => ["~~~json\n{\"city\":\"Utrecht\",\"people\":3}\n~~~"],
        'JSON over several lines in a fence' => ["```json\n{\n  \"city\": \"Utrecht\",\n  \"people\": 3\n}\n```"],
    ]);

    it('keeps backticks that are part of the JSON inside a fence', function (): void {
        $result = checked("```json\n{\"code\":\"```php\\necho 1;\\n```\"}\n```", formatOf('{"type":"object"}'));

        expect($result->isUsable())->toBeTrue()
            ->and($result->data)->toBe(['code' => "```php\necho 1;\n```"]);
    });

    it('does not take JSON out of anything that is not one whole fence, and repairs nothing', function (string $text): void {
        $result = checked($text);

        expect($result->isUsable())->toBeFalse()
            ->and($result->data)->toBeNull()
            ->and($result->issues)->toHaveCount(1)
            ->and($result->issues[0]->kind)->toBe(StructuredIssueKind::NotJson)
            ->and($result->issues[0]->path)->toBeNull()
            ->and($result->reasons()[0])->toStartWith('not JSON: ');
    })->with([
        'prose before the JSON' => ['Here is the answer: {"city":"Utrecht","people":3}'],
        'prose after the JSON' => ['{"city":"Utrecht","people":3} I hope this helps.'],
        'prose before a fence' => ["Here you go:\n```json\n{\"city\":\"Utrecht\",\"people\":3}\n```"],
        'prose after a fence' => ["```json\n{\"city\":\"Utrecht\",\"people\":3}\n```\nI hope this helps."],
        'two fences' => ["```json\n{\"city\":\"Utrecht\",\"people\":3}\n```\n```json\n{\"city\":\"Utrecht\",\"people\":3}\n```"],
        'a fence that is not closed' => ["```json\n{\"city\":\"Utrecht\",\"people\":3}"],
        'a fence closed with fewer backticks' => ["````json\n{\"city\":\"Utrecht\",\"people\":3}\n```"],
        'a fence closed with more backticks' => ["```json\n{\"city\":\"Utrecht\",\"people\":3}\n````"],
        'a fence closed with the other mark' => ["```json\n{\"city\":\"Utrecht\",\"people\":3}\n~~~"],
        'backticks on the line of the JSON' => ['```{"city":"Utrecht","people":3}```'],
        'a fence closed on the line of the JSON' => ["```json\n{\"city\":\"Utrecht\",\"people\":3}```"],
        'a backtick in the language tag' => ["```json`\n{\"city\":\"Utrecht\",\"people\":3}\n```"],
        'a tilde in the language tag of a tilde fence' => ["~~~js~on\n{\"city\":\"Utrecht\",\"people\":3}\n~~~"],
        'one backtick around the JSON' => ['`{"city":"Utrecht","people":3}`'],
        'an empty fence' => ["```json\n```"],
        'a fence inside a fence' => ["```\n```json\n{\"city\":\"Utrecht\",\"people\":3}\n```\n```"],
        'a comma too many' => ['{"city":"Utrecht","people":3,}'],
        'single quotes' => ["{'city':'Utrecht','people':3}"],
        'a comment' => ["{\"city\":\"Utrecht\", // the city\n\"people\":3}"],
        'a key without quotes' => ['{city:"Utrecht","people":3}'],
        'a missing closing brace' => ['{"city":"Utrecht","people":3'],
        'a byte order mark before the JSON' => ["\xEF\xBB\xBF{\"city\":\"Utrecht\",\"people\":3}"],
        'a no-break space before the JSON' => ["\u{00A0}{\"city\":\"Utrecht\",\"people\":3}"],
        'a null byte after the JSON' => ["{\"city\":\"Utrecht\",\"people\":3}\0"],
        'two JSON values' => ['{"city":"Utrecht","people":3}{"city":"Zwolle","people":1}'],
        'two JSON values on two lines' => ["{\"city\":\"Utrecht\",\"people\":3}\n{\"city\":\"Zwolle\",\"people\":1}"],
    ]);
});

describe('step 2: cut off at the token limit', function (): void {
    it('reports one issue "cut off at the token limit" without data when the text does not decode', function (string $text): void {
        $result = checked($text, null, FinishReason::Length);

        expect($result->isUsable())->toBeFalse()
            ->and($result->data)->toBeNull()
            ->and($result->issues)->toHaveCount(1)
            ->and($result->issues[0]->kind)->toBe(StructuredIssueKind::CutOff)
            ->and($result->issues[0]->path)->toBeNull()
            ->and($result->reasons())->toBe(['cut off at the token limit']);
    })->with([
        'JSON that stops half-way' => ['{"city":"Utre'],
        'an empty answer' => [''],
        'only whitespace' => ["  \n"],
        'a fence that was never closed' => ["```json\n{\"city\":\"Utrecht\","],
        'JSON that is no object' => ['["Utrecht"]'],
        'the word null' => ['null'],
    ]);

    it('keeps the decoded object when the text happens to decode, and still reports only the cut', function (string $text, array $data): void {
        $result = checked($text, null, FinishReason::Length);

        expect($result->isUsable())->toBeFalse()
            ->and($result->data)->toBe($data)
            ->and($result->reasons())->toBe(['cut off at the token limit']);
    })->with([
        'an answer that matches' => ['{"city":"Utrecht","people":3}', ['city' => 'Utrecht', 'people' => 3]],
        'an answer that does not match' => ['{"city":7}', ['city' => 7]],
        'an answer in a fence' => ["```json\n{\"city\":\"Utrecht\"}\n```", ['city' => 'Utrecht']],
    ]);

    it('does not look at the schema of a cut-off answer, so a schema that cannot be used is not reported then', function (): void {
        $result = checked('{"city":"Utrecht"}', formatOf('{"type":"object","required":"city"}'), FinishReason::Length);

        expect($result->reasons())->toBe(['cut off at the token limit']);
    });

    it('checks an answer with any other finish reason in full', function (FinishReason $finishReason): void {
        expect(checked('{"city":"Utrecht","people":3}', null, $finishReason)->isUsable())->toBeTrue()
            ->and(checked('{"city":"Utrecht"}', null, $finishReason)->issues[0]->kind)->toBe(StructuredIssueKind::SchemaMismatch);
    })->with([FinishReason::Stop, FinishReason::ToolCalls, FinishReason::ContentFilter, FinishReason::Other]);
});

describe('step 3: not JSON', function (): void {
    it('reports one issue with the message of the decoder, and no data', function (string $text): void {
        $result = checked($text);

        expect($result->isUsable())->toBeFalse()
            ->and($result->data)->toBeNull()
            ->and($result->issues)->toHaveCount(1)
            ->and($result->issues[0]->kind)->toBe(StructuredIssueKind::NotJson)
            ->and($result->issues[0]->path)->toBeNull()
            ->and($result->issues[0]->message)->toStartWith('not JSON: ')
            ->and(strlen($result->issues[0]->message))->toBeGreaterThan(strlen('not JSON: '))
            ->and($result->reasons())->toBe([$result->issues[0]->message]);
    })->with([
        'an empty answer' => [''],
        'only whitespace' => [" \n\t "],
        'prose' => ['I cannot answer that.'],
        'a page of markup' => ['<html><body>Bad gateway</body></html>'],
        'text that is not valid UTF-8' => ["{\"city\":\"\xFF\xFE\",\"people\":3}"],
        'a number that is no JSON' => ['{"city":"Utrecht","people":NaN}'],
        'a control character in a string' => ["{\"city\":\"Ut\x01recht\",\"people\":3}"],
        'nesting deeper than 64 levels' => [str_repeat('{"a":', 600) . '1' . str_repeat('}', 600)],
        'lists nested deeper than 64 levels' => [str_repeat('[', 100000)],
    ]);

    it('says "Syntax error" for text that is no JSON at all', function (): void {
        expect(checked('not json')->reasons()[0])->toStartWith('not JSON: Syntax error');
    });

    it('does not look at the schema of text that is not JSON', function (): void {
        $result = checked('nothing', formatOf('{"type":"object","required":"city"}'));

        expect($result->issues[0]->kind)->toBe(StructuredIssueKind::NotJson);
    });
});

describe('step 4: JSON that is not an object', function (): void {
    it('reports one schema mismatch at the root, and no data', function (string $text): void {
        $result = checked($text);

        expect($result->isUsable())->toBeFalse()
            ->and($result->data)->toBeNull()
            ->and($result->issues)->toHaveCount(1)
            ->and($result->issues[0]->kind)->toBe(StructuredIssueKind::SchemaMismatch)
            ->and($result->issues[0]->path)->toBe('/')
            ->and($result->reasons())->toBe(['/: must be an object']);
    })->with([
        'a list' => ['[{"city":"Utrecht","people":3}]'],
        'an empty list' => ['[]'],
        'a text' => ['"Utrecht"'],
        'a number' => ['12'],
        'true' => ['true'],
        'null' => ['null'],
    ]);

    it('takes an empty object as an object, not as a list', function (): void {
        $result = checked('{}', formatOf('{"type":"object"}'));

        expect($result->isUsable())->toBeTrue()
            ->and($result->data)->toBe([]);
    });
});

describe('step 5: the check against the schema', function (): void {
    it('accepts an answer that matches', function (): void {
        $result = checked('{"city":"Utrecht","people":3}');

        expect($result->isUsable())->toBeTrue()
            ->and($result->issues)->toBe([])
            ->and($result->reasons())->toBe([])
            ->and($result->data)->toBe(['city' => 'Utrecht', 'people' => 3]);
    });

    it('reports a mismatch as "path: message" and keeps the decoded data', function (): void {
        $result = checked('{"city":"Utrecht","people":"three"}');

        expect($result->isUsable())->toBeFalse()
            ->and($result->data)->toBe(['city' => 'Utrecht', 'people' => 'three'])
            ->and($result->issues)->toHaveCount(1)
            ->and($result->issues[0]->kind)->toBe(StructuredIssueKind::SchemaMismatch)
            ->and($result->issues[0]->path)->toBe('/people')
            ->and($result->issues[0]->message)->toBe('The data (string) must match the type: integer')
            ->and($result->reasons())->toBe(['/people: The data (string) must match the type: integer']);
    });

    it('reports every mismatch, in the same order every time', function (): void {
        $text = '{"city":"U","people":-1,"mayor":"x","river":"y"}';
        $expected = [
            '/city: Minimum string length is 2, found 1',
            '/people: Number must be greater than or equal to 0',
            '/: Additional object properties are not allowed: mayor, river',
        ];

        expect(checked($text)->reasons())->toBe($expected)
            ->and(checked($text)->reasons())->toBe($expected)
            ->and(checked($text)->data)->toBe(['city' => 'U', 'people' => -1, 'mayor' => 'x', 'river' => 'y']);
    });

    it('reports every missing property in one issue at the object that lacks them', function (): void {
        expect(checked('{}')->reasons())->toBe(['/: The required properties (city, people) are missing']);
    });

    it('gives the path as a JSON pointer, down into lists and with "/" and "~" in a name escaped', function (): void {
        $format = formatOf('{"type":"object","properties":{"rows":{"type":"array","items":{"type":"object","properties":{"a/b":{"type":"object","properties":{"c~d":{"type":"integer"}}}}}}}}');
        $result = checked('{"rows":[{"a/b":{"c~d":1}},{"a/b":{"c~d":"x"}},{"a/b":{"c~d":null}}]}', $format);

        expect($result->reasons())->toBe([
            '/rows/1/a~1b/c~0d: The data (string) must match the type: integer',
            '/rows/2/a~1b/c~0d: The data (null) must match the type: integer',
        ]);
    });

    it('reports the branches of anyOf one by one, at the same path', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"anyOf":[{"type":"integer"},{"type":"string","minLength":5}]}}}');

        expect(checked('{"a":"x"}', $format)->reasons())->toBe([
            '/a: The data (string) must match the type: integer',
            '/a: Minimum string length is 5, found 1',
        ]);
    });

    it('follows a reference inside the schema itself', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"$ref":"#/$defs/count"},"next":{"$ref":"#"}},"$defs":{"count":{"type":"integer"}}}');

        expect(checked('{"a":1,"next":{"a":2}}', $format)->isUsable())->toBeTrue()
            ->and(checked('{"a":1,"next":{"a":"two"}}', $format)->reasons())->toBe(['/next/a: The data (string) must match the type: integer']);
    });

    it('reports at most 100 mismatches, then says that more are not listed, and keeps the data', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"type":"array","items":{"type":"integer"}}}}');
        $result = checked((string) json_encode(['a' => array_fill(0, 500, 'x')]), $format);

        expect($result->isUsable())->toBeFalse()
            ->and($result->issues)->toHaveCount(101)
            ->and($result->issues[0]->path)->toBe('/a/0')
            ->and($result->issues[99]->path)->toBe('/a/99')
            ->and($result->issues[99]->message)->toBe('The data (string) must match the type: integer')
            ->and($result->issues[100]->kind)->toBe(StructuredIssueKind::SchemaMismatch)
            ->and($result->issues[100]->path)->toBe('/')
            ->and($result->reasons()[100])->toBe('/: more mismatches are not listed: only the first 100 are reported')
            ->and($result->data['a'] ?? null)->toHaveCount(500);
    });

    it('reports exactly 100 mismatches without that last line', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"type":"array","items":{"type":"integer"}}}}');
        $result = checked((string) json_encode(['a' => array_fill(0, 100, 'x')]), $format);

        expect($result->issues)->toHaveCount(100)
            ->and($result->issues[99]->path)->toBe('/a/99');
    });

    it('counts the 100 over the whole answer, not per list', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"type":"array","items":{"type":"array","items":{"type":"integer"}}}}}');
        $result = checked((string) json_encode(['a' => array_fill(0, 30, array_fill(0, 30, 'x'))]), $format);

        expect($result->issues)->toHaveCount(101)
            ->and($result->issues[0]->path)->toBe('/a/0/0')
            ->and($result->issues[30]->path)->toBe('/a/1/0')
            ->and($result->issues[99]->path)->toBe('/a/3/9')
            ->and($result->issues[100]->path)->toBe('/');
    });

    it('gives nested objects and lists of the answer as arrays', function (): void {
        $format = formatOf('{"type":"object"}');
        $result = checked('{"place":{"city":"Utrecht","tags":["a",{"b":null}],"empty":{}},"n":1.5,"ok":true}', $format);

        expect($result->data)->toBe([
            'place' => ['city' => 'Utrecht', 'tags' => ['a', ['b' => null]], 'empty' => []],
            'n' => 1.5,
            'ok' => true,
        ]);
    });

    it('checks the formats the validator knows and ignores one it does not know', function (): void {
        $format = formatOf('{"type":"object","properties":{"mail":{"type":"string","format":"email"},"day":{"type":"string","format":"date"},"x":{"type":"string","format":"no-such-format"}}}');

        expect(checked('{"mail":"a@example.test","day":"2026-11-26","x":"anything"}', $format)->isUsable())->toBeTrue()
            ->and(checked('{"mail":"nobody","day":"2026-13-45","x":"anything"}', $format)->reasons())->toBe([
                "/mail: The data must match the 'email' format",
                "/day: The data must match the 'date' format",
            ]);
    });

    it('does not fill in a default: the answer is checked and returned as the model gave it', function (): void {
        $format = formatOf('{"type":"object","properties":{"people":{"type":"integer","default":5}},"required":["people"]}');
        $result = checked('{}', $format);

        expect($result->data)->toBe([])
            ->and($result->reasons())->toBe(['/: The required properties (people) are missing']);
    });

    it('ignores the keywords of the validator that are no JSON Schema', function (): void {
        $format = formatOf('{"type":"object","$error":"own text","$pragma":{"maxErrors":1},"properties":{"a":{"type":"string","$filters":"no-such-filter"}},"required":["b","c"]}');

        expect(checked('{"a":"x","b":1,"c":2}', $format)->isUsable())->toBeTrue()
            ->and(checked('{"a":"x"}', $format)->reasons())->toBe(['/: The required properties (b, c) are missing']);
    });
});

describe('what a model can put in an answer', function (): void {
    it('reports a pattern that runs out of steps on the answer as a mismatch, without a warning', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"type":"string","pattern":"^(a+)+$"}}}');
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $result = checked((string) json_encode(['a' => str_repeat('a', 5000) . '!']), $format);
        } finally {
            restore_error_handler();
        }

        expect($result->reasons())->toBe(['/a: The string should match pattern: ^(a+)+$'])
            ->and($warnings)->toBe([]);
    });

    it('reads a whole number too large for PHP as a number, which still counts as an integer', function (): void {
        $format = formatOf('{"type":"object","properties":{"n":{"type":"integer"}}}');
        $result = checked('{"n":123456789012345678901234567890}', $format);

        expect($result->isUsable())->toBeTrue()
            ->and($result->data)->toBe(['n' => 1.2345678901234568E+29]);
    });

    it('holds a whole number too large for PHP to the limits of the schema', function (): void {
        $format = formatOf('{"type":"object","properties":{"n":{"type":"integer","maximum":100}}}');

        expect(checked('{"n":9223372036854775808}', $format)->reasons())->toBe(['/n: Number must be lower than or equal to 100']);
    });

    it('does not take a number beyond every float as an integer', function (): void {
        $format = formatOf('{"type":"object","properties":{"n":{"type":"integer"}}}');

        expect(checked('{"n":1e400}', $format)->reasons())->toBe(['/n: The data (number) must match the type: integer']);
    });

    it('gives the same results under a host that turns every warning into an exception, and leaves its handler in place', function (): void {
        $handler = function (int $level, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $level, $file, $line);
        };
        set_error_handler($handler);

        try {
            $tooLarge = checked('{"n":1e400}', formatOf('{"type":"object","properties":{"n":{"type":"integer"}}}'));
            $slowPattern = checked((string) json_encode(['a' => str_repeat('a', 5000) . '!']), formatOf('{"type":"object","properties":{"a":{"type":"string","pattern":"^(a+)+$"}}}'));
            $badPattern = schemaRefusal('{"type":"object","properties":{"a":{"type":"string","pattern":"("}}}', '{"a":"x"}');
            $inPlace = set_error_handler(null);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        expect($tooLarge->reasons())->toBe(['/n: The data (number) must match the type: integer'])
            ->and($slowPattern->reasons())->toBe(['/a: The string should match pattern: ^(a+)+$'])
            ->and($badPattern->detail)->toBe('the response schema is not a valid JSON Schema: the keyword pattern is not valid')
            ->and($inPlace)->toBe($handler);
    });

    it('takes the last value of a name that is given twice', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"type":"integer"}}}');
        $result = checked('{"a":"x","a":1}', $format);

        expect($result->isUsable())->toBeTrue()
            ->and($result->data)->toBe(['a' => 1]);
    });

    it('checks an empty name and a name of digits like any other', function (): void {
        $format = formatOf('{"type":"object","properties":{"":{"type":"integer"},"0":{"type":"integer"}}}');
        $result = checked('{"":"x","0":"y"}', $format);

        expect($result->data)->toBe(['' => 'x', 0 => 'y'])
            ->and($result->reasons())->toBe([
                '/: The data (string) must match the type: integer',
                '/0: The data (string) must match the type: integer',
            ]);
    });

    it('checks an answer of 64 objects inside each other, and calls one of 65 "not JSON"', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"$ref":"#"}}}');
        $allowed = checked(str_repeat('{"a":', 63) . '{"a":1}' . str_repeat('}', 63), $format);
        $empty = checked(str_repeat('{"a":', 63) . '{}' . str_repeat('}', 63), $format);
        $tooDeep = checked(str_repeat('{"a":', 64) . '{}' . str_repeat('}', 64), $format);

        expect($allowed->issues)->toHaveCount(1)
            ->and($allowed->issues[0]->path)->toBe(str_repeat('/a', 64))
            ->and($allowed->issues[0]->message)->toBe('The data (integer) must match the type: object')
            ->and($empty->isUsable())->toBeTrue()
            ->and($tooDeep->data)->toBeNull()
            ->and($tooDeep->issues[0]->kind)->toBe(StructuredIssueKind::NotJson)
            ->and($tooDeep->reasons())->toBe(['not JSON: nested deeper than 64 levels']);
    });

    it('calls lists nested deeper than 64 levels "not JSON" too, also when the schema has no reference', function (): void {
        $result = checked('{"a":' . str_repeat('[', 64) . str_repeat(']', 64) . '}', formatOf('{"type":"object"}'));

        expect($result->reasons())->toBe(['not JSON: nested deeper than 64 levels'])
            ->and(checked('{"a":' . str_repeat('[', 63) . str_repeat(']', 63) . '}', formatOf('{"type":"object"}'))->isUsable())->toBeTrue();
    });

    it('never throws for an answer, whatever its text and its finish reason', function (string $text): void {
        foreach (FinishReason::cases() as $finishReason) {
            $result = checked($text, null, $finishReason);

            expect($result)->toBeInstanceOf(StructuredResult::class);
            if ($text !== '{"city":"Utrecht","people":3}') {
                expect($result->isUsable())->toBeFalse()
                    ->and($result->reasons())->not->toBe([]);
            }
        }
    })->with([
        'a valid answer' => ['{"city":"Utrecht","people":3}'],
        'empty' => [''],
        'a null byte' => ["\0"],
        'bytes that are no text' => ["\xFF\xFE\xFD"],
        'a lone surrogate' => ['{"city":"\ud800","people":"x"}'],
        'a name that starts with a null byte' => ['{"\u0000city":1}'],
        'an object that does not match' => ['{"city":[],"people":{}}'],
        'a list' => ['[1,2,3]'],
        'a fence around nothing' => ["```\n\n```"],
        'only backticks' => ['``````'],
        'only tildes' => ['~~~'],
        'a fence line and a line end' => ["```json\n"],
        'a very long text' => [str_repeat('{"city":"Utrecht"} ', 20000)],
    ]);
});

describe('what a check may cost', function (): void {
    it('checks a valid answer against a schema with branches that refer back, in step with its size', function (int $depth): void {
        // Looking for every mismatch, the validator needs 11 seconds for depth 10 and never ends for depth 20.
        // Were that back, this check would run into the limit of references and be refused, not usable.
        $result = checked(filterAnswer($depth, false), formatOf(filterSchema()));

        expect($result->isUsable())->toBeTrue();
    })->with([10, 12, 20, 30]);

    it('returns one wrong value deep in such an answer as mismatches, within a few megabytes', function (int $depth): void {
        $result = null;
        $growth = memoryGrowthOf(function () use (&$result, $depth): void {
            $result = checked(filterAnswer($depth, true), formatOf(filterSchema()));
        });
        assert($result instanceof StructuredResult);

        expect($result->isUsable())->toBeFalse()
            ->and($result->data)->not->toBeNull()
            ->and(count($result->issues))->toBeGreaterThan(0)->toBeLessThanOrEqual(101)
            ->and($result->issues[0]->kind)->toBe(StructuredIssueKind::SchemaMismatch)
            ->and($growth)->toBeLessThan(48 * 1024 * 1024);
    })->with([8, 20, 30]);

    it('stops at the first mismatch when the schema holds a reference', function (): void {
        $format = formatOf('{"type":"object","properties":{"city":{"$ref":"#/$defs/text"},"people":{"type":"integer"},"river":{"type":"string"}},"$defs":{"text":{"type":"string"}}}');

        expect(checked('{"city":1,"people":"x","river":2}', $format)->reasons())->toBe(['/city: The data (integer) must match the type: string']);
    });

    it('takes a reference keyword as one wherever its name stands, also as the name of a property', function (): void {
        $format = formatOf('{"type":"object","properties":{"$ref":{"type":"string"},"people":{"type":"integer"}}}');

        expect(checked('{"$ref":1,"people":"x"}', $format)->issues)->toHaveCount(1);
    });

    it('does not take a reference keyword that stands in a text as one', function (): void {
        $format = formatOf('{"type":"object","description":"\"$ref\": \"#\"","properties":{"a":{"type":"string","const":"$ref"},"b":{"type":"integer"}}}');

        expect(checked('{"a":1,"b":"x"}', $format)->issues)->toHaveCount(3);
    });

    it('returns an answer nested far deeper than 64 levels as "not JSON", for a schema that refers to itself in two steps', function (): void {
        $result = null;
        $growth = memoryGrowthOf(function () use (&$result): void {
            $result = checked(chainAnswer(500, '1'), formatOf(chainSchema(2)));
        });
        assert($result instanceof StructuredResult);

        expect($result->reasons())->toBe(['not JSON: nested deeper than 64 levels'])
            ->and($growth)->toBeLessThan(8 * 1024 * 1024);
    });

    it('checks a wrong answer at the deepest level it allows, for a schema that refers to itself in several steps', function (int $hops): void {
        $result = null;
        $growth = memoryGrowthOf(function () use (&$result, $hops): void {
            $result = checked(chainAnswer(63, '1'), formatOf(chainSchema($hops)));
        });
        assert($result instanceof StructuredResult);

        expect($result->issues)->toHaveCount(1)
            ->and($result->issues[0]->path)->toBe(str_repeat('/next', 63))
            ->and($growth)->toBeLessThan(64 * 1024 * 1024);
    })->with([1, 2, 4, 8]);

    it('returns 100,000 wrong items as one mismatch, the first, without holding the others', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"type":"array","items":{"type":"integer"}}}}');
        $text = (string) json_encode(['a' => array_fill(0, 100000, 'x')]);

        $result = null;
        $growth = memoryGrowthOf(function () use (&$result, $text, $format): void {
            $result = checked($text, $format);
        });
        assert($result instanceof StructuredResult);

        expect($result->isUsable())->toBeFalse()
            ->and($result->reasons())->toBe(['/a/0: The data (string) must match the type: integer'])
            ->and($result->data['a'] ?? null)->toHaveCount(100000)
            ->and($growth)->toBeLessThan(64 * 1024 * 1024);
    });

    it('looks for every mismatch up to 5000 values in the answer, and for the first one above that', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"type":"array","items":{"type":"integer"}},"b":{"type":"integer"}}}');
        // The object, the list "a", its items and "b" are the values.
        $atLimit = checked((string) json_encode(['a' => array_fill(0, 4997, 1), 'b' => 'x', 'c' => 2] + ['a' => []]), $format);
        $within = checked((string) json_encode(['a' => [...array_fill(0, 4995, 1), 'x', 'y'], 'b' => 'x']), $format);
        $above = checked((string) json_encode(['a' => [...array_fill(0, 4996, 1), 'x', 'y'], 'b' => 'x']), $format);

        expect($atLimit->reasons())->toBe(['/b: The data (string) must match the type: integer'])
            ->and($within->issues)->toHaveCount(3)
            ->and($above->issues)->toHaveCount(1);
    });

    it('returns wrong leaves three lists deep as one mismatch, within a few megabytes', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"type":"array","items":{"type":"array","items":{"type":"array","items":{"type":"string"}}}}}}');
        $text = (string) json_encode(['a' => array_fill(0, 100, array_fill(0, 100, array_fill(0, 10, 1)))]);

        $result = null;
        $growth = memoryGrowthOf(function () use (&$result, $text, $format): void {
            $result = checked($text, $format);
        });
        assert($result instanceof StructuredResult);

        expect($result->reasons())->toBe(['/a/0/0/0: The data (integer) must match the type: string'])
            ->and($growth)->toBeLessThan(64 * 1024 * 1024);
    });

    it('returns an answer whose check goes over the limits with its data and one reason, and never throws', function (string $schemaJson, string $text): void {
        $result = null;
        $growth = memoryGrowthOf(function () use (&$result, $schemaJson, $text): void {
            $result = checked($text, formatOf($schemaJson));
        });
        assert($result instanceof StructuredResult);

        expect($result->isUsable())->toBeFalse()
            ->and($result->data)->toBe(json_decode($text, true))
            ->and($result->issues)->toHaveCount(1)
            ->and($result->issues[0]->kind)->toBe(StructuredIssueKind::SchemaMismatch)
            ->and($result->issues[0]->path)->toBe('/')
            ->and($result->reasons())->toBe(['/: could not be checked against the schema within the limits of one check'])
            ->and($growth)->toBeLessThan(96 * 1024 * 1024);
    })->with([
        'two branches of allOf that both refer back, a valid answer' => [
            '{"type":"object","properties":{"next":{"allOf":[{"$ref":"#"},{"$ref":"#"}]}}}',
            chainAnswer(30, '{}'),
        ],
        'two branches of anyOf that both refer back, a wrong answer' => [
            '{"type":"object","properties":{"next":{"anyOf":[{"$ref":"#"},{"$ref":"#"}]}}}',
            chainAnswer(20, '1'),
        ],
        'the same, at the deepest level' => [
            '{"type":"object","properties":{"next":{"anyOf":[{"$ref":"#"},{"$ref":"#"}]}}}',
            chainAnswer(63, '1'),
        ],
        'twenty references per level, a wrong answer at the deepest level' => [
            chainSchema(20),
            chainAnswer(63, '1'),
        ],
    ]);

    it('keeps the limits inside the check of the names of an object', function (): void {
        // Seventeen definitions that each ask the next one twice: 131,072 references for one name, and no loop.
        $defs = [];
        for ($step = 0; $step < 17; $step++) {
            $next = $step === 16 ? ['type' => 'string'] : ['$ref' => '#/$defs/d' . ($step + 1)];
            $defs['d' . $step] = ['allOf' => [$next, $next]];
        }
        $schema = (string) json_encode(['type' => 'object', 'propertyNames' => ['$ref' => '#/$defs/d0'], '$defs' => $defs]);

        expect(checked('{"a":1}', formatOf($schema))->reasons())->toBe(['/: could not be checked against the schema within the limits of one check']);
    });

    it('checks again to the first mismatch when looking for every mismatch goes over the limits', function (): void {
        // Thirty object types of eleven properties, without a reference: every wrong item fails every type.
        $types = [];
        for ($type = 0; $type < 30; $type++) {
            $properties = [];
            for ($field = 0; $field < 10; $field++) {
                $properties['f' . $field] = ['type' => 'string'];
            }
            $properties['kind'] = ['const' => 't' . $type];
            $types[] = ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
        }
        $schema = (string) json_encode(['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['anyOf' => $types]]]]);
        $wrong = array_fill_keys(['f0', 'f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7', 'f8', 'f9'], 1) + ['kind' => 't0'];
        $right = array_fill_keys(['f0', 'f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7', 'f8', 'f9'], 'x') + ['kind' => 't29'];

        foreach ([94, 95, 400] as $items) {
            $result = null;
            $growth = memoryGrowthOf(function () use (&$result, $schema, $wrong, $items): void {
                $result = checked((string) json_encode(['items' => array_fill(0, $items, $wrong)]), formatOf($schema));
            });
            assert($result instanceof StructuredResult);

            expect($result->isUsable())->toBeFalse()
                ->and($result->data['items'] ?? null)->toHaveCount($items)
                ->and(count($result->issues))->toBeGreaterThan(1)->toBeLessThanOrEqual(101)
                ->and($result->issues[0]->path)->toStartWith('/items/0/')
                ->and($growth)->toBeLessThan(96 * 1024 * 1024);
        }

        $oneWrong = [...array_fill(0, 399, $right), ['f0' => 1] + $right];
        $result = checked((string) json_encode(['items' => $oneWrong]), formatOf($schema));

        expect($result->isUsable())->toBeFalse()
            ->and($result->issues[0]->path)->toStartWith('/items/399/')
            ->and(checked((string) json_encode(['items' => array_fill(0, 400, $right)]), formatOf($schema))->isUsable())->toBeTrue();
    });

    it('accepts a valid answer for a tree of object types that are told apart by a property listed after their children', function (int $types, int $depth, int $fanout): void {
        // Without the kind checked first, every wrong type walks the whole answer below it before it is refused:
        // a valid answer of 170 bytes then needs more references than one check may follow.
        $defs = [];
        $branches = [];
        for ($type = 0; $type < $types; $type++) {
            $defs['c' . $type] = [
                'type' => 'object',
                'properties' => ['children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/node']], 'kind' => ['const' => 'c' . $type]],
                'required' => ['kind', 'children'],
                'additionalProperties' => false,
            ];
            $branches[] = ['$ref' => '#/$defs/c' . $type];
        }
        $defs['leaf'] = ['type' => 'object', 'properties' => ['kind' => ['const' => 'leaf'], 'text' => ['type' => 'string']], 'required' => ['kind', 'text'], 'additionalProperties' => false];
        $branches[] = ['$ref' => '#/$defs/leaf'];
        $defs['node'] = ['anyOf' => $branches];
        $schema = (string) json_encode(['type' => 'object', 'properties' => ['root' => ['$ref' => '#/$defs/node']], 'required' => ['root'], '$defs' => $defs]);

        $node = ['kind' => 'leaf', 'text' => 'x'];
        $wrongNode = ['kind' => 'leaf', 'text' => 5];
        for ($level = 0; $level < $depth; $level++) {
            $node = ['kind' => 'c' . ($types - 1), 'children' => array_fill(0, $fanout, $node)];
            $wrongNode = ['kind' => 'c' . ($types - 1), 'children' => [$wrongNode]];
        }

        $wrong = checked((string) json_encode(['root' => $wrongNode]), formatOf($schema));

        expect(checked((string) json_encode(['root' => $node]), formatOf($schema))->isUsable())->toBeTrue()
            ->and($wrong->isUsable())->toBeFalse()
            ->and($wrong->reasons())->not->toContain('/: could not be checked against the schema within the limits of one check');

        // Every level adds the mismatches of the types it is not; past 100 the list is cut before the leaf.
        $leaf = '/root' . str_repeat('/children/0', $depth) . '/text: The data (integer) must match the type: string';
        $cut = '/: more mismatches are not listed: only the first 100 are reported';
        expect(in_array($leaf, $wrong->reasons(), true) || in_array($cut, $wrong->reasons(), true))->toBeTrue()
            ->and(in_array($leaf, $wrong->reasons(), true))->toBe($depth <= 12);
    })->with([
        'five types, five levels' => [5, 5, 1],
        'three types, eight levels' => [3, 8, 1],
        'two types, twelve levels' => [2, 12, 1],
        'five types, three levels, three children each' => [5, 3, 3],
        'five types, thirty levels' => [5, 30, 1],
    ]);

    it('accepts a valid answer for a layout tree whose types have no property that tells them apart', function (int $depth, int $fanout): void {
        // Two of the three branches match every box. The validator stops at the first branch that matches, as
        // long as the schema does not use "unevaluatedProperties" or "unevaluatedItems".
        $children = ['type' => 'array', 'items' => ['$ref' => '#/$defs/node']];
        $schema = (string) json_encode(['type' => 'object', 'properties' => ['root' => ['$ref' => '#/$defs/node']], 'required' => ['root'], '$defs' => [
            'box' => ['type' => 'object', 'properties' => ['children' => $children, 'pad' => ['type' => 'integer']], 'required' => ['children']],
            'row' => ['type' => 'object', 'properties' => ['children' => $children, 'gap' => ['type' => 'integer']], 'required' => ['children']],
            'text' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
            'node' => ['anyOf' => [['$ref' => '#/$defs/box'], ['$ref' => '#/$defs/row'], ['$ref' => '#/$defs/text']]],
        ]]);

        $node = ['text' => 'x'];
        $wrongNode = ['text' => 5];
        for ($level = 0; $level < $depth; $level++) {
            $node = ['children' => [...array_fill(0, $fanout - 1, ['text' => 'x']), $node]];
            $wrongNode = ['children' => [...array_fill(0, $fanout - 1, ['text' => 'x']), $wrongNode]];
        }

        $wrong = checked((string) json_encode(['root' => $wrongNode]), formatOf($schema));

        expect(checked((string) json_encode(['root' => $node]), formatOf($schema))->isUsable())->toBeTrue()
            ->and($wrong)->toBeInstanceOf(StructuredResult::class)
            ->and($wrong->isUsable())->toBeFalse()
            ->and($wrong->data)->not->toBeNull();
    })->with([
        'eight levels, two children' => [8, 2],
        'twelve levels, three children' => [12, 3],
        'twenty-five levels, two children' => [25, 2],
    ]);

    it('still tries every branch when the schema uses "unevaluatedProperties", which needs it', function (): void {
        $format = formatOf('{"type":"object","anyOf":[{"properties":{"a":{"type":"integer"}}},{"properties":{"b":{"type":"integer"}}}],"unevaluatedProperties":false}');

        expect(checked('{"a":1,"b":2}', $format)->isUsable())->toBeTrue()
            ->and(checked('{"a":1,"b":2,"c":3}', $format)->reasons())->toBe(['/: Unevaluated object properties not allowed: c']);
    });

    it('takes "$dynamicRef" and "$recursiveRef" as references too: the check stops at the first mismatch', function (string $schemaJson): void {
        expect(checked('{"a":"x","b":"y"}', formatOf($schemaJson))->issues)->toHaveCount(1);
    })->with([
        'a dynamic reference' => ['{"type":"object","properties":{"a":{"type":"integer"},"b":{"type":"integer"},"c":{"$dynamicRef":"#/$defs/n"}},"$defs":{"n":{"type":"integer"}}}'],
        'a recursive reference of draft 2019-09' => ['{"$schema":"https://json-schema.org/draft/2019-09/schema","type":"object","properties":{"a":{"type":"integer"},"b":{"type":"integer"},"c":{"$recursiveRef":"#"}}}'],
        'the same schema with a plain reference' => ['{"type":"object","properties":{"a":{"type":"integer"},"b":{"type":"integer"},"c":{"$ref":"#/$defs/n"}},"$defs":{"n":{"type":"integer"}}}'],
    ]);

    it('checks the properties with "const" or "enum" before the others, which changes the order of the reasons and nothing else', function (): void {
        $schema = JsonSchema::fromJson('{"type":"object","properties":{"name":{"type":"string"},"kind":{"const":"a"},"size":{"type":"integer"},"tone":{"enum":["x","y"]}},"required":["name","kind"]}');
        $before = $schema->json;
        $format = new ResponseFormat($schema);

        expect(checked('{"name":1,"kind":"b","size":"s","tone":"z"}', $format)->reasons())->toBe([
            '/kind: The data must match the const value',
            '/tone: The data should match one item from enum',
            '/name: The data (integer) must match the type: string',
            '/size: The data (string) must match the type: integer',
        ])
            ->and(checked('{"name":"n","kind":"a","size":1,"tone":"x"}', $format)->isUsable())->toBeTrue()
            ->and(checked('{"size":1}', $format)->reasons())->toBe(['/: The required properties (name, kind) are missing'])
            ->and($schema->json)->toBe($before);
    });

    it('leaves a property that is itself named "properties", and the value of a "const", as they are', function (): void {
        $format = formatOf('{"type":"object","properties":{"properties":{"type":"object","properties":{"z":{"type":"string"},"k":{"const":1}}},"fixed":{"const":{"properties":{"b":{"type":"string"},"a":{"const":2}}}}}}');

        expect(checked('{"properties":{"z":"s","k":1},"fixed":{"properties":{"b":{"type":"string"},"a":{"const":2}}}}', $format)->isUsable())->toBeTrue()
            ->and(checked('{"properties":{"z":5,"k":2},"fixed":{"properties":{}}}', $format)->reasons())->toBe([
                '/fixed: The data must match the const value',
                '/properties/k: The data must match the const value',
                '/properties/z: The data (integer) must match the type: string',
            ]);
    });

    it('finds both mismatches in the same schema without a reference', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"type":"integer"},"b":{"type":"integer"},"c":{"type":"integer"}}}');

        expect(checked('{"a":"x","b":"y"}', $format)->issues)->toHaveCount(2);
    });

    it('does not refuse a schema that only refers, however many branches it has', function (): void {
        $branches = [];
        $defs = [];
        for ($kind = 0; $kind < 30; $kind++) {
            $branches[] = ['$ref' => '#/$defs/k' . $kind];
            $defs['k' . $kind] = [
                'type' => 'object',
                'properties' => ['kind' => ['const' => 'k' . $kind], 'kids' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/node']]],
                'required' => ['kind'],
            ];
        }
        $defs['node'] = ['anyOf' => $branches];
        $schema = (string) json_encode(['type' => 'object', 'properties' => ['root' => ['$ref' => '#/$defs/node']], '$defs' => $defs]);

        // Every node matches the last of the thirty branches only.
        $node = ['kind' => 'k29', 'kids' => []];
        for ($level = 0; $level < 30; $level++) {
            $node = ['kind' => 'k29', 'kids' => [['kind' => 'k29', 'kids' => []], ['kind' => 'k29', 'kids' => []], $node]];
        }

        expect(checked((string) json_encode(['root' => $node]), formatOf($schema))->isUsable())->toBeTrue();
    });

    it('checks 20,000 rows against a schema with references', function (): void {
        $format = formatOf('{"type":"object","properties":{"rows":{"type":"array","items":{"$ref":"#/$defs/row"}}},"$defs":{"row":{"type":"object","properties":{"id":{"$ref":"#/$defs/id"},"tags":{"type":"array","items":{"$ref":"#/$defs/tag"}}}},"id":{"type":"integer"},"tag":{"type":"string"}}}');
        $rows = array_fill(0, 20000, ['id' => 1, 'tags' => ['a', 'b']]);
        $rows[19999] = ['id' => 1, 'tags' => ['a', 7]];

        expect(checked((string) json_encode(['rows' => array_fill(0, 20000, ['id' => 1, 'tags' => ['a', 'b']])]), $format)->isUsable())->toBeTrue()
            ->and(checked((string) json_encode(['rows' => $rows]), $format)->reasons())->toBe(['/rows/19999/tags/1: The data (integer) must match the type: string']);
    });
});

describe('a schema that refers back to itself', function (): void {
    it('is refused as rejected, in place of a process that dies or never ends', function (string $schemaJson): void {
        $refusal = schemaRefusal($schemaJson, '{"a":1}');

        expect($refusal->reason)->toBe(ErrorReason::Rejected)
            ->and($refusal->detail)->toBe('the response schema is not a valid JSON Schema: a reference in it leads back to itself')
            ->and($refusal->getPrevious())->toBeNull();
    })->with([
        'the root refers to itself' => ['{"type":"object","$ref":"#"}'],
        'two definitions refer to each other' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"}},"$defs":{"x":{"$ref":"#/$defs/y"},"y":{"$ref":"#/$defs/x"}}}'],
        'three definitions in a ring' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"}},"$defs":{"x":{"$ref":"#/$defs/y"},"y":{"$ref":"#/$defs/z"},"z":{"$ref":"#/$defs/x"}}}'],
        'allOf refers to the root' => ['{"type":"object","allOf":[{"$ref":"#"}]}'],
        'anyOf, oneOf and not refer to the root' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"}},"$defs":{"x":{"not":{"oneOf":[{"anyOf":[{"$ref":"#/$defs/x"}]}]}}}}'],
        'if refers to the schema it stands in' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/x"}},"$defs":{"x":{"if":{"$ref":"#/$defs/x"},"then":{"type":"integer"}}}}'],
        'a nested id with a relative reference to itself' => ['{"type":"object","properties":{"a":{"$id":"https://schemas.example.test/s.json","$ref":"s.json"}}}'],
        'an anchor with a reference to itself' => ['{"type":"object","properties":{"a":{"$anchor":"me","$ref":"#me"}}}'],
        'a dynamic reference to its own anchor' => ['{"type":"object","$dynamicAnchor":"n","properties":{"a":{"type":"integer"}},"$dynamicRef":"#n"}'],
        'a dynamic reference that is no text' => ['{"type":"object","$dynamicRef":5}'],
        'a recursive reference of draft 2019-09' => ['{"$schema":"https://json-schema.org/draft/2019-09/schema","$recursiveAnchor":true,"type":"object","$recursiveRef":"#"}'],
        'the names of the properties refer back' => ['{"type":"object","propertyNames":{"$ref":"#/$defs/x"},"$defs":{"x":{"$ref":"#/$defs/x"}}}'],
    ]);

    it('is not stopped when it goes one level deeper into the answer each time', function (string $schemaJson, string $valid, string $wrong, array $reasons): void {
        expect(checked($valid, formatOf($schemaJson))->isUsable())->toBeTrue()
            ->and(checked($wrong, formatOf($schemaJson))->reasons())->toBe($reasons);
    })->with([
        'a list of objects with "next"' => [
            '{"type":"object","properties":{"v":{"type":"integer"},"next":{"$ref":"#"}}}',
            str_repeat('{"v":1,"next":', 60) . '{"v":2}' . str_repeat('}', 60),
            str_repeat('{"v":1,"next":', 60) . '{"v":"x"}' . str_repeat('}', 60),
            [str_repeat('/next', 60) . '/v: The data (string) must match the type: integer'],
        ],
        'a chain of four references per level' => [
            '{"type":"object","properties":{"next":{"$ref":"#/$defs/a"}},"$defs":{"a":{"$ref":"#/$defs/b"},"b":{"$ref":"#/$defs/c"},"c":{"$ref":"#"}}}',
            str_repeat('{"next":', 60) . '{}' . str_repeat('}', 60),
            str_repeat('{"next":', 60) . '1' . str_repeat('}', 60),
            [str_repeat('/next', 60) . ': The data (integer) must match the type: object'],
        ],
        'the same reference used for several properties and twice in allOf' => [
            '{"type":"object","properties":{"a":{"$ref":"#/$defs/s"},"b":{"$ref":"#/$defs/s"},"c":{"allOf":[{"$ref":"#/$defs/s"},{"$ref":"#/$defs/s"}]}},"$defs":{"s":{"type":"string"}}}',
            '{"a":"x","b":"y","c":"z"}',
            '{"a":"x","b":"y","c":1}',
            ['/c: The data (integer) must match the type: string'],
        ],
        'a tree' => [
            '{"type":"object","properties":{"kids":{"type":"array","items":{"$ref":"#"}},"v":{"type":"integer"}}}',
            '{"kids":[{"kids":[{"v":2},{"v":1}]},{"kids":[{"kids":[{"v":3}]}]}]}',
            '{"kids":[{"kids":[{"v":2},{"v":1}]},{"kids":[{"kids":[{"v":"y"}]}]}]}',
            ['/kids/1/kids/0/kids/0/v: The data (string) must match the type: integer'],
        ],
        'a reference that is followed for an object and again, further in, for one of its names' => [
            '{"type":"object","properties":{"a":{"$ref":"#/$defs/x/propertyNames"}},"$defs":{"x":{"type":["object","string"],"maxLength":2,"propertyNames":{"$ref":"#/$defs/x"}}}}',
            '{"a":{"k":{"anything":1}}}',
            '{"a":{"long":1}}',
            ['/a: Maximum string length is 2, found 4'],
        ],
        'the names of the properties checked by a schema that is also used for a value' => [
            '{"type":"object","propertyNames":{"$ref":"#/$defs/short"},"properties":{"ab":{"$ref":"#/$defs/short"}},"$defs":{"short":{"maxLength":2}}}',
            '{"ab":"cd"}',
            '{"ab":"cde"}',
            ['/ab: Maximum string length is 2, found 3'],
        ],
    ]);

    it('is guarded by two small classes on top of the validator, which keep nothing after a check', function (): void {
        foreach ([LoopGuardContext::class, LoopGuardValidator::class, SchemaReferenceLoop::class, SchemaCheckTooCostly::class] as $class) {
            $reflection = new ReflectionClass($class);

            expect($reflection->isFinal())->toBeTrue($class)
                ->and($reflection->getStaticProperties())->toBe([])
                ->and((string) $reflection->getDocComment())->toContain('@internal');
        }
    });
});

describe('the additions of the validator that are no JSON Schema', function (): void {
    it('are switched off: a schema that uses one is read as if the keyword were not there', function (string $schemaJson, string $text, array $reasons): void {
        expect(checked($text, formatOf($schemaJson))->reasons())->toBe($reasons);
    })->with([
        // With mappers on, "$map": 5 is a keyword of the wrong kind and the schema would be rejected.
        'a mapper next to a reference' => [
            '{"type":"object","properties":{"a":{"$ref":"#/$defs/int","$map":5}},"$defs":{"int":{"type":"integer"}}}',
            '{"a":"x"}',
            ['/a: The data (string) must match the type: integer'],
        ],
        // With globals on, "$globals": 5 would be rejected.
        'globals next to a reference' => [
            '{"type":"object","properties":{"a":{"$ref":"#/$defs/int","$globals":5}},"$defs":{"int":{"type":"integer"}}}',
            '{"a":1}',
            [],
        ],
        // With slots on, "$inject": 5 would be rejected.
        'an injected slot next to a reference' => [
            '{"type":"object","properties":{"a":{"$ref":"#/$defs/int","$inject":5}},"$defs":{"int":{"type":"integer"}}}',
            '{"a":1}',
            [],
        ],
        // With slots on, the answer would be refused: "Required slot 'x' is missing".
        'slots' => [
            '{"type":"object","properties":{"a":{"type":"integer","$slots":{"x":"nope"}}}}',
            '{"a":1}',
            [],
        ],
        // With the decoding of content on, both texts would be refused, and PHP's file type database opened.
        'content of drafts 06 and 07' => [
            '{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","properties":{"a":{"type":"string","contentEncoding":"base64"},"b":{"type":"string","contentMediaType":"application/json"}}}',
            '{"a":"!!","b":"{"}',
            [],
        ],
        'content of draft 06' => [
            '{"$schema":"http://json-schema.org/draft-06/schema#","type":"object","properties":{"a":{"type":"string","contentEncoding":"base64","contentMediaType":"image/png"}}}',
            '{"a":"!!"}',
            [],
        ],
    ]);
});

describe('a warning raised while the validator runs', function (): void {
    it('is swallowed when it comes from the code of the validator, and goes to the handler of the host from anywhere else', function (): void {
        $seen = [];
        $ofHost = function (int $level, string $message, string $file, int $line) use (&$seen): bool {
            $seen[] = $message . ' in ' . basename($file) . ':' . $line;

            return true;
        };
        $folders = checkerInternal('foldersOfValidator');
        $handler = checkerInternal('quietFor', $folders, $ofHost);
        assert($handler instanceof Closure && is_array($folders));

        $inValidator = (string) (new ReflectionClass(Helper::class))->getFileName();

        expect($handler(E_WARNING, 'inside', $inValidator, 271))->toBeTrue()
            ->and($seen)->toBe([])
            ->and($handler(E_WARNING, 'outside', __FILE__, 7))->toBeTrue()
            ->and($handler(E_USER_DEPRECATED, 'next to the validator', dirname($inValidator, 2) . '-other/Helper.php', 9))->toBeTrue()
            ->and($seen)->toBe(['outside in ' . basename(__FILE__) . ':7', 'next to the validator in Helper.php:9']);
    });

    it('gets the answer of the handler of the host, and false when the host has none, so that PHP handles it', function (): void {
        $folders = checkerInternal('foldersOfValidator');
        $declines = checkerInternal('quietFor', $folders, fn(): bool => false);
        $none = checkerInternal('quietFor', $folders, null);
        assert($declines instanceof Closure && $none instanceof Closure);

        expect($declines(E_WARNING, 'outside', __FILE__, 7))->toBeFalse()
            ->and($none(E_WARNING, 'outside', __FILE__, 7))->toBeFalse()
            ->and($none(E_WARNING, 'inside', (string) (new ReflectionClass(Validator::class))->getFileName(), 7))->toBeTrue();
    });

    it('lets an exception of the handler of the host pass for a warning from outside the validator', function (): void {
        $handler = checkerInternal('quietFor', checkerInternal('foldersOfValidator'), function (int $level, string $message): bool {
            throw new ErrorException($message);
        });
        assert($handler instanceof Closure);

        expect(fn() => $handler(E_WARNING, 'outside', __FILE__, 7))->toThrow(ErrorException::class, 'outside');
    });

    it('finds the folders of the validator and of the two packages under it from where their classes are', function (): void {
        $folders = checkerInternal('foldersOfValidator');
        assert(is_array($folders));

        expect($folders)->toHaveCount(3)
            ->and($folders[0])->toBe(dirname((string) (new ReflectionClass(Validator::class))->getFileName()) . DIRECTORY_SEPARATOR);
    });
});

describe('what the validator throws', function (): void {
    it('becomes rejected when it is an exception of the validator or a type error raised in its code', function (): void {
        $folders = checkerInternal('foldersOfValidator');

        $typeError = null;
        try {
            Helper::isValidJsonType(untyped([]));
        } catch (TypeError $thrown) {
            $typeError = $thrown;
        }
        assert($typeError instanceof TypeError);

        foreach ([new ParseException('made up'), new SchemaReferenceLoop(), $typeError] as $thrown) {
            $instead = checkerInternal('insteadOf', $thrown, $folders);

            expect($instead)->toBeInstanceOf(GatewayException::class);
            assert($instead instanceof GatewayException);
            expect($instead->reason)->toBe(ErrorReason::Rejected)
                ->and($instead->getPrevious())->toBeNull();
        }
    });

    it('becomes a new plain error with a fixed text when it is an error that does not come from the schema', function (Throwable $thrown, string $class): void {
        $instead = checkerInternal('insteadOf', $thrown, checkerInternal('foldersOfValidator'));
        assert($instead instanceof Throwable);

        expect($instead::class)->toBe(Error::class)
            ->and($instead)->not->toBe($thrown)
            ->and($instead->getPrevious())->toBeNull()
            ->and($instead->getMessage())->toBe('The structured check failed with an error of class ' . $class . ', thrown in StructuredAnswerCheckerTest.php on line ' . $thrown->getLine() . '.');
    })->with([
        'a type error raised outside the validator' => [fn() => new TypeError('made up, with a text of the host'), 'TypeError'],
        'a value error raised outside the validator' => [fn() => new ValueError('made up'), 'ValueError'],
        'an arithmetic error' => [fn() => new DivisionByZeroError('made up'), 'DivisionByZeroError'],
        'an error' => [fn() => new Error('made up'), 'Error'],
    ]);

    it('passes as it is when it is an exception that is not of the validator: only the handler of the host can throw one', function (): void {
        $thrown = new ErrorException('made up');

        expect(checkerInternal('insteadOf', $thrown, checkerInternal('foldersOfValidator')))->toBe($thrown);
    });
});

describe('step 6: a schema the validator cannot use', function (): void {
    it('is refused as rejected with a fixed detail that names at most the keyword', function (string $schemaJson, string $text, string $detail): void {
        $refusal = schemaRefusal($schemaJson, $text);

        expect($refusal->reason)->toBe(ErrorReason::Rejected)
            ->and($refusal->detail)->toBe($detail)
            ->and($refusal->getMessage())->toBe('rejected: ' . $detail)
            ->and($refusal->httpStatus)->toBeNull()
            ->and($refusal->providerCode)->toBeNull()
            ->and($refusal->attempts)->toBe(0)
            ->and($refusal->getPrevious())->toBeNull();
    })->with([
        'a keyword of the wrong kind' => [
            '{"type":"object","required":"city"}',
            '{"city":"Utrecht"}',
            'the response schema is not a valid JSON Schema: the keyword required is not valid',
        ],
        'a keyword of the wrong kind, deeper' => [
            '{"type":"object","properties":{"a":{"type":5}}}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema: the keyword type is not valid',
        ],
        'a type the validator fails on with a type error' => [
            '{"type":"object","properties":{"a":{"type":[null]}}}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema',
        ],
        'a pattern that is no regular expression' => [
            '{"type":"object","properties":{"a":{"type":"string","pattern":"("}}}',
            '{"a":"x"}',
            'the response schema is not a valid JSON Schema: the keyword pattern is not valid',
        ],
        'a draft the validator does not know' => [
            '{"$schema":"http://json-schema.org/draft-04/schema#","type":"object"}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema',
        ],
        'a draft that is no text' => [
            '{"$schema":7,"type":"object"}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema',
        ],
        'an id of the root that is no full address' => [
            '{"$id":"verdict.json","type":"object"}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema',
        ],
        'an id that is no text' => [
            '{"$id":[1],"type":"object"}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema',
        ],
        'the same id twice' => [
            '{"type":"object","properties":{"a":{"$id":"https://schemas.example.test/x"},"b":{"$id":"https://schemas.example.test/x"}}}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema',
        ],
        'a reference to a place that is not in the schema' => [
            '{"type":"object","properties":{"a":{"$ref":"#/$defs/missing"}}}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema: a reference in it cannot be resolved, and no other schema is ever fetched',
        ],
        'a reference to an address' => [
            '{"type":"object","properties":{"a":{"$ref":"https://schemas.example.test/a.json"}}}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema: a reference in it cannot be resolved, and no other schema is ever fetched',
        ],
        'a reference to an address without TLS' => [
            '{"type":"object","properties":{"a":{"$ref":"http://schemas.example.test/a.json#/x"}}}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema: a reference in it cannot be resolved, and no other schema is ever fetched',
        ],
        'a reference to another file by a relative name' => [
            '{"type":"object","properties":{"a":{"$ref":"other.json#/x"}}}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema: a reference in it cannot be resolved, and no other schema is ever fetched',
        ],
        'a reference next to an id with an address' => [
            '{"$id":"https://schemas.example.test/root.json","type":"object","properties":{"a":{"$ref":"other.json"}}}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema: a reference in it cannot be resolved, and no other schema is ever fetched',
        ],
        'a reference with a template' => [
            '{"type":"object","properties":{"a":{"$ref":"https://schemas.example.test/{name}"}}}',
            '{"a":1}',
            'the response schema is not a valid JSON Schema: the keyword $ref is not valid',
        ],
        'the "$data" addition of the validator' => [
            '{"type":"object","properties":{"a":{"type":"integer","minimum":{"$data":"/b"}}}}',
            '{"a":1,"b":5}',
            'the response schema is not a valid JSON Schema: the keyword minimum is not valid',
        ],
    ]);

    it('does not read the file a reference names', function (): void {
        // The file exists and holds a schema that would refuse the answer. Were it read, the result would be a
        // mismatch; it is not read, so the reference cannot be resolved.
        $file = dirname(__DIR__, 3) . '/fixtures/shared/never-read.schema.json';
        expect(is_file($file))->toBeTrue();

        $refusal = schemaRefusal('{"type":"object","properties":{"a":{"$ref":"file://' . $file . '"}}}', '{"a":1}');

        expect($refusal->reason)->toBe(ErrorReason::Rejected)
            ->and($refusal->detail)->toContain('a reference in it cannot be resolved');
    });

    it('opens no address a reference or an id names', function (string $schemaJson): void {
        RecordingStreamWrapper::$opened = [];
        foreach (['http', 'https'] as $scheme) {
            if (in_array($scheme, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($scheme);
            }
            stream_wrapper_register($scheme, RecordingStreamWrapper::class, STREAM_IS_URL);
        }

        try {
            $refusal = schemaRefusal($schemaJson, '{"a":1}');
        } finally {
            foreach (['http', 'https'] as $scheme) {
                stream_wrapper_unregister($scheme);
                @stream_wrapper_restore($scheme);
            }
        }

        expect($refusal->reason)->toBe(ErrorReason::Rejected)
            ->and(RecordingStreamWrapper::$opened)->toBe([]);
    })->with([
        'a reference' => ['{"type":"object","properties":{"a":{"$ref":"https://schemas.example.test/a.json"}}}'],
        'a reference without TLS' => ['{"type":"object","properties":{"a":{"$ref":"http://schemas.example.test/a.json"}}}'],
        'a reference at the root' => ['{"type":"object","$ref":"https://schemas.example.test/a.json"}'],
        'a relative reference under an id' => ['{"$id":"https://schemas.example.test/root.json","type":"object","properties":{"a":{"$ref":"a.json"}}}'],
        'a dynamic reference' => ['{"type":"object","properties":{"a":{"$dynamicRef":"https://schemas.example.test/a.json#node"}}}'],
    ]);

    it('takes an id and a draft with an address as names only: nothing is fetched for them', function (): void {
        $format = formatOf('{"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://schemas.example.test/verdict.json","type":"object","properties":{"a":{"type":"integer"}}}');

        expect(checked('{"a":1}', $format)->isUsable())->toBeTrue()
            ->and(checked('{"a":"x"}', $format)->reasons())->toBe(['/a: The data (string) must match the type: integer']);
    });

    it('knows the drafts 06, 07, 2019-09 and 2020-12, and reads a schema without a draft as 2020-12', function (): void {
        // "prefixItems" exists from 2020-12 on; in draft 07 the same check is written as a list under "items".
        $newest = '"type":"object","properties":{"a":{"type":"array","prefixItems":[{"type":"integer"}]}}}';
        $older = '"type":"object","properties":{"a":{"type":"array","items":[{"type":"integer"}]}}}';

        expect(checked('{"a":["x"]}', formatOf('{' . $newest))->reasons())->toBe(['/a/0: The data (string) must match the type: integer'])
            ->and(checked('{"a":["x"]}', formatOf('{"$schema":"https://json-schema.org/draft/2020-12/schema",' . $newest))->isUsable())->toBeFalse()
            ->and(checked('{"a":["x"]}', formatOf('{"$schema":"https://json-schema.org/draft/2019-09/schema",' . $older))->isUsable())->toBeFalse()
            ->and(checked('{"a":["x"]}', formatOf('{"$schema":"http://json-schema.org/draft-07/schema#",' . $older))->isUsable())->toBeFalse()
            ->and(checked('{"a":["x"]}', formatOf('{"$schema":"http://json-schema.org/draft-06/schema#",' . $older))->isUsable())->toBeFalse()
            ->and(checked('{"a":["x"]}', formatOf('{"$schema":"http://json-schema.org/draft-07/schema#",' . $newest))->isUsable())->toBeTrue();
    });

    it('finds a fault only in the part of the schema the answer reaches', function (): void {
        $format = formatOf('{"type":"object","properties":{"a":{"type":5},"b":{"$ref":"https://schemas.example.test/b.json"}}}');

        expect(checked('{"c":1}', $format)->isUsable())->toBeTrue()
            ->and(fn() => checked('{"a":1}', $format))->toThrow(GatewayException::class)
            ->and(fn() => checked('{"b":1}', $format))->toThrow(GatewayException::class);
    });

    it('keeps the answer, the schema and the paths of the host out of the refusal, in any output form', function (): void {
        foreach ([false, true] as $ignoreArgs) {
            withExceptionIgnoreArgs($ignoreArgs, function (): void {
                $schemas = [
                    '{"type":"object","properties":{"' . LEAK_MARKER . '":{"type":[null]}}}',
                    '{"type":"object","properties":{"' . LEAK_MARKER . '":{"$ref":"https://schemas.example.test/' . LEAK_MARKER . '"}}}',
                    '{"type":"object","properties":{"' . LEAK_MARKER . '":{"pattern":"(' . LEAK_MARKER . '"}}}',
                ];

                foreach ($schemas as $schemaJson) {
                    $refusal = schemaRefusal($schemaJson, '{"' . LEAK_MARKER . '":"' . LEAK_MARKER . '"}');
                    $forms = exceptionForms($refusal);

                    expectNoMarker($forms);
                    expect($forms['getMessage'])->not->toContain('vendor')
                        ->and($forms['getMessage'])->not->toContain('.php')
                        ->and($forms['getMessage'])->not->toContain('Opis')
                        ->and($forms['string cast'])->not->toContain('Opis\\JsonSchema\\Exceptions');
                }
            });
        }
    });
});
