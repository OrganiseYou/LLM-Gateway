<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Gemini\ThoughtParts;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContent;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContentFixtures;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// Thought signatures and tool calls in the generate-content adapter (docs/design/adapters.md, section 5.1;
// decisions G78, G100, G112, G115, G118): the parts of an answer that carries a signature or a tool call are kept
// in the opaque part as the JSON text the server wrote, within limits that are measured as text, and go back only
// as what the format documents and only with the message they came with.

const GENERATE_SIGNATURE = 'c2lnLWV4YW1wbGU=';

// A backslash and a "u", built from a constant, so that no escape of this file's own text stands in for it.
const GENERATE_ESCAPE = "\x5cu";

/**
 * A signed call of "lookup" as a part.
 *
 * @return array<string, mixed>
 */
function generateSignedCall(?string $id = 'fc-example01'): array
{
    return GenerateContent::call(['city' => 'Paris'], $id, 'lookup', GENERATE_SIGNATURE);
}

/**
 * One round and the next: the gateway gets this body as its answer, and then sends the message of that answer,
 * changed by `$alter` when one is given, with a result for each of its calls.
 *
 * @param (callable(Message): Message)|null $alter
 * @return array{Answer, string} the answer of the first round, and the body of the second request
 */
function generateRounds(string $body, ?callable $alter = null): array
{
    $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], $body))->willReturn(GenerateContent::answered());
    $gateway = GenerateContent::gateway($transport);
    $request = GenerateContent::request()->withTools([GenerateContent::lookup()]);

    $answer = $gateway->complete($request);
    $message = $alter === null ? $answer->toMessage() : $alter($answer->toMessage());
    $messages = [...$request->messages, $message];
    foreach ($message->toolCalls as $toolCall) {
        $messages[] = Message::toolResult($toolCall->id, $toolCall->name, 'ok');
    }
    $gateway->complete($request->withMessages($messages));

    return [$answer, $transport->requests()[1]->body];
}

/**
 * The message with the data of its opaque part changed.
 *
 * @param callable(string): string $change
 */
function generateWithChangedPart(Message $message, callable $change): Message
{
    $parts = [];
    foreach ($message->parts as $part) {
        $parts[] = $part instanceof OpaquePart ? new OpaquePart($change($part->data)) : $part;
    }

    return new Message(Role::Assistant, $parts, $message->toolCalls);
}

/**
 * The body of an answer with these parts, with the parts written as this JSON text.
 */
function generateBodyWithParts(string $parts): string
{
    return str_replace('["PARTS"]', $parts, GenerateContent::json(GenerateContent::body(['PARTS'])));
}

describe('the parts of an answer with a thought signature', function (): void {
    it('are kept in the opaque part as the JSON text the server wrote, and go back as the parts of that model turn', function (): void {
        $parts = '[ {"text": "Let me look."}, {"functionCall": {"id": "fc-example01", "name": "lookup", "args": { "n": 1.0, "big": 12345678901234567890, "empty": {} }}, "thoughtSignature": "' . GENERATE_SIGNATURE . '"} ]';
        [$answer, $sent] = generateRounds(generateBodyWithParts($parts));

        expect($answer->text)->toBe('Let me look.')
            ->and($answer->toolCalls[0]->rawArguments)->toBe('{"n":1.0,"big":12345678901234567890,"empty":{}}')
            ->and($answer->opaque)->not->toBeNull();
        assert($answer->opaque !== null);

        $kept = '[{"text":"Let me look."},{"functionCall":{"id":"fc-example01","name":"lookup","args":{"n":1.0,"big":12345678901234567890,"empty":{}}},"thoughtSignature":"' . GENERATE_SIGNATURE . '"}]';
        expect($answer->opaque->data)->toStartWith('{"f":"a3","m":"')
            // One string, the JSON text itself: nothing of it was decoded and written again.
            ->and(json_decode($answer->opaque->data, true)['c'])->toBe(['parts' => $kept])
            ->and($sent)->toContain('{"role":"model","parts":' . $kept . '}')
            ->and($sent)->toContain('{"functionResponse":{"id":"fc-example01","name":"lookup","response":{"output":"ok"}}}')
            ->and($sent)->not->toContain(GenerateContent::NO_SIGNATURE);
    });

    it('are not kept for an answer of text alone, with or without a thought: text has a neutral form', function (): void {
        $answer = GenerateContent::answerTo(GenerateContent::answered([GenerateContent::text('Thinking.', ['thought' => true]), GenerateContent::text()]));

        expect($answer->opaque)->toBeNull();
    });

    it('are kept with a signature on a text part, on a part with an empty text, and beside a thought', function (array $parts): void {
        [$answer, $sent] = generateRounds(GenerateContent::json(GenerateContent::body(array_values($parts))));

        expect($answer->opaque)->not->toBeNull()
            ->and($sent)->toContain('"parts":' . GenerateContent::json($parts) . '}');
    })->with([
        'a text part' => [[GenerateContent::text('Hello.', ['thoughtSignature' => GENERATE_SIGNATURE])]],
        'an empty text' => [[GenerateContent::text('Hello.'), GenerateContent::text('', ['thoughtSignature' => GENERATE_SIGNATURE])]],
        'a thought' => [[GenerateContent::text('Hm.', ['thought' => true]), generateSignedCall()]],
        'the first of two calls' => [[generateSignedCall(), GenerateContent::call(['city' => 'Rome'], 'fc-example02')]],
        'a call without arguments' => [[['functionCall' => ['name' => 'lookup'], 'thoughtSignature' => GENERATE_SIGNATURE]]],
        'an empty signature' => [[GenerateContent::text('Hello.', ['thoughtSignature' => ''])]],
    ]);

    it('are not kept when the list holds anything but the documented parts with the documented members of the expected type; the answer is read all the same', function (string $parts, string $text, int $calls): void {
        $answer = GenerateContent::answerTo(new HttpResponse(200, [], generateBodyWithParts($parts)));

        expect($answer->opaque)->toBeNull()
            ->and($answer->text)->toBe($text)
            ->and($answer->toolCalls)->toHaveCount($calls);
    })->with([
        'a part of another kind' => ['[{"text":"Hello.","thoughtSignature":"c2ln"},{"inlineData":{"mimeType":"image/png","data":"AAAA"}}]', 'Hello.', 0],
        'a part of another kind that carries the signature' => ['[{"text":"Hello."},{"executableCode":{"code":"x"},"thoughtSignature":"c2ln"}]', 'Hello.', 0],
        'a member no page names' => ['[{"text":"Hello.","thoughtSignature":"c2ln","someNewMember":1}]', 'Hello.', 0],
        'a thought that is no true or false' => ['[{"text":"Hello.","thought":"yes","thoughtSignature":"c2ln"}]', 'Hello.', 0],
        'a text that is no string, beside a signed part' => ['[{"text":["Hello."]},{"text":"Hello.","thoughtSignature":"c2ln"}]', 'Hello.', 0],
        'a call with a member no page names' => ['[{"functionCall":{"name":"lookup","args":{},"partialArgs":[]},"thoughtSignature":"c2ln"}]', '', 1],
        'a call whose arguments are a string' => ['[{"functionCall":{"name":"lookup","args":"{}"},"thoughtSignature":"c2ln"}]', '', 1],
        'a call whose arguments are a list' => ['[{"functionCall":{"name":"lookup","args":[1]},"thoughtSignature":"c2ln"}]', '', 1],
        'a call that says it is a thought' => ['[{"functionCall":{"name":"lookup","args":{}},"thought":true,"thoughtSignature":"c2ln"}]', '', 1],
        'a call with an empty id' => ['[{"functionCall":{"id":"","name":"lookup","args":{}},"thoughtSignature":"c2ln"}]', '', 1],
        'a call without a signature, with a member no page names' => ['[{"functionCall":{"name":"lookup","args":{},"willContinue":false}}]', '', 1],
        'a call whose id is a number' => ['[{"functionCall":{"id":7,"name":"lookup"},"thoughtSignature":"c2ln"}]', '', 1],
        'a text and a call in one part' => ['[{"text":"Hello.","functionCall":{"name":"lookup"},"thoughtSignature":"c2ln"}]', '', 1],
        'a part with neither' => ['[{"text":"Hello."},{"thoughtSignature":"c2ln"}]', 'Hello.', 0],
        'something that is no object' => ['[{"text":"Hello.","thoughtSignature":"c2ln"},"a string"]', 'Hello.', 0],
        'a member name twice on a part' => ['[{"text":"Hello.","thoughtSignature":"first","thoughtSignature":"c2ln"}]', 'Hello.', 0],
        'a member name twice in the arguments' => ['[{"functionCall":{"name":"lookup","args":{"city":"Rome","city":"Paris"}},"thoughtSignature":"c2ln"}]', '', 1],
        'a member name twice, once written with an escape' => ['[{"text":"Hello.","thoughtSignature":"c2ln","t' . GENERATE_ESCAPE . '0065xt":"Other."}]', 'Other.', 0],
    ]);
});

describe('the limits of the parts that are kept', function (): void {
    it('keeps no list of more than 1 MiB, and reads the answer all the same', function (): void {
        $within = GenerateContent::answerTo(GenerateContent::answered([GenerateContent::text('Hello.', ['thoughtSignature' => str_repeat('a', 1_000_000)])]));
        $over = GenerateContent::answerTo(GenerateContent::answered([GenerateContent::text('Hello.', ['thoughtSignature' => str_repeat('a', 1_048_576)])]));

        expect($within->opaque)->not->toBeNull()
            ->and($over->opaque)->toBeNull()
            ->and($over->text)->toBe('Hello.');
    });

    it('keeps no list with more than 10,000 objects and lists, counted as text before anything is decoded', function (): void {
        $nested = fn(int $lists): string => '[{"functionCall":{"name":"lookup","args":{"a":' . str_repeat('[', $lists) . str_repeat(']', $lists) . '}},"thoughtSignature":"c2ln"}]';

        // The list, the part, the call and the arguments are four; the nested lists are the rest.
        expect(ThoughtParts::kept($nested(100)))->toBe($nested(100))
            ->and(ThoughtParts::isWithinTheLimits($nested(9_996)))->toBeTrue()
            ->and(ThoughtParts::isWithinTheLimits($nested(9_997)))->toBeFalse()
            ->and(ThoughtParts::kept($nested(9_997)))->toBeNull()
            ->and(ThoughtParts::agreeing($nested(9_997), '', []))->toBeNull();
    });

    it('counts a bracket inside a string as text, not as a list', function (): void {
        $list = '[{"text":"' . str_repeat('[{', 20_000) . '","thoughtSignature":"c2ln"}]';

        expect(ThoughtParts::kept($list))->toBe($list);
    });

    it('refuses a made list of very many objects on the way back without decoding it', function (): void {
        // 900 KB of empty objects: within the bytes, far over the count. Decoded, it would take many megabytes.
        $list = '[{"text":"Hello.","thoughtSignature":"c2ln"}' . str_repeat(',{}', 300_000) . ']';

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $kept = ThoughtParts::agreeing($list, 'Hello.', []);
        $rise = memory_get_peak_usage() - $before;

        expect($kept)->toBeNull()
            ->and($rise)->toBeLessThan(4 * 1024 * 1024);
    });

    it('stops keeping in a stream with the chunk that is over a limit, or with which the parts pass one, and reads the answer all the same', function (array $chunks, int $calls): void {
        $answer = Talk::finishedIn(GenerateContent::eventsOf($chunks))->answer;

        expect($answer->opaque)->toBeNull()
            ->and($answer->toolCalls)->toHaveCount($calls)
            ->and($answer->text)->toStartWith('Hello.');
    })->with([
        'one chunk over the bytes' => [fn() => [
            GenerateContent::chunk([GenerateContent::text('Hello.')]),
            GenerateContent::chunk([GenerateContent::text('', ['thoughtSignature' => str_repeat('a', 1_048_576)])], 'STOP'),
        ], 0],
        'many chunks that pass the bytes together' => [fn() => [
            GenerateContent::chunk([GenerateContent::text('Hello.', ['thoughtSignature' => 'c2ln'])]),
            ...array_fill(0, 12, GenerateContent::chunk([GenerateContent::text(str_repeat('a', 100_000))])),
            GenerateContent::chunk([GenerateContent::text('!')], 'STOP'),
        ], 0],
        'many chunks that pass the count together' => [fn() => [
            GenerateContent::chunk([GenerateContent::text('Hello.', ['thoughtSignature' => 'c2ln'])]),
            ...array_fill(0, 3_400, GenerateContent::chunk([GenerateContent::call(['city' => 'Paris'], null)])),
            GenerateContent::chunk([], 'STOP'),
        ], 3_400],
        'one chunk over the count' => [fn() => [
            GenerateContent::chunk([GenerateContent::text('Hello.', ['thoughtSignature' => 'c2ln'])]),
            GenerateContent::chunk([GenerateContent::call(['a' => json_decode(str_repeat('[', 100) . str_repeat(']', 100), true)], null)]),
            'data: ' . str_replace('"ARGS"', '{"a":[[]' . str_repeat(',[]', 10_000) . ']}', GenerateContent::json(GenerateContent::body([GenerateContent::call('ARGS', null)], 'STOP'))) . "\n\n",
        ], 2],
    ]);

    it('measures the pieces of a stream chunk by chunk', function (): void {
        $pieces = ThoughtParts::none();

        expect(ThoughtParts::add($pieces, '[{"text":"' . str_repeat('a', 600_000) . '","thoughtSignature":"c2ln"}]'))->toBeTrue()
            ->and(ThoughtParts::holdWhatGoesBack($pieces))->toBeTrue()
            ->and(ThoughtParts::add($pieces, '[{"text":"' . str_repeat('b', 300_000) . '"}]'))->toBeTrue()
            // With this one the pieces pass 1 MiB: it is refused before it is cut into its parts.
            ->and(ThoughtParts::add($pieces, '[{"text":"' . str_repeat('c', 300_000) . '"}]'))->toBeFalse();
    });

    it('refuses a chunk of a stream that is no list of documented parts', function (string $json): void {
        $pieces = ThoughtParts::none();

        expect(ThoughtParts::add($pieces, $json))->toBeFalse();
    })->with([
        'no list' => ['{"text":"Hello."}'],
        'an empty list' => ['[]'],
        'no JSON' => ['[{"text":"Hello."'],
        'a part of another kind' => ['[{"inlineData":{}}]'],
        'a name twice' => ['[{"text":"a","text":"b"}]'],
        'a member no page names' => ['[{"text":"a","more":1}]'],
        'too many lists' => ['[{"functionCall":{"name":"x","args":{"a":' . str_repeat('[', 10_000) . str_repeat(']', 10_000) . '}}}]'],
    ]);
});

describe('the parts of a stream with a thought signature', function (): void {
    it('are put together to the list a whole answer holds: a text part joins the one before it, a signed part and a thought stand on their own', function (): void {
        $events = GenerateContent::eventsOf([
            GenerateContent::chunk([GenerateContent::text('Hm, ', ['thought' => true])]),
            GenerateContent::chunk([GenerateContent::text('one city.', ['thought' => true])]),
            GenerateContent::chunk([GenerateContent::text('Let me ')]),
            GenerateContent::chunk([GenerateContent::text('look'), GenerateContent::text('.', ['thought' => false])]),
            GenerateContent::chunk([GenerateContent::text('', ['thoughtSignature' => 'c2ln'])]),
            GenerateContent::chunk([GenerateContent::text(' Now.')]),
            GenerateContent::chunk([generateSignedCall(null)]),
            GenerateContent::chunk([GenerateContent::call(['city' => 'Rome'], null)], 'STOP'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->text)->toBe('Let me look. Now.')
            ->and($answer->opaque)->not->toBeNull();
        assert($answer->opaque !== null);
        expect(json_decode($answer->opaque->data, true)['c']['parts'])->toBe(
            '[{"text":"Hm, one city.","thought":true},{"text":"Let me look."},{"text":"","thoughtSignature":"c2ln"},{"text":" Now."},'
            . '{"functionCall":{"name":"lookup","args":{"city":"Paris"}},"thoughtSignature":"' . GENERATE_SIGNATURE . '"},{"functionCall":{"name":"lookup","args":{"city":"Rome"}}}]',
        );
    });

    it('give the same opaque part as the whole answer, byte for byte, for the fixture pairs of both sets', function (string $set): void {
        $fixtures = new GenerateContentFixtures($set);
        $whole = $fixtures->answer(ContractFixture::ToolCallWithOpaqueData);
        $chunks = $fixtures->stream(ContractFixture::ToolCallWithOpaqueData);
        assert($whole instanceof HttpResponse && is_array($chunks));
        $request = GenerateContent::request()->withTools([GenerateContent::lookup()]);

        $answered = GenerateContent::answerTo($whole, request: $request);
        $streamed = Talk::finishedIn(GenerateContent::eventsOf($chunks, request: $request))->answer;

        expect($answered->opaque)->not->toBeNull()
            ->and($streamed->opaque?->data)->toBe($answered->opaque?->data);
    })->with([[GenerateContentFixtures::KEY_IN_A_HEADER], [GenerateContentFixtures::TOKEN]]);

    it('are not kept when a chunk holds a part that is not kept, also when a later chunk carries a signature', function (string $chunk): void {
        $events = GenerateContent::eventsOf([GenerateContent::chunk([GenerateContent::text('Hello.')]), $chunk, GenerateContent::chunk([GenerateContent::text('', ['thoughtSignature' => 'c2ln'])], 'STOP')]);
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->opaque)->toBeNull()
            ->and($answer->text)->toBe('Hello.');
    })->with([
        'a part of another kind' => [GenerateContent::chunk([['inlineData' => ['mimeType' => 'image/png', 'data' => 'AAAA']]])],
        'a member no page names' => [GenerateContent::chunk([GenerateContent::text('', ['more' => 1])])],
        'a name twice' => ['data: ' . generateBodyWithParts('[{"text":"","thought":true,"thought":false}]') . "\n\n"],
        'parts that are an object' => ['data: ' . generateBodyWithParts('{"first":{"text":""}}') . "\n\n"],
    ]);
});

describe('the way back of the parts that were kept', function (): void {
    it('sends them only with the message they came with, and builds every other message from its neutral fields with the skip value', function (): void {
        $body = GenerateContent::json(GenerateContent::body([GenerateContent::text('Let me look.'), generateSignedCall()]));

        [, $unchanged] = generateRounds($body);
        [, $otherText] = generateRounds($body, fn(Message $message): Message => new Message(Role::Assistant, [...array_filter($message->parts, fn($part): bool => $part instanceof OpaquePart), new OrganiseYou\LlmGateway\Contract\TextPart('Let me see.')], $message->toolCalls));
        [, $otherCall] = generateRounds($body, fn(Message $message): Message => new Message(Role::Assistant, $message->parts, [ToolCall::decoded('fc-example01', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}')]));
        [, $byHand] = generateRounds($body, fn(Message $message): Message => Message::assistant($message->text(), ...$message->toolCalls));

        expect($unchanged)->toContain(GENERATE_SIGNATURE)
            ->and($unchanged)->not->toContain(GenerateContent::NO_SIGNATURE);
        foreach ([$otherText, $otherCall, $byHand] as $sent) {
            expect($sent)->not->toContain(GENERATE_SIGNATURE)
                ->and($sent)->toContain('"thoughtSignature":"' . GenerateContent::NO_SIGNATURE . '"');
        }
    });

    it('sends nothing of a part that was made or changed so that it is no longer what the adapter keeps, or no longer says what the message says', function (string $from, string $to, string $never): void {
        $body = GenerateContent::json(GenerateContent::body([GenerateContent::text('Let me look.'), generateSignedCall()]));
        [$answer, $sent] = generateRounds($body, fn(Message $message): Message => generateWithChangedPart($message, function (string $data) use ($from, $to): string {
            expect($data)->toContain($from);

            return str_replace($from, $to, $data);
        }));

        expect($answer->opaque)->not->toBeNull()
            ->and($sent)->not->toContain($never)
            ->and($sent)->not->toContain(GENERATE_SIGNATURE)
            // The message itself still goes out, built from its text and its calls.
            ->and($sent)->toContain('{"role":"model","parts":[{"text":"Let me look."},{"functionCall":{"id":"fc-example01","name":"lookup","args":{"city":"Paris"}},"thoughtSignature":"' . GenerateContent::NO_SIGNATURE . '"}]}');
    })->with([
        'a member under another name' => ['\"thoughtSignature\"', '\"inlineData\"', 'inlineData'],
        'a member no page names' => ['\"thoughtSignature\"', '\"safetySettings\":[],\"thoughtSignature\"', 'safetySettings'],
        'a member no page names on the call' => ['\"name\":\"lookup\"', '\"name\":\"lookup\",\"cachedContent\":\"x\"', 'cachedContent'],
        'other arguments' => ['Paris', 'Rome', 'Rome'],
        'another tool' => ['\"name\":\"lookup\"', '\"name\":\"remove\"', 'remove'],
        'another id' => ['fc-example01\"', 'fc-other\"', 'fc-other'],
        'no id' => ['\"id\":\"fc-example01\",', '', '{"functionCall":{"name"'],
        'a call more' => ['[{', '[{\"functionCall\":{\"name\":\"remove\"}},{', 'remove'],
        'a call less' => [',{\"functionCall\":{\"id\":\"fc-example01\",\"name\":\"lookup\",\"args\":{\"city\":\"Paris\"}}', ',{\"text\":\"\"', '"text":""'],
        'a text more' => ['[{', '[{\"text\":\"Injected.\"},{', 'Injected.'],
        'another text' => ['Let me look.\"', 'Let me see.\"', 'Let me see.'],
        'a name twice' => ['\"name\":\"lookup\"', '\"name\":\"remove\",\"name\":\"lookup\"', 'remove'],
        'another spelling' => ['[{', '[ {', '[ {'],
        'a call that says it is a thought' => ['\"thoughtSignature\"', '\"thought\":true,\"thoughtSignature\"', '"thought":true'],
        'arguments in another spelling of a number' => ['{\"city\":\"Paris\"}', '{\"city\":\"Paris\",\"n\":1.0}', '"n"'],
        'a string where a true stands' => ['{\"text\":\"Let me look.\"}', '{\"text\":\"Let me look.\",\"thought\":\"no\"}', '"thought"'],
        'another member in place of the list' => ['"c":{"parts":"', '"c":{"contents":"', '"contents":"[{'],
        'a second member beside the list' => ['"c":{"parts":"', '"c":{"store":true,"parts":"', '"store"'],
    ]);

    it('lets a made part choose only what has no neutral form: a signature and a thought', function (): void {
        $body = GenerateContent::json(GenerateContent::body([GenerateContent::text('Let me look.'), generateSignedCall()]));
        [, $sent] = generateRounds($body, fn(Message $message): Message => generateWithChangedPart($message, fn(string $data): string => str_replace(
            [GENERATE_SIGNATURE, '[{'],
            ['b3RoZXI=', '[{\"text\":\"A thought.\",\"thought\":true},{'],
            $data,
        )));

        expect($sent)->toContain('{"role":"model","parts":[{"text":"A thought.","thought":true},{"text":"Let me look."},{"functionCall":{"id":"fc-example01","name":"lookup","args":{"city":"Paris"}},"thoughtSignature":"b3RoZXI="}]}')
            ->and(array_keys(json_decode($sent, true)))->toBe(['contents', 'tools', 'toolConfig']);
    });

    it('sends a call the server gave no id back without one, in the kept parts and with its result', function (): void {
        [$answer, $sent] = generateRounds(GenerateContent::json(GenerateContent::body([generateSignedCall(null), GenerateContent::call(['city' => 'Rome'], null)])));

        expect(array_map(fn(ToolCall $call): string => $call->id, $answer->toolCalls))->toBe(['call-0', 'call-1'])
            ->and($sent)->toContain('{"functionCall":{"name":"lookup","args":{"city":"Paris"}},"thoughtSignature":"' . GENERATE_SIGNATURE . '"},{"functionCall":{"name":"lookup","args":{"city":"Rome"}}}')
            ->and($sent)->toContain('{"functionResponse":{"name":"lookup","response":{"output":"ok"}}}')
            ->and($sent)->not->toContain('call-0')
            ->and($sent)->not->toContain('call-1');
    });

    it('sends an id of the server back that looks like one the adapter makes, when the kept parts say the server gave it', function (): void {
        [$answer, $sent] = generateRounds(GenerateContent::json(GenerateContent::body([generateSignedCall('call-7')])));

        expect($answer->toolCalls[0]->id)->toBe('call-7')
            ->and($sent)->toContain('{"functionCall":{"id":"call-7","name":"lookup","args":{"city":"Paris"}},"thoughtSignature":"' . GENERATE_SIGNATURE . '"}')
            ->and($sent)->toContain('{"functionResponse":{"id":"call-7","name":"lookup","response":{"output":"ok"}}}');
    });

    it('cannot tell such an id from one it made in a message that was built by hand: it is then not sent back', function (): void {
        $body = GenerateContent::json(GenerateContent::body([GenerateContent::call(['city' => 'Paris'], 'call-7')]));
        [$answer, $sent] = generateRounds($body, fn(Message $message): Message => Message::assistant('', ...$message->toolCalls));

        expect($answer->toolCalls[0]->id)->toBe('call-7')
            ->and($sent)->toContain('{"functionCall":{"name":"lookup","args":{"city":"Paris"}},"thoughtSignature":"' . GenerateContent::NO_SIGNATURE . '"}')
            ->and($sent)->toContain('{"functionResponse":{"name":"lookup","response":{"output":"ok"}}}')
            ->and($sent)->not->toContain('call-7');
    });

    it('does not open a part that agrees with the message but was made for a call with an id of the wrong kind', function (): void {
        // The kept call has no id, so the call of the message must have an id the adapter makes.
        $list = '[{"functionCall":{"name":"lookup","args":{"city":"Paris"}},"thoughtSignature":"c2ln"}]';

        expect(ThoughtParts::agreeing($list, '', [['call-0', 'lookup', '{"city":"Paris"}']]))->not->toBeNull()
            ->and(ThoughtParts::agreeing($list, '', [['call-0-2', 'lookup', '{ "city" : "Paris" }']]))->not->toBeNull()
            ->and(ThoughtParts::agreeing($list, '', [['fc-1', 'lookup', '{"city":"Paris"}']]))->toBeNull()
            ->and(ThoughtParts::agreeing($list, '', [['call-0', 'lookup', '{"city":"Rome"}']]))->toBeNull()
            ->and(ThoughtParts::agreeing($list, 'More.', [['call-0', 'lookup', '{"city":"Paris"}']]))->toBeNull()
            ->and(ThoughtParts::agreeing($list, '', []))->toBeNull()
            ->and(ThoughtParts::agreeing(' ' . $list, '', [['call-0', 'lookup', '{"city":"Paris"}']]))->toBeNull();
    });

    it('compares the arguments of a call as text in one spelling, not as decoded values', function (string $kept, string $ofTheMessage, bool $agrees): void {
        $list = '[{"functionCall":{"id":"fc-1","name":"lookup","args":' . $kept . '}}]';

        expect(ThoughtParts::kept($list))->toBe($list)
            ->and(ThoughtParts::agreeing($list, '', [['fc-1', 'lookup', $ofTheMessage]]) !== null)->toBe($agrees);
    })->with([
        'the same text' => ['{"n":9223372036854775808}', '{"n":9223372036854775808}', true],
        'the same text with other whitespace' => ['{"n":1.0,"e":{}}', "{ \"n\" : 1.0,\n \"e\" : {} }", true],
        'the same string in another spelling' => ['{"path":"a/b","word":"é"}', '{"path":"a\\/b","word":"' . GENERATE_ESCAPE . '00e9"}', true],
        'two whole numbers that are the same float' => ['{"n":9223372036854775808}', '{"n":9223372036854775809}', false],
        'two numbers that are both infinite' => ['{"n":1e400}', '{"n":1e999}', false],
        'an empty object and an empty list' => ['{"x":{}}', '{"x":[]}', false],
        'another spelling of a number' => ['{"n":1.0}', '{"n":1}', false],
        'the members in another order' => ['{"a":1,"b":2}', '{"b":2,"a":1}', false],
    ]);

    it('walks the parts in their order: the calls of the list are the calls of the message, one after the other', function (): void {
        $list = '[{"functionCall":{"id":"fc-1","name":"lookup","args":{"city":"Paris"}}},{"text":"Then "},{"functionCall":{"id":"fc-2","name":"weather","args":{}}},{"text":"this."}]';
        $first = ['fc-1', 'lookup', '{"city":"Paris"}'];
        $second = ['fc-2', 'weather', '{}'];

        expect(ThoughtParts::agreeing($list, 'Then this.', [$first, $second]))->not->toBeNull()
            ->and(ThoughtParts::agreeing($list, 'Then this.', [$second, $first]))->toBeNull()
            ->and(ThoughtParts::agreeing($list, 'this.Then ', [$first, $second]))->toBeNull()
            ->and(ThoughtParts::agreeing($list, 'Then this.', [$first]))->toBeNull()
            ->and(ThoughtParts::agreeing($list, 'Then this.', [$first, $second, $second]))->toBeNull();
    });
});

describe('the parts of an answer with a tool call and no signature', function (): void {
    it('are kept too, and go back as they came: the ids of the server unchanged, also ids that look like the adapter\'s, and no skip value', function (bool $asStream): void {
        $parts = [GenerateContent::text('Let me look.'), GenerateContent::call(['city' => 'Paris'], 'call-1'), GenerateContent::call(['city' => 'Rome'], 'call-2')];
        $transport = new FakeTransport();
        $gateway = GenerateContent::gateway($transport);
        $request = GenerateContent::request()->withTools([GenerateContent::lookup()]);
        if ($asStream) {
            $transport->willStream(200, [], GenerateContent::chunk([$parts[0]]), GenerateContent::chunk([$parts[1]]), GenerateContent::chunk([$parts[2]], 'STOP'));
            $answer = Talk::finishedIn(Talk::events($gateway->stream($request)))->answer;
        } else {
            $transport->willReturn(GenerateContent::answered($parts));
            $answer = $gateway->complete($request);
        }
        $transport->willReturn(GenerateContent::answered());
        $gateway->complete($request->withMessages([...$request->messages, $answer->toMessage(), Message::toolResult('call-2', 'lookup', 'two'), Message::toolResult('call-1', 'lookup', 'one')]));
        $sent = $transport->requests()[1]->body;

        expect(array_map(fn(ToolCall $call): string => $call->id, $answer->toolCalls))->toBe(['call-1', 'call-2'])
            ->and($answer->opaque)->not->toBeNull()
            ->and($sent)->toContain('{"role":"model","parts":[{"text":"Let me look."},{"functionCall":{"id":"call-1","name":"lookup","args":{"city":"Paris"}}},{"functionCall":{"id":"call-2","name":"lookup","args":{"city":"Rome"}}}]}')
            ->and($sent)->toContain('{"role":"user","parts":[{"functionResponse":{"id":"call-1","name":"lookup","response":{"output":"one"}}},{"functionResponse":{"id":"call-2","name":"lookup","response":{"output":"two"}}}]}')
            ->and($sent)->not->toContain('thoughtSignature');
    })->with(['a whole answer' => [false], 'a stream' => [true]]);

    it('sends the skip value, and no id the adapter made, with a message that was changed or built by hand', function (callable $alter): void {
        $body = GenerateContent::json(GenerateContent::body([GenerateContent::call(['city' => 'Paris'], null), GenerateContent::call(['city' => 'Rome'], 'fc-example02')]));
        [$answer, $sent] = generateRounds($body, $alter);

        expect(array_map(fn(ToolCall $call): string => $call->id, $answer->toolCalls))->toBe(['call-0', 'fc-example02'])
            ->and($sent)->toContain('{"functionCall":{"name":"lookup","args":{"city":"Paris"}},"thoughtSignature":"' . GenerateContent::NO_SIGNATURE . '"},{"functionCall":{"id":"fc-example02","name":"lookup","args":{"city":"Rome"}}}')
            ->and($sent)->toContain('{"functionResponse":{"name":"lookup","response":{"output":"ok"}}},{"functionResponse":{"id":"fc-example02","name":"lookup","response":{"output":"ok"}}}')
            ->and($sent)->not->toContain('call-0');
    })->with([
        'built by hand' => [fn(Message $message): Message => Message::assistant('', ...$message->toolCalls)],
        'a text added' => [fn(Message $message): Message => new Message(Role::Assistant, [...$message->parts, new OrganiseYou\LlmGateway\Contract\TextPart('More.')], $message->toolCalls)],
        'the part changed' => [fn(Message $message): Message => generateWithChangedPart($message, fn(string $data): string => str_replace('Rome', 'Oslo', $data))],
    ]);

    it('keeps the two kinds of id apart in one answer: a call without an id beside a call the server named as the adapter would', function (): void {
        [$answer, $sent] = generateRounds(GenerateContent::json(GenerateContent::body([GenerateContent::call(['city' => 'Paris'], null), GenerateContent::call(['city' => 'Rome'], 'call-0')])));

        expect(array_map(fn(ToolCall $call): string => $call->id, $answer->toolCalls))->toBe(['call-0-2', 'call-0'])
            ->and($sent)->toContain('{"role":"model","parts":[{"functionCall":{"name":"lookup","args":{"city":"Paris"}}},{"functionCall":{"id":"call-0","name":"lookup","args":{"city":"Rome"}}}]}')
            ->and($sent)->toContain('{"role":"user","parts":[{"functionResponse":{"name":"lookup","response":{"output":"ok"}}},{"functionResponse":{"id":"call-0","name":"lookup","response":{"output":"ok"}}}]}')
            ->and($sent)->not->toContain('call-0-2')
            ->and($sent)->not->toContain('thoughtSignature');
    });

    it('are not kept for a call whose arguments could not be read: that message goes back built from its neutral fields', function (): void {
        [$answer, $sent] = generateRounds(GenerateContent::json(GenerateContent::body([GenerateContent::call('{"city": "Par')])));

        expect($answer->opaque)->toBeNull()
            ->and($sent)->toContain('{"functionCall":{"id":"fc-example01","name":"lookup","args":{}},"thoughtSignature":"' . GenerateContent::NO_SIGNATURE . '"}');
    });
});
