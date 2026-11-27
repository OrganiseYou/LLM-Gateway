<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Anthropic\ContentBlocks;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\TextPart;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\MessagesFixtures;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// Thinking in the Messages adapter (docs/design/adapters.md, section 5.1): the list of content blocks of an answer
// that holds thinking is kept in the opaque part, as the JSON text the server wrote, and goes back as the content
// of the assistant message it came with: only then, and only this adapter's own.

/**
 * The answer to a body whose `content` is this JSON text, as the server wrote it.
 */
function messagesAnswerWithContent(string $content, string $stop = 'tool_use'): Answer
{
    return Messages::answerTo(new HttpResponse(200, [], '{"id":"msg_example01","type":"message","role":"assistant","model":"example-messages-1","content":' . $content . ',"stop_reason":"' . $stop . '","usage":{"input_tokens":3,"output_tokens":2}}'));
}

/**
 * The list of blocks an answer keeps in its opaque part, as the JSON text it holds, or null when it keeps none.
 */
function messagesKeptWith(Answer $answer): ?string
{
    if ($answer->opaque === null) {
        return null;
    }
    $envelope = json_decode($answer->opaque->data, true);

    return $envelope['c']['content'] ?? null;
}

/**
 * The body the adapter sends in the round after this assistant message, as it was written, and the content of the
 * assistant message in it, decoded.
 *
 * @return array{string, mixed}
 */
function messagesSentBack(Message $assistant): array
{
    $messages = [Message::user('Where to?'), $assistant];
    foreach ($assistant->toolCalls as $toolCall) {
        $messages[] = Message::toolResult($toolCall->id, $toolCall->name, '{"degrees":18}');
    }
    if ($assistant->toolCalls === []) {
        $messages[] = Message::user('And then?');
    }
    $transport = (new FakeTransport())->willReturn(Messages::answered());
    Messages::gateway($transport)->complete(new Request(Messages::MODEL, $messages, 30.0, [Messages::lookup()]));

    return [$transport->requests()[0]->body, Messages::sent($transport)['messages'][1]['content']];
}

/**
 * An opaque part as the adapter base seals it for a mark, with a content of the test's choice: what someone who can
 * write where the caller keeps its messages could make.
 *
 * @param list<ToolCall> $toolCalls
 * @param array<string, mixed> $content
 */
function messagesForgedPart(string $text, array $toolCalls, array $content, string $mark = 'a2'): OpaquePart
{
    $hash = hash_init('sha256');
    hash_update($hash, strlen($text) . ':' . $text);
    foreach ($toolCalls as $toolCall) {
        foreach ([$toolCall->id, $toolCall->name, $toolCall->rawArguments] as $field) {
            hash_update($hash, strlen($field) . ':' . $field);
        }
    }

    return new OpaquePart('{"f":"' . $mark . '","m":"' . hash_final($hash) . '","c":' . json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . '}');
}

/**
 * What a call gives, and how far the peak of memory rose while it ran. What the call is given is built before.
 *
 * @template T
 * @param callable(): T $run
 * @return array{T, int}
 */
function messagesPeakOf(callable $run): array
{
    memory_reset_peak_usage();
    $before = memory_get_peak_usage();
    $result = $run();

    return [$result, memory_get_peak_usage() - $before];
}

const THINKING_BLOCK = '{"type":"thinking","thinking":"One city, so the lookup tool.","signature":"c2lnLWV4YW1wbGU="}';

const PARIS_BLOCK = '{"type":"tool_use","id":"toolu_example01","name":"lookup","input":{"city":"Paris"}}';

describe('the opaque part of an answer with thinking', function (): void {
    it('holds the whole list of content blocks as JSON text in one string, bound to this adapter and to the answer', function (): void {
        $answer = messagesAnswerWithContent('[' . THINKING_BLOCK . ',{"type":"text","text":"One moment."},' . PARIS_BLOCK . ']');

        expect($answer->opaque)->not->toBeNull();
        $envelope = json_decode((string) $answer->opaque?->data, true);

        expect(array_keys($envelope))->toBe(['f', 'm', 'c'])
            ->and($envelope['f'])->toBe('a2')
            ->and($envelope['m'])->toMatch('/^[0-9a-f]{64}$/')
            ->and($envelope['c'])->toBe(['content' => '[' . THINKING_BLOCK . ',{"type":"text","text":"One moment."},' . PARIS_BLOCK . ']'])
            // The thinking is in neither the text nor a tool call of the answer.
            ->and($answer->text)->toBe('One moment.')
            ->and($answer->toolCalls[0]->rawArguments)->toBe('{"city":"Paris"}');
    });

    it('keeps the text of the server, not decoded values: an empty object, a number and its spelling stay as they came', function (): void {
        $content = "[\n  {\"type\": \"thinking\", \"thinking\": \"Hm.\", \"signature\": \"c2ln\"},\n"
            . '  {"type": "redacted_thinking", "data": "cmVk"},' . "\n"
            . '  {"type": "tool_use", "id": "toolu_example01", "name": "plan", "input": {"empty": {}, "n": 1.0, "big": 12345678901234567890123, "e": 1e2, "list": []}},' . "\n"
            . '  {"type": "tool_use", "id": "toolu_example02", "name": "now", "input": {}}' . "\n]";
        $answer = messagesAnswerWithContent($content);

        $kept = '[{"type":"thinking","thinking":"Hm.","signature":"c2ln"},'
            . '{"type":"redacted_thinking","data":"cmVk"},'
            . '{"type":"tool_use","id":"toolu_example01","name":"plan","input":{"empty":{},"n":1.0,"big":12345678901234567890123,"e":1e2,"list":[]}},'
            . '{"type":"tool_use","id":"toolu_example02","name":"now","input":{}}]';

        expect(messagesKeptWith($answer))->toBe($kept);

        // And that text is what goes back, byte for byte.
        [$body] = messagesSentBack($answer->toMessage());
        expect($body)->toContain('{"role":"assistant","content":' . $kept . '}');
    });

    it('writes every string of the list in one spelling, whatever escapes the server used', function (): void {
        $slash = "\x5c";
        $answer = messagesAnswerWithContent('[{"type":"thinking","thinking":"caf' . $slash . 'u00e9 a' . $slash . '/b","signature":"c2ln"},{"type":"text","text":"Hello."}]', 'end_turn');

        expect(messagesKeptWith($answer))->toBe('[{"type":"thinking","thinking":"café a/b","signature":"c2ln"},{"type":"text","text":"Hello."}]');
    });

    it('is made for a redacted thinking block alone, and for thinking beside plain text', function (string $content, string $stop): void {
        $answer = messagesAnswerWithContent($content, $stop);

        expect(messagesKeptWith($answer))->toBe($content);
    })->with([
        'a redacted block and a tool call' => ['[{"type":"redacted_thinking","data":"cmVkYWN0ZWQ="},' . PARIS_BLOCK . ']', 'tool_use'],
        'thinking and text' => ['[' . THINKING_BLOCK . ',{"type":"text","text":"Hello."}]', 'end_turn'],
        'thinking between two texts' => ['[{"type":"text","text":"Hel"},' . THINKING_BLOCK . ',{"type":"text","text":"lo."}]', 'end_turn'],
    ]);

    it('is not made for an answer of text and tool calls alone', function (string $content): void {
        expect(messagesAnswerWithContent($content)->opaque)->toBeNull();
    })->with([
        'text' => ['[{"type":"text","text":"Hello."}]'],
        'a tool call' => ['[' . PARIS_BLOCK . ']'],
        'text and a tool call' => ['[{"type":"text","text":"One moment."},' . PARIS_BLOCK . ']'],
    ]);

    it('is not made for a content that holds something this adapter does not keep, and the answer is read all the same', function (string $content): void {
        $answer = messagesAnswerWithContent($content);

        expect($answer->opaque)->toBeNull()
            ->and($answer->toolCalls)->toHaveCount(1)
            ->and($answer->toolCalls[0]->arguments['city'] ?? null)->toBe('Paris');
    })->with([
        'a block of a kind no page names for text chat' => ['[' . THINKING_BLOCK . ',{"type":"server_tool_use","id":"srvtoolu_example01","name":"search","input":{}},' . PARIS_BLOCK . ']'],
        'a block without a type' => ['[' . THINKING_BLOCK . ',{"text":"x"},' . PARIS_BLOCK . ']'],
        'something that is no block' => ['[' . THINKING_BLOCK . ',"a string",' . PARIS_BLOCK . ']'],
        'a type that is no string' => ['[' . THINKING_BLOCK . ',{"type":7},' . PARIS_BLOCK . ']'],
        'a thinking block with a member no page names' => ['[{"type":"thinking","thinking":"Hm.","signature":"c2ln","cache_control":{"type":"ephemeral"}},' . PARIS_BLOCK . ']'],
        'a text block with a member no page names' => ['[' . THINKING_BLOCK . ',{"type":"text","text":"","citations":null},' . PARIS_BLOCK . ']'],
        'a tool call with a member no page names' => ['[' . THINKING_BLOCK . ',{"type":"tool_use","id":"toolu_example01","name":"lookup","input":{"city":"Paris"},"caller":{"type":"direct"}}]'],
        'a thinking block without its signature' => ['[{"type":"thinking","thinking":"Hm."},' . PARIS_BLOCK . ']'],
        'a thinking block whose thinking is no string' => ['[{"type":"thinking","thinking":["Hm."],"signature":"c2ln"},' . PARIS_BLOCK . ']'],
        'a signature that is null' => ['[{"type":"thinking","thinking":"Hm.","signature":null},' . PARIS_BLOCK . ']'],
        'redacted thinking whose data is an object' => ['[{"type":"redacted_thinking","data":{"x":"cmVk"}},' . PARIS_BLOCK . ']'],
        'a text block whose text is a number' => ['[' . THINKING_BLOCK . ',{"type":"text","text":7},' . PARIS_BLOCK . ']'],
        'a name twice in a block' => ['[{"type":"redacted_thinking","data":"cmVk","type":"thinking","thinking":"Hm.","signature":"c2ln"},' . PARIS_BLOCK . ']'],
        'a name twice deep inside the input of a call' => ['[' . THINKING_BLOCK . ',{"type":"tool_use","id":"toolu_example01","name":"lookup","input":{"city":"Paris","opts":[{"a":1,"a":2}]}}]'],
    ]);

    it('is not made for thinking beside a tool call whose input is no object, in a whole answer as in a stream', function (string $input): void {
        $whole = messagesAnswerWithContent('[' . THINKING_BLOCK . ',{"type":"tool_use","id":"toolu_example01","name":"lookup","input":' . $input . '}]');
        $streamed = Talk::finishedIn(Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, Messages::thinking()),
            Messages::blockStopped(0),
            Messages::blockStarted(1, Messages::call([])),
            // In a stream the input is the text of its pieces: the string of the whole answer, or the list as it is.
            Messages::delta(1, 'input_json_delta', str_starts_with($input, '"') ? (string) json_decode($input) : $input),
            Messages::blockStopped(1),
            ...Messages::stopped('tool_use'),
        ]))->answer;

        expect($whole->opaque)->toBeNull()
            ->and($streamed->opaque)->toBeNull()
            ->and($whole->toolCalls)->toHaveCount(1)
            ->and($streamed->toolCalls)->toHaveCount(1)
            ->and($whole->toolCalls[0]->isReadable())->toBeFalse()
            ->and($streamed->toolCalls[0]->isReadable())->toBeFalse();
    })->with([
        'a string that holds a cut-off object' => ['"{\"city\": \"Par"'],
        'a list' => ['["Paris"]'],
    ]);

    it('is the same for a stream as for the whole answer', function (): void {
        $fixtures = new MessagesFixtures();
        $whole = $fixtures->answer(ContractFixture::ToolCallWithOpaqueData);
        $chunks = $fixtures->stream(ContractFixture::ToolCallWithOpaqueData);
        assert($whole instanceof HttpResponse && is_array($chunks));

        $request = new Request(Messages::MODEL, [Message::user('Where to?')], 30.0, [Messages::lookup()]);
        $answer = Messages::answerTo($whole, request: $request);
        $streamed = Talk::finishedIn(Messages::eventsOf($chunks, request: $request))->answer;

        expect($answer->opaque)->not->toBeNull()
            ->and($streamed->opaque?->data)->toBe($answer->opaque?->data)
            ->and(messagesKeptWith($streamed))->toBe(
                '[{"type":"thinking","thinking":"The user asks about one city, so the lookup tool is the way: opaque-tracer.","signature":"c2lnLWV4YW1wbGUtMDE="},'
                . '{"type":"tool_use","id":"toolu_example01","name":"lookup","input":{"city":"Paris"}}]',
            );
    });

    it('joins the pieces of a stream into the blocks a whole answer holds: text, thinking, its signature and the input of a call', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, ['type' => 'thinking', 'thinking' => '', 'signature' => '']),
            Messages::delta(0, 'thinking_delta', 'One city, '),
            Messages::delta(0, 'thinking_delta', 'so the "lookup" tool.'),
            Messages::delta(0, 'signature_delta', 'c2lnLWV4'),
            Messages::delta(0, 'signature_delta', 'YW1wbGU='),
            Messages::blockStopped(0),
            Messages::blockStarted(1, ['type' => 'text', 'text' => 'One ']),
            Messages::delta(1, 'text_delta', 'moment.'),
            Messages::blockStopped(1),
            Messages::blockStarted(2, ['type' => 'tool_use', 'id' => 'toolu_example01', 'name' => 'lookup', 'input' => (object) []]),
            Messages::delta(2, 'input_json_delta', '{"city": "Paris",'),
            Messages::delta(2, 'input_json_delta', ' "opts": {}, "n": 1.0}'),
            Messages::blockStopped(2),
            Messages::blockStarted(3, ['type' => 'tool_use', 'id' => 'toolu_example02', 'name' => 'now', 'input' => (object) []]),
            Messages::blockStopped(3),
            Messages::blockStarted(4, ['type' => 'redacted_thinking', 'data' => 'cmVk']),
            Messages::blockStopped(4),
            ...Messages::stopped('tool_use'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(messagesKeptWith($answer))->toBe(
            '[{"type":"thinking","thinking":"One city, so the \"lookup\" tool.","signature":"c2lnLWV4YW1wbGU="},'
            . '{"type":"text","text":"One moment."},'
            . '{"type":"tool_use","id":"toolu_example01","name":"lookup","input":{"city":"Paris","opts":{},"n":1.0}},'
            . '{"type":"tool_use","id":"toolu_example02","name":"now","input":{}},'
            . '{"type":"redacted_thinking","data":"cmVk"}]',
        )
            ->and($answer->text)->toBe('One moment.')
            ->and($answer->toolCalls)->toHaveCount(2);

        // No event before the last holds the thinking.
        foreach (array_slice($events, 0, -1) as $event) {
            expect(str_contains(serialize($event), 'One city'))->toBeFalse();
        }
    });

    it('keeps nothing of a stream whose tool input is no JSON object, or that holds a block it does not keep', function (array $chunks): void {
        $answer = Talk::finishedIn(Messages::eventsOf([Messages::started(), Messages::blockStarted(0, Messages::thinking()), Messages::blockStopped(0), ...$chunks, ...Messages::stopped('tool_use')]))->answer;

        expect($answer->opaque)->toBeNull()
            ->and($answer->toolCalls)->toHaveCount(1);
    })->with([
        'a cut-off input' => [[Messages::blockStarted(1, Messages::call([])), Messages::delta(1, 'input_json_delta', '{"city": "Par'), Messages::blockStopped(1)]],
        'an input that is a list' => [[Messages::blockStarted(1, Messages::call([])), Messages::delta(1, 'input_json_delta', '["Paris"]'), Messages::blockStopped(1)]],
        'a block of another kind' => [[Messages::blockStarted(1, ['type' => 'server_tool_use', 'id' => 'srvtoolu_example01', 'name' => 'search']), Messages::blockStopped(1), Messages::blockStarted(2, Messages::call(['city' => 'Paris'])), Messages::blockStopped(2)]],
        'a block that starts with a member no page names' => [[Messages::blockStarted(1, [...Messages::call(['city' => 'Paris']), 'caller' => 'direct']), Messages::blockStopped(1)]],
        'a block that starts with a name twice' => [['event: content_block_start' . "\n" . 'data: {"type":"content_block_start","index":1,"content_block":{"type":"text","type":"tool_use","id":"toolu_example01","name":"lookup","input":{}}}' . "\n\n", Messages::blockStopped(1)]],
    ]);

    it('keeps nothing of a stream whose thinking block never got a signature', function (): void {
        $answer = Talk::finishedIn(Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, ['type' => 'thinking', 'thinking' => '']),
            Messages::delta(0, 'thinking_delta', 'Hm.'),
            Messages::blockStopped(0),
            Messages::blockStarted(1, Messages::text('Hello.')),
            Messages::blockStopped(1),
            ...Messages::stopped(),
        ]))->answer;

        expect($answer->opaque)->toBeNull()
            ->and($answer->text)->toBe('Hello.');
    });

    it('is not made for a stream without thinking', function (): void {
        expect(Talk::finishedIn(Messages::eventsOf(Messages::saying()))->answer->opaque)->toBeNull();
    });
});

describe('the round after an answer with thinking', function (): void {
    it('sends the list back as the content of the message it came with, and the tool results after it', function (): void {
        $answer = messagesAnswerWithContent('[' . THINKING_BLOCK . ',' . PARIS_BLOCK . ']');
        [$body, $content] = messagesSentBack($answer->toMessage());
        $sent = json_decode($body, true);

        expect($content)->toBe([
            ['type' => 'thinking', 'thinking' => 'One city, so the lookup tool.', 'signature' => 'c2lnLWV4YW1wbGU='],
            ['type' => 'tool_use', 'id' => 'toolu_example01', 'name' => 'lookup', 'input' => ['city' => 'Paris']],
        ])
            ->and($sent['messages'][2])->toBe(['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_example01', 'content' => '{"degrees":18}']]])
            // The envelope of the part is nowhere in the request.
            ->and(str_contains($body, '"f":"a2"'))->toBeFalse();
    });

    it('sends each of several answers its own list', function (): void {
        $first = messagesAnswerWithContent('[{"type":"thinking","thinking":"First thought.","signature":"c2lnMQ=="},' . PARIS_BLOCK . ']');
        $second = messagesAnswerWithContent('[{"type":"thinking","thinking":"Second thought.","signature":"c2lnMg=="},{"type":"tool_use","id":"toolu_example02","name":"lookup","input":{"city":"Rome"}}]');

        $transport = (new FakeTransport())->willReturn(Messages::answered());
        Messages::gateway($transport)->complete(Messages::request(
            Message::user('Where to?'),
            $first->toMessage(),
            Message::toolResult('toolu_example01', 'lookup', '18'),
            $second->toMessage(),
            Message::toolResult('toolu_example02', 'lookup', '22'),
        ));
        $sent = Messages::sent($transport)['messages'];

        expect($sent[1]['content'][0]['thinking'])->toBe('First thought.')
            ->and($sent[3]['content'][0]['thinking'])->toBe('Second thought.')
            ->and($sent[3]['content'][1]['input'])->toBe(['city' => 'Rome']);
    });

    it('sends nothing of the part when the message was changed since, and still sends the message', function (callable $change, array $built): void {
        $answer = messagesAnswerWithContent('[' . THINKING_BLOCK . ',{"type":"text","text":"One moment."},' . PARIS_BLOCK . ']');
        $message = $answer->toMessage();
        $opaque = array_values(array_filter($message->parts, fn($part): bool => $part instanceof OpaquePart));

        [$body, $content] = messagesSentBack($change($message, $opaque[0]));

        expect($content)->toBe($built)
            ->and(str_contains($body, 'thinking'))->toBeFalse()
            ->and(str_contains($body, 'c2lnLWV4YW1wbGU='))->toBeFalse();
    })->with([
        'another text' => [
            fn(Message $message, OpaquePart $part): Message => new Message(Role::Assistant, [$part, new TextPart('Hold on.')], $message->toolCalls),
            [['type' => 'text', 'text' => 'Hold on.'], ['type' => 'tool_use', 'id' => 'toolu_example01', 'name' => 'lookup', 'input' => ['city' => 'Paris']]],
        ],
        'other arguments of the tool call' => [
            fn(Message $message, OpaquePart $part): Message => new Message(Role::Assistant, [$part, new TextPart('One moment.')], [ToolCall::decoded('toolu_example01', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}')]),
            [['type' => 'text', 'text' => 'One moment.'], ['type' => 'tool_use', 'id' => 'toolu_example01', 'name' => 'lookup', 'input' => ['city' => 'Rome']]],
        ],
        'a tool call less' => [
            fn(Message $message, OpaquePart $part): Message => new Message(Role::Assistant, [$part, new TextPart('One moment.')]),
            [['type' => 'text', 'text' => 'One moment.']],
        ],
        'the message built by hand, without the part' => [
            fn(Message $message, OpaquePart $part): Message => Message::assistant($message->text(), ...$message->toolCalls),
            [['type' => 'text', 'text' => 'One moment.'], ['type' => 'tool_use', 'id' => 'toolu_example01', 'name' => 'lookup', 'input' => ['city' => 'Paris']]],
        ],
    ]);

    it('sends nothing of a part that is not this adapter\'s own', function (callable $part): void {
        $call = ToolCall::decoded('toolu_example01', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $list = '[' . THINKING_BLOCK . ',' . PARIS_BLOCK . ']';

        [$body, $content] = messagesSentBack(new Message(Role::Assistant, [$part($call, $list)], [$call]));

        expect($content)->toBe([['type' => 'tool_use', 'id' => 'toolu_example01', 'name' => 'lookup', 'input' => ['city' => 'Paris']]])
            ->and(str_contains($body, 'thinking'))->toBeFalse();
    })->with([
        'the mark of another adapter of the library' => [fn(ToolCall $call, string $list): OpaquePart => messagesForgedPart('', [$call], ['content' => $list], 'a1')],
        'the mark of an adapter of a host' => [fn(ToolCall $call, string $list): OpaquePart => messagesForgedPart('', [$call], ['content' => $list], 'x7')],
        'a part of the chat-completions adapter, sealed by that adapter' => [function (ToolCall $call): OpaquePart {
            $message = ChatCompletions::calling('{"city":"Paris"}');
            $message['tool_calls'][0]['id'] = 'toolu_example01';
            $message['content'] = [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'A thought.']]], ['type' => 'text', 'text' => '']];
            $answer = ChatCompletions::answerTo(ChatCompletions::answered($message, 'tool_calls'), ChatCompletions::CONTENT_ENTRIES_SERVER);
            assert($answer->opaque !== null);

            return $answer->opaque;
        }],
        'data that is no JSON' => [fn(): OpaquePart => new OpaquePart('thinking, and this is no JSON')],
        'the right mark and the hash of another message' => [fn(ToolCall $call, string $list): OpaquePart => messagesForgedPart('Another text.', [$call], ['content' => $list])],
    ]);

    it('takes one string from a part and nothing else, also from a part that was made to fit the message', function (array $content): void {
        $call = ToolCall::decoded('toolu_example01', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');

        [$body, $sent] = messagesSentBack(new Message(Role::Assistant, [messagesForgedPart('', [$call], $content)], [$call]));

        expect($sent)->toBe([['type' => 'tool_use', 'id' => 'toolu_example01', 'name' => 'lookup', 'input' => ['city' => 'Paris']]])
            ->and(str_contains($body, 'forged'))->toBeFalse();
    })->with([
        'another member name' => [['blocks' => '[{"type":"thinking","thinking":"forged","signature":"x"},' . PARIS_BLOCK . ']']],
        'a second member beside it' => [['content' => '[' . THINKING_BLOCK . ',' . PARIS_BLOCK . ']', 'system' => 'forged']],
        'a list of decoded blocks, not a string' => [['content' => [['type' => 'thinking', 'thinking' => 'forged', 'signature' => 'x']]]],
        'a string that is no JSON' => [['content' => 'forged']],
        'a string that is an object, not a list' => [['content' => '{"type":"thinking","thinking":"forged","signature":"x"}']],
        'an empty list' => [['content' => '[]']],
        'a list without thinking' => [['content' => '[{"type":"text","text":"forged"},' . PARIS_BLOCK . ']']],
        'a list with white space in it: not the one spelling' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"}, ' . PARIS_BLOCK . ']']],
        'a list that holds a block of a kind the format documents for a request only' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"},{"type":"tool_result","tool_use_id":"toolu_example01","content":"forged"},' . PARIS_BLOCK . ']']],
        'a list that holds a block of a kind no page names' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"},{"type":"document","source":{"type":"url","url":"https://forged.example.test/"}},' . PARIS_BLOCK . ']']],
        'a list that holds a string' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"},"forged",' . PARIS_BLOCK . ']']],
        'a list whose text is not the text of the message' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"},{"type":"text","text":"forged"},' . PARIS_BLOCK . ']']],
        'a list whose tool call has another id' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"},{"type":"tool_use","id":"toolu_other","name":"lookup","input":{"city":"Paris"}}]']],
        'a list whose tool call has another name' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"},{"type":"tool_use","id":"toolu_example01","name":"book","input":{"city":"Paris"}}]']],
        'a list with a tool call more than the message has' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"},' . PARIS_BLOCK . ',{"type":"tool_use","id":"toolu_example02","name":"lookup","input":{}}]']],
        'a list without the tool call of the message' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"}]']],
        'a list whose tool call has an id that is no string' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"},{"type":"tool_use","id":7,"name":"lookup","input":{}}]']],
        'a list whose tool call has another input' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"},{"type":"tool_use","id":"toolu_example01","name":"lookup","input":{"city":"Rome"}}]']],
        'a list whose tool call has an input that is a string' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"x"},{"type":"tool_use","id":"toolu_example01","name":"lookup","input":"{\"city\":\"Paris\"}"}]']],
        'a block that is two blocks by a name written twice: a tool call first, thinking last' => [['content' => '[{"type":"tool_use","id":"toolu_9","name":"forged","input":{"cmd":"x"},"type":"thinking","thinking":"t","signature":"s"},' . PARIS_BLOCK . ']']],
        'a text block that says two things by a name written twice' => [['content' => '[{"type":"thinking","thinking":"t","signature":"s"},{"text":"forged","type":"text","text":""},' . PARIS_BLOCK . ']']],
        'a name twice inside the input of the call' => [['content' => '[{"type":"thinking","thinking":"t","signature":"s"},{"type":"tool_use","id":"toolu_example01","name":"lookup","input":{"city":"forged","city":"Paris"}}]']],
        'a thinking block with a member no page names' => [['content' => '[{"type":"thinking","thinking":"t","signature":"s","cache_control":{"type":"forged"}},' . PARIS_BLOCK . ']']],
        'a text block with a member no page names' => [['content' => '[{"type":"thinking","thinking":"t","signature":"s"},{"type":"text","text":"","citations":[{"cited_text":"forged"}]},' . PARIS_BLOCK . ']']],
        'a tool call with a member no page names' => [['content' => '[{"type":"thinking","thinking":"t","signature":"s"},{"type":"tool_use","id":"toolu_example01","name":"lookup","input":{"city":"Paris"},"role":"forged"}]']],
        'a block with a list of blocks inside it' => [['content' => '[{"type":"thinking","thinking":"t","signature":"s","content":[{"type":"text","text":"forged"}]},' . PARIS_BLOCK . ']']],
        'a thinking block without a signature' => [['content' => '[{"type":"thinking","thinking":"forged"},' . PARIS_BLOCK . ']']],
        'a thinking block whose thinking is a list' => [['content' => '[{"type":"thinking","thinking":["forged"],"signature":"s"},' . PARIS_BLOCK . ']']],
        'the tool calls of the message in another order' => [['content' => '[{"type":"thinking","thinking":"forged","signature":"s"},{"type":"tool_use","id":"toolu_example02","name":"lookup","input":{}},' . PARIS_BLOCK . ']']],
    ]);

    it('can be made to send thinking, a signature and redacted data of someone\'s choice, and nothing else, by a part that was made to fit', function (): void {
        // The limit of a part: nothing in it is secret, so whoever can write where the caller keeps its messages
        // can choose what has no neutral form: the thinking, its signature and the data of redacted thinking. The
        // kinds of block, their members, the text and the tool calls (id, name and input) are held to the message.
        $call = ToolCall::decoded('toolu_example01', 'lookup', ['city' => 'Paris'], '{"city": "Paris"}');
        $list = '[{"type":"thinking","thinking":"made up","signature":"bWFkZQ=="},{"type":"text","text":"One "},{"type":"redacted_thinking","data":"bWFkZSB1cA=="},{"type":"text","text":"moment."},' . PARIS_BLOCK . ']';

        [$body, $content] = messagesSentBack(new Message(Role::Assistant, [messagesForgedPart('One moment.', [$call], ['content' => $list]), new TextPart('One moment.')], [$call]));
        $sent = json_decode($body, true);

        expect($body)->toContain('{"role":"assistant","content":' . $list . '}')
            ->and($content[0]['thinking'])->toBe('made up')
            ->and($content[4]['input'])->toBe(['city' => 'Paris'])
            ->and(array_keys($sent))->toBe(['model', 'max_tokens', 'messages', 'tools'])
            ->and($sent['model'])->toBe(Messages::MODEL)
            ->and($sent['messages'])->toHaveCount(3);
    });
});

describe('the limits of what the Messages adapter keeps', function (): void {
    // The adapter base counts the bytes and the objects and lists of a part before it decodes one (G78 (4)). The
    // list of blocks is sealed as one string, so the base counts one string: the adapter measures the list itself,
    // as text, before anything decodes it or cuts it into its blocks.
    $megabyte = 1_048_576;

    it('has the limits of an opaque part', function () use ($megabyte): void {
        expect(ContentBlocks::LIMIT_BYTES)->toBe($megabyte)
            ->and(ContentBlocks::LIMIT_CONTAINERS)->toBe(10_000);
    });

    it('keeps a list of 10,000 objects and lists, and not one of 10,001', function (): void {
        // The list and the thinking block are two, the tool call and its input two more; every text block is one.
        $list = fn(int $more): string => '[{"type":"thinking","thinking":"Hm.","signature":"c2ln"}' . str_repeat(',{"type":"text","text":""}', $more) . ',' . PARIS_BLOCK . ']';

        expect(ContentBlocks::kept($list(9_996)))->toBe($list(9_996))
            ->and(ContentBlocks::kept($list(9_997)))->toBeNull()
            ->and(messagesAnswerWithContent($list(9_996))->opaque)->not->toBeNull()
            ->and(messagesAnswerWithContent($list(9_997))->opaque)->toBeNull()
            ->and(messagesAnswerWithContent($list(9_997))->toolCalls)->toHaveCount(1);
    });

    it('keeps a list of 1 MiB and not one of a byte more', function () use ($megabyte): void {
        $list = fn(int $bytes): string => '[{"type":"thinking","thinking":"' . str_repeat('a', $bytes - 54) . '","signature":"c2ln"}]';

        expect(strlen($list($megabyte)))->toBe($megabyte)
            ->and(ContentBlocks::kept($list($megabyte)))->not->toBeNull()
            ->and(ContentBlocks::kept($list($megabyte + 1)))->toBeNull()
            // The brackets inside a string are no objects and no lists.
            ->and(ContentBlocks::kept('[{"type":"thinking","thinking":"' . str_repeat('[{', 20_000) . '","signature":"c2ln"}]'))->not->toBeNull();
    });

    it('gives a whole answer whose content holds too many blocks without the part, and does not cut the list up', function () use ($megabyte): void {
        // More than 1 MiB of text blocks beside one thinking block, and the same below the limit of bytes.
        foreach ([45_000, 38_000] as $blocks) {
            $body = '{"content":[' . THINKING_BLOCK . str_repeat(',{"type":"text","text":""}', $blocks) . ',{"type":"text","text":"Hello."}],"stop_reason":"end_turn"}';
            $plain = '{"content":[' . substr(str_repeat(',{"type":"text","text":""}', $blocks), 1) . ',{"type":"text","text":"Hello."}],"stop_reason":"end_turn"}';
            $gateway = Messages::gateway((new FakeTransport())->willReturn(new HttpResponse(200, [], $body)));
            $other = Messages::gateway((new FakeTransport())->willReturn(new HttpResponse(200, [], $plain)));

            [$answer, $taken] = messagesPeakOf(fn(): Answer => $gateway->complete(Messages::request()));
            [, $withoutThinking] = messagesPeakOf(fn(): Answer => $other->complete(Messages::request()));

            expect($answer->opaque)->toBeNull()
                ->and($answer->text)->toBe('Hello.')
                // Reading the answer decodes the body once, with or without thinking in it; keeping adds little.
                ->and($taken - $withoutThinking)->toBeLessThan(4 * $megabyte);
        }
    });

    it('measures the content of a whole answer where it stands, and makes no copy of one that is too long', function () use ($megabyte): void {
        $body = fn(string $block): string => '{"content":[' . $block . ',{"type":"text","text":"Hello."}],"stop_reason":"end_turn"}';
        $long = str_repeat('a', 20 * $megabyte);
        $withThinking = Messages::gateway((new FakeTransport())->willReturn(new HttpResponse(200, [], $body('{"type":"thinking","thinking":"' . $long . '","signature":"c2ln"}'))));
        // The same body with a block the adapter reads past and never keeps: what reading alone takes.
        $without = Messages::gateway((new FakeTransport())->willReturn(new HttpResponse(200, [], $body('{"type":"other_thing","thinking":"' . $long . '","signature":"c2ln"}'))));
        unset($long);

        [$answer, $taken] = messagesPeakOf(fn(): Answer => $withThinking->complete(Messages::request()));
        [, $reading] = messagesPeakOf(fn(): Answer => $without->complete(Messages::request()));

        expect($answer->opaque)->toBeNull()
            ->and($answer->text)->toBe('Hello.')
            ->and($taken - $reading)->toBeLessThan($megabyte);
    });

    it('sends a message whose part was made with too many blocks without the part, and does not decode the list', function () use ($megabyte): void {
        $call = ToolCall::decoded('toolu_example01', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        // About 900,000 bytes with 300,000 objects: under the limit of bytes of a part, which the base checks.
        $message = new Message(Role::Assistant, [messagesForgedPart('', [$call], ['content' => '[' . str_repeat('{},', 299_999) . '{}]'])], [$call]);
        $transport = (new FakeTransport())->willReturn(Messages::answered());
        $gateway = Messages::gateway($transport);
        $request = Messages::request(Message::user('Where to?'), $message, Message::toolResult('toolu_example01', 'lookup', '{"degrees":18}'));

        [, $taken] = messagesPeakOf(fn(): Answer => $gateway->complete($request));

        expect(Messages::sent($transport)['messages'][1]['content'])->toBe([['type' => 'tool_use', 'id' => 'toolu_example01', 'name' => 'lookup', 'input' => ['city' => 'Paris']]])
            ->and(strlen($transport->requests()[0]->body))->toBeLessThan(2000)
            ->and($taken)->toBeLessThan(6 * $megabyte);
    });

    it('gives the answer of a stream whose one block is over the limits without the part', function (string $block) use ($megabyte): void {
        $chunks = [
            Messages::started(),
            'event: content_block_start' . "\n" . 'data: {"type":"content_block_start","index":0,"content_block":' . $block . '}' . "\n\n",
            Messages::blockStopped(0),
            Messages::blockStarted(1, Messages::text('')),
            Messages::delta(1, 'text_delta', 'Hello.'),
            Messages::blockStopped(1),
            ...Messages::stopped(),
        ];
        $gateway = Messages::gateway((new FakeTransport())->willStream(200, [], ...$chunks));

        [$events, $taken] = messagesPeakOf(fn(): array => Talk::events($gateway->stream(Messages::request())));
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->opaque)->toBeNull()
            ->and($answer->text)->toBe('Hello.')
            // The event is decoded once to be read; the block is not cut into its members beside that.
            ->and($taken)->toBeLessThan(40 * $megabyte);
    })->with([
        'too many objects and lists' => ['{"type":"redacted_thinking","data":"cmVk","more":[' . str_repeat('{},', 20_000) . '{}]}'],
        'too many bytes' => ['{"type":"thinking","thinking":"' . str_repeat('a', 1_100_000) . '","signature":"c2ln"}'],
    ]);

    it('stops keeping the pieces of a stream when their bytes pass 1 MiB, and still gives the text', function (): void {
        $streamed = function (int $pieces): Answer {
            $chunks = [Messages::started(), Messages::blockStarted(0, Messages::thinking('', ''))];
            for ($at = 0; $at < $pieces; $at++) {
                $chunks[] = Messages::delta(0, 'thinking_delta', str_repeat('a', 100_000));
            }

            return Talk::finishedIn(Messages::eventsOf([...$chunks, Messages::blockStopped(0), Messages::blockStarted(1, Messages::text('Hello.')), Messages::blockStopped(1), ...Messages::stopped()]))->answer;
        };

        expect($streamed(10)->opaque)->not->toBeNull()
            ->and($streamed(11)->opaque)->toBeNull()
            ->and($streamed(11)->text)->toBe('Hello.');
    });

    it('stops keeping the pieces of a stream of many blocks when they pass the limit, and adds little to the memory of the call', function () use ($megabyte): void {
        // 30,000 text blocks of one letter each after a thinking block: more objects than a part may hold.
        $chunks = [Messages::started(), Messages::blockStarted(0, Messages::thinking()), Messages::blockStopped(0)];
        $plain = [Messages::started()];
        for ($at = 1; $at <= 30_000; $at++) {
            $chunks[] = $plain[] = Messages::blockStarted($at, Messages::text('a'));
        }
        $chunks = [...$chunks, ...Messages::stopped()];
        $plain = [...$plain, ...Messages::stopped()];

        $withThinking = Messages::gateway((new FakeTransport())->willStream(200, [], ...$chunks));
        $without = Messages::gateway((new FakeTransport())->willStream(200, [], ...$plain));
        // Only the last event is kept: the events themselves are what a caller reads and lets go.
        $last = function (iterable $stream): ?StreamEvent {
            $event = null;
            foreach ($stream as $event) {
            }

            return $event;
        };

        [$finished, $taken] = messagesPeakOf(fn(): ?StreamEvent => $last($withThinking->stream(Messages::request())));
        [, $withoutThinking] = messagesPeakOf(fn(): ?StreamEvent => $last($without->stream(Messages::request())));

        expect($finished)->toBeInstanceOf(StreamFinished::class);
        assert($finished instanceof StreamFinished);
        expect($finished->answer->opaque)->toBeNull()
            ->and($finished->answer->text)->toBe(str_repeat('a', 30_000))
            ->and($taken - $withoutThinking)->toBeLessThan(4 * $megabyte);
    });

    it('counts what the pieces are joined to, not the pieces: long thinking in many small pieces is kept', function (): void {
        $chunks = [Messages::started(), Messages::blockStarted(0, Messages::thinking('', ''))];
        for ($at = 0; $at < 12_000; $at++) {
            $chunks[] = Messages::delta(0, 'thinking_delta', 'think ');
        }
        $chunks = [...$chunks, Messages::delta(0, 'signature_delta', 'c2ln'), Messages::blockStopped(0), Messages::blockStarted(1, Messages::text(''))];
        for ($at = 0; $at < 12_000; $at++) {
            $chunks[] = Messages::delta(1, 'text_delta', 'word ');
        }

        $answer = Talk::finishedIn(Messages::eventsOf([...$chunks, Messages::blockStopped(1), ...Messages::stopped()]))->answer;

        expect(messagesKeptWith($answer))->toBe('[{"type":"thinking","thinking":"' . str_repeat('think ', 12_000) . '","signature":"c2ln"},{"type":"text","text":"' . str_repeat('word ', 12_000) . '"}]');
    });

    it('does not go on keeping after a limit was passed: a later small block is not kept either', function (): void {
        $answer = Talk::finishedIn(Messages::eventsOf([
            Messages::started(),
            'event: content_block_start' . "\n" . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":"","signature":"","more":[' . str_repeat('{},', 10_000) . '{}]}}' . "\n\n",
            Messages::blockStopped(0),
            Messages::blockStarted(1, Messages::thinking()),
            Messages::blockStopped(1),
            Messages::blockStarted(2, Messages::text('Hello.')),
            Messages::blockStopped(2),
            ...Messages::stopped(),
        ]))->answer;

        expect($answer->opaque)->toBeNull()
            ->and($answer->text)->toBe('Hello.');
    });
});

describe('the pieces of a stream as the Messages adapter keeps them', function (): void {
    it('refuses a block that is no object, a block of another kind and an index that started before', function (): void {
        $pieces = ContentBlocks::none();

        expect(ContentBlocks::start($pieces, 0, '"a string"'))->toBeFalse()
            ->and(ContentBlocks::start($pieces, 0, '{"type":"document"}'))->toBeFalse()
            ->and(ContentBlocks::start($pieces, 0, '{"type": "text", "text": "Hel"}'))->toBeTrue()
            ->and(ContentBlocks::start($pieces, 0, '{"type":"text","text":"again"}'))->toBeFalse();
    });

    it('adds a delta only to a block of the kind it fits, and nothing for an index that never started', function (): void {
        $pieces = ContentBlocks::none();
        ContentBlocks::start($pieces, 0, '{"type":"text","text":"Hel"}');
        ContentBlocks::start($pieces, 1, '{"type":"thinking","thinking":"","signature":""}');

        expect(ContentBlocks::add($pieces, 0, 'text_delta', 'lo.'))->toBeTrue()
            ->and(ContentBlocks::add($pieces, 0, 'thinking_delta', 'not here'))->toBeTrue()
            ->and(ContentBlocks::add($pieces, 0, 'input_json_delta', '{"not":"here"}'))->toBeTrue()
            ->and(ContentBlocks::add($pieces, 1, 'text_delta', 'not here'))->toBeTrue()
            ->and(ContentBlocks::add($pieces, 1, 'some_new_delta', 'not here'))->toBeTrue()
            ->and(ContentBlocks::add($pieces, 7, 'text_delta', 'not here'))->toBeTrue()
            ->and(ContentBlocks::add($pieces, 1, 'thinking_delta', 'Hm.'))->toBeTrue()
            ->and(ContentBlocks::written($pieces))->toBe('[{"type":"text","text":"Hello."},{"type":"thinking","thinking":"Hm.","signature":""}]')
            ->and(ContentBlocks::holdThinking($pieces))->toBeTrue();
    });

    it('writes the blocks in the order of their index, whatever order they started in', function (): void {
        $pieces = ContentBlocks::none();
        ContentBlocks::start($pieces, 2, '{"type":"text","text":"b"}');
        ContentBlocks::start($pieces, 0, '{"type":"redacted_thinking","data":"cmVk"}');

        expect(ContentBlocks::written($pieces))->toBe('[{"type":"redacted_thinking","data":"cmVk"},{"type":"text","text":"b"}]');
    });

    it('tells whether a decoded content must go back as it came', function (mixed $content, bool $mustGoBack): void {
        expect(ContentBlocks::mustGoBack($content))->toBe($mustGoBack);
    })->with([
        'thinking and text' => [[['type' => 'thinking'], ['type' => 'text']], true],
        'redacted thinking alone' => [[['type' => 'redacted_thinking']], true],
        'text and a tool call' => [[['type' => 'text'], ['type' => 'tool_use']], false],
        'thinking beside a block of another kind' => [[['type' => 'thinking'], ['type' => 'image']], false],
        'an empty list' => [[], false],
        'an object, not a list' => [['a' => ['type' => 'thinking']], false],
        'a string' => ['thinking', false],
        'null' => [null, false],
    ]);
});
