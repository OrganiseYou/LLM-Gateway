<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\RawJson;

// JSON read as the text it is (decision G100): an adapter cuts a value out of a body without decoding it, so that
// what goes back to a server is what came. Decoded to arrays, an empty object is an empty list, a large whole
// number is a float and a number loses its spelling.

/** The two bytes that begin an escape of JSON for a character by its number: a backslash and the letter u. */
const RAW_JSON_ESC = "\x5cu";

const RAW_JSON_BODY = '{ "id" : "a" , "choices" : [ { "index" : 0 , "message" : { "role" : "assistant" , "content" : [ { "type" : "thinking" , "thinking" : [ ] , "details" : { } , "score" : 1.0 } ] } } , { "message" : { "content" : "second" } } ] , "usage" : null }';

describe('the text of a value at a path', function (): void {
    it('is cut out as it is written, without the white space around it', function (array $path, ?string $text): void {
        expect(RawJson::at(RAW_JSON_BODY, array_values($path)))->toBe($text);
    })->with([
        'a string' => [['id'], '"a"'],
        'a list inside objects and lists' => [['choices', 0, 'message', 'content'], '[ { "type" : "thinking" , "thinking" : [ ] , "details" : { } , "score" : 1.0 } ]'],
        'a number in its spelling' => [['choices', 0, 'message', 'content', 0, 'score'], '1.0'],
        'an empty object' => [['choices', 0, 'message', 'content', 0, 'details'], '{ }'],
        'an empty list' => [['choices', 0, 'message', 'content', 0, 'thinking'], '[ ]'],
        'the second item of a list' => [['choices', 1, 'message', 'content'], '"second"'],
        'null' => [['usage'], 'null'],
        'the whole text' => [[], RAW_JSON_BODY],
        'a name that is not there' => [['choices', 0, 'delta'], null],
        'an item that is not there' => [['choices', 2], null],
        'a name asked of a list' => [['choices', 'message'], null],
        'a number asked of an object' => [[0], null],
        'a step below a string' => [['id', 'x'], null],
    ]);

    it('takes the last of a name that is written twice, and knows a name written with escapes', function (): void {
        $json = '{"content":[1],"c' . RAW_JSON_ESC . '006fntent":[2],"other":{"content":[3]},"a\"b":true,"a/b":"x","' . RAW_JSON_ESC . '00e9":0}';

        expect(RawJson::at($json, ['content']))->toBe('[2]')
            ->and(RawJson::at($json, ['other', 'content']))->toBe('[3]')
            ->and(RawJson::at($json, ['a"b']))->toBe('true')
            ->and(RawJson::at($json, ['a/b']))->toBe('"x"')
            ->and(RawJson::at($json, ['é']))->toBe('0')
            ->and(array_keys((array) RawJson::members($json)))->toBe(['content', 'other', 'a"b', 'a/b', 'é']);
    });

    it('is not misled by the marks of JSON inside a string', function (): void {
        $json = '{"a":"} ] , \" { [ : \\\\","b":["]","}",{"c":"\\\\\"]"}],"d":7}';

        expect(RawJson::at($json, ['a']))->toBe('"} ] , \" { [ : \\\\"')
            ->and(RawJson::at($json, ['b', 2, 'c']))->toBe('"\\\\\"]"')
            ->and(RawJson::at($json, ['d']))->toBe('7')
            ->and(RawJson::items((string) RawJson::at($json, ['b'])))->toBe(['"]"', '"}"', '{"c":"\\\\\"]"}']);
    });

    it('gives the members of an object and the items of a list, and null for anything else', function (): void {
        expect(RawJson::members('{"a":1,"0":{},"b":[true,null]}'))->toBe(['a' => '1', 0 => '{}', 'b' => '[true,null]'])
            ->and(RawJson::members(' { } '))->toBe([])
            ->and(RawJson::members('[1]'))->toBeNull()
            ->and(RawJson::members('"{}"'))->toBeNull()
            ->and(RawJson::members(''))->toBeNull()
            ->and(RawJson::items('[1, "a" ,{"b":[]},[ ],-2.5e3]'))->toBe(['1', '"a"', '{"b":[]}', '[ ]', '-2.5e3'])
            ->and(RawJson::items('[]'))->toBe([])
            ->and(RawJson::items('{"a":1}'))->toBeNull()
            ->and(RawJson::items('7'))->toBeNull();
    });
});

describe('one spelling', function (): void {
    it('drops the white space between the parts, writes every string with the fewest escapes and leaves the rest as it is', function (string $json, string $plain): void {
        expect(RawJson::plain($json))->toBe($plain)
            // The value is the value it was.
            ->and(json_decode($plain))->toEqual(json_decode($json));
    })->with([
        'white space' => [" { \"a\" :\t[ 1 ,\r\n 2 ] , \"b\" : { } } ", '{"a":[1,2],"b":{}}'],
        'white space inside a string' => ['{"a":"  b \t c  "}', '{"a":"  b \t c  "}'],
        'numbers' => ['[1.0,1e2,-0.0,2.50E-3,12345678901234567890123,0]', '[1.0,1e2,-0.0,2.50E-3,12345678901234567890123,0]'],
        'words' => ['[true , false , null]', '[true,false,null]'],
        'an escaped letter, a pair of escapes and a slash' => ['["' . RAW_JSON_ESC . '0041' . RAW_JSON_ESC . 'd83d' . RAW_JSON_ESC . 'de42","a\/b"]', '["A🙂","a/b"]'],
        'the escapes JSON needs' => ['["a\"b","c\\\\d","e\nf","' . RAW_JSON_ESC . '0001"]', '["a\"b","c\\\\d","e\nf","' . RAW_JSON_ESC . '0001"]'],
        'an escaped name' => ['{"' . RAW_JSON_ESC . '0061":1}', '{"a":1}'],
        'a string alone' => [' "a" ', '"a"'],
    ]);

    it('makes a word findable that a server wrote with escapes', function (): void {
        $word = 'key-of-the-test';
        $escaped = '';
        foreach (str_split($word) as $letter) {
            $escaped .= RAW_JSON_ESC . sprintf('%04x', ord($letter));
        }

        expect(RawJson::plain('[{"text":"' . $escaped . '"}]'))->toBe('[{"text":"key-of-the-test"}]');
    });

    it('writes an object and a list from the text of their parts', function (): void {
        expect(RawJson::object(['a' => '1', 0 => '{}', 'b"c' => '[1.0]']))->toBe('{"a":1,"0":{},"b\"c":[1.0]}')
            ->and(RawJson::object([]))->toBe('{}')
            ->and(RawJson::list(['1', '{}', '"a"']))->toBe('[1,{},"a"]')
            ->and(RawJson::list([]))->toBe('[]')
            ->and(RawJson::string('a/b "c" é'))->toBe('"a/b \"c\" é"');
    });
});

describe('text that is no JSON', function (): void {
    it('ends in null or in a text, never in an error and never in a loop: every cut of a body, and every byte changed', function (): void {
        $json = '{"a":[{"b":"c\"d","e":[1,2.5,{}]},"f"],"g":{"h":null},"i":"' . RAW_JSON_ESC . '00e9"}';

        $tried = 0;
        for ($at = 0; $at <= strlen($json); $at++) {
            $texts = [substr($json, 0, $at), substr($json, $at)];
            foreach (['"', '\\', '{', '}', '[', ']', ',', ':', ' '] as $byte) {
                $texts[] = substr($json, 0, $at) . $byte . substr($json, $at + 1);
            }
            foreach ($texts as $text) {
                // Each of these comes back, with a text or with null.
                $read = [RawJson::at($text, ['a', 0, 'e', 2]), RawJson::at($text, ['g', 'h']), RawJson::members($text), RawJson::items($text)];
                expect($read)->toHaveCount(4);
                $plain = RawJson::plain($text);
                // What the decoder accepts keeps its value in one spelling.
                $decoded = json_decode($text);
                if (json_last_error() === JSON_ERROR_NONE) {
                    expect($plain)->not->toBeNull()
                        ->and(json_decode((string) $plain))->toEqual($decoded);
                }
                $tried++;
            }
        }

        expect($tried)->toBeGreaterThan(500);
    });
});

describe('text that is measured before it is read', function (): void {
    it('counts the objects and lists outside the strings, and stops one above the limit', function (string $json, int $limit, int $count): void {
        expect(RawJson::containers($json, $limit))->toBe($count);
    })->with([
        'none' => ['"a"', 10, 0],
        'a list of objects' => ['[{},{},{"a":[]}]', 10, 5],
        'brackets inside a string' => ['{"a":"[{[{","b":"\"[{"}', 10, 1],
        'at the limit' => ['[[],[],[]]', 4, 4],
        'above the limit: the count stops' => ['[' . str_repeat('{},', 1000) . '{}]', 4, 5],
        'a string that never ends is over the limit' => ['[{"a":"b', 10, 11],
        'nothing' => ['', 10, 0],
    ]);

    it('finds a value in a large body without a copy of what stands beside it', function (): void {
        // 300,000 choices after the first, and 300,000 members before the one that is asked for.
        $body = '{"filler":{' . str_repeat('"a":{},', 300_000) . '"z":0},"choices":[{"message":{"content":[{"type":"thinking","thinking":[]}]}}' . str_repeat(',{}', 300_000) . ']}';

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $content = RawJson::at($body, ['choices', 0, 'message', 'content']);
        $taken = memory_get_peak_usage() - $before;

        expect($content)->toBe('[{"type":"thinking","thinking":[]}]')
            ->and($taken)->toBeLessThan(65_536);
    });

    it('writes the two line ends of Unicode as they are, so that a search of the text finds them', function (): void {
        $lineEnd = "\xe2\x80\xa8";

        expect(RawJson::plain('["a' . RAW_JSON_ESC . '2028b' . RAW_JSON_ESC . '2029c"]'))->toBe('["a' . $lineEnd . "b\xe2\x80\xa9c\"]")
            ->and(RawJson::string('a' . $lineEnd . 'b'))->toBe('"a' . $lineEnd . 'b"');
    });
});

describe('a member name that an object holds twice', function (): void {
    it('is found at any depth, also when one of the two is written with an escape', function (string $json): void {
        expect(RawJson::hasRepeatedName($json))->toBeTrue();
    })->with([
        'at the top' => ['{"a":1,"a":2}'],
        'in an object of a list' => ['[{"type":"tool_use","id":"x","type":"thinking"}]'],
        'deep inside' => ['[{"a":{"b":[1,{"c":{"d":true,"e":[],"d":false}}]}}]'],
        'with white space around the marks' => ['{ "a" : 1 , "b" : { } , "a" : 2 }'],
        'one of the two written with an escape' => ['{"type":1,"t' . RAW_JSON_ESC . '0079pe":2}'],
        'an empty name twice' => ['{"":1,"":2}'],
        'names that are numbers' => ['{"1":"a","1":"b"}'],
        'after a nested object closed' => ['{"a":{"x":1},"b":[{"y":2}],"a":3}'],
        'a string that never ends' => ['{"a":"b'],
    ]);

    it('is not found where every object holds each name once, whatever its strings and values say', function (string $json): void {
        expect(RawJson::hasRepeatedName($json))->toBeFalse();
    })->with([
        'an empty list' => ['[]'],
        'a string' => ['"a"'],
        'the same name in two objects' => ['[{"type":"text"},{"type":"text"}]'],
        'the same name in an object and in the object inside it' => ['{"a":{"a":{"a":1}}}'],
        'a value that is the same string as a name' => ['{"a":"a","b":"a","c":["a","a"]}'],
        'marks inside a string' => ['{"a":"{\"a\":1,\"a\":2}","b":"x,\"a\":1"}'],
        'the same strings in a list' => ['["a","a",{"b":["c","c"]}]'],
        'a name after a list of strings' => ['{"list":["a","b"],"a":1,"b":2}'],
        'names that differ in case' => ['{"a":1,"A":2}'],
        'white space and line ends' => ["{\n  \"a\": {},\n  \"b\": []\n}"],
    ]);

    it('walks a long text once and keeps only the names of the objects that are open', function (): void {
        $json = '[' . str_repeat('{"type":"text","text":"a"},', 30_000) . '{"type":"text","text":"a"}]';
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        expect(RawJson::hasRepeatedName($json))->toBeFalse()
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(65_536);
    });
});

describe('the longest value that is cut out', function (): void {
    it('gives a value of at most that many bytes, and no longer one', function (): void {
        $json = '{"content":[1,2,3],"model":"m"}';

        expect(RawJson::at($json, ['content'], 7))->toBe('[1,2,3]')
            ->and(RawJson::at($json, ['content'], 6))->toBeNull()
            ->and(RawJson::at($json, ['content', 0], 1))->toBe('1')
            ->and(RawJson::at($json, ['model'], 0))->toBeNull()
            ->and(RawJson::at($json, ['content'], null))->toBe('[1,2,3]');
    });

    it('does not copy a value that is too long', function (): void {
        $json = '{"content":"' . str_repeat('a', 4_000_000) . '"}';
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        expect(RawJson::at($json, ['content'], 1_048_576))->toBeNull()
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(65_536);
    });
});
