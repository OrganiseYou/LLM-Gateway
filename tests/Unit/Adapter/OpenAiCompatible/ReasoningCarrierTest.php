<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\TextPart;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// The option reasoning_carrier: message_field (docs/design/adapters.md, section 5.1). A server writes its reasoning
// in a string beside the answer and wants it back with that answer. The adapter keeps it in the opaque part of the
// answer, as JSON text in one string, and sends it back only with the message it came with.

const REASONING_CARRIER = ['reasoning_carrier' => 'message_field'];

/**
 * The answer of a server with a reasoning carrier that calls "lookup" and writes this reasoning beside the call.
 *
 * @param array<string, bool|int|string> $options
 */
function answerWithReasoning(mixed $reasoning, array $options = REASONING_CARRIER, string $field = 'reasoning_content'): Answer
{
    $message = ChatCompletions::calling('{"city":"Paris"}');
    $message['content'] = '';
    $message[$field] = $reasoning;

    return ChatCompletions::answerTo(ChatCompletions::answered($message, 'tool_calls'), $options, new Request(ChatCompletions::MODEL, [Message::user('Where to?')], 30.0, [ChatCompletions::lookup()]));
}

/**
 * The assistant message the adapter sends in the round after this one, on a server with these options.
 *
 * @param array<string, bool|int|string> $options
 * @return array<string, mixed>
 */
function sentBack(Message $assistant, array $options = REASONING_CARRIER): array
{
    $messages = [Message::user('Where to?'), $assistant];
    foreach ($assistant->toolCalls as $toolCall) {
        $messages[] = Message::toolResult($toolCall->id, $toolCall->name, '{"degrees":18}');
    }

    return ChatCompletions::bodyFor(new Request(ChatCompletions::MODEL, $messages, 30.0, [ChatCompletions::lookup()]), $options)['messages'][1];
}

/**
 * An opaque part as the adapter base seals it for the mark of this adapter, with a content of the test's choice:
 * what someone who can write where the caller keeps its messages could make.
 *
 * @param list<ToolCall> $toolCalls
 */
function forgedPart(string $text, array $toolCalls, string $content): OpaquePart
{
    $hash = hash_init('sha256');
    hash_update($hash, strlen($text) . ':' . $text);
    foreach ($toolCalls as $toolCall) {
        foreach ([$toolCall->id, $toolCall->name, $toolCall->rawArguments] as $field) {
            hash_update($hash, strlen($field) . ':' . $field);
        }
    }

    return new OpaquePart('{"f":"a1","m":"' . hash_final($hash) . '","c":' . $content . '}');
}

describe('the opaque part of an answer', function (): void {
    it('holds the reasoning as JSON text in one string, bound to this adapter and to the answer', function (): void {
        $reasoning = 'The user asks about one city, so the lookup tool is the way.';
        $answer = answerWithReasoning($reasoning);

        expect($answer->opaque)->not->toBeNull();
        $envelope = json_decode((string) $answer->opaque?->data, true);

        expect(array_keys($envelope))->toBe(['f', 'm', 'c'])
            ->and($envelope['f'])->toBe('a1')
            ->and($envelope['m'])->toMatch('/^[0-9a-f]{64}$/')
            ->and($envelope['c'])->toBe(['reasoning' => '"The user asks about one city, so the lookup tool is the way."'])
            // Neither the text nor a tool call of the answer holds it.
            ->and($answer->text)->toBe('')
            ->and($answer->toolCalls[0]->rawArguments)->toBe('{"city":"Paris"}');
    });

    it('comes back as the string that arrived, whatever that string holds', function (string $reasoning): void {
        $answer = answerWithReasoning($reasoning);
        $envelope = json_decode((string) $answer->opaque?->data, true);

        // One string in the part, never a decoded value: an empty object in it stays an empty object.
        expect($envelope['c']['reasoning'])->toBeString()
            ->and(json_decode($envelope['c']['reasoning']))->toBe($reasoning)
            ->and(sentBack($answer->toMessage())['reasoning_content'])->toBe($reasoning);
    })->with([
        'plain words' => ['The user asks about one city.'],
        'quotes, a backslash and a slash' => ['He said "go" to C:\\maps or /maps.'],
        'line ends and a tab' => ["First.\n\nSecond.\r\n\tThird."],
        'letters outside ASCII' => ['Zoek naar Curaçao, 東京 en 🙂.'],
        'text that looks like JSON' => ['{}'],
        'text that looks like a list' => ['[]'],
        'text that looks like a number too large for a whole number' => ['12345678901234567890123'],
        'text that looks like a JSON string' => ['"quoted"'],
        'white space around it' => ['  A thought.  '],
        'the marks of an escape' => ['\\u00e9 and \\n stay as they are written'],
    ]);

    it('is read from the other name of the field too, and goes back under the documented one', function (): void {
        $answer = answerWithReasoning('A thought.', REASONING_CARRIER, 'reasoning');

        expect($answer->opaque)->not->toBeNull()
            ->and(sentBack($answer->toMessage()))->toHaveKey('reasoning_content', 'A thought.')
            ->and(sentBack($answer->toMessage()))->not->toHaveKey('reasoning');
    });

    it('is not made for an answer without reasoning', function (mixed $reasoning): void {
        expect(answerWithReasoning($reasoning)->opaque)->toBeNull();
    })->with([
        'null' => [null],
        'an empty string' => [''],
        'a list' => [[['type' => 'text', 'text' => 'A thought.']]],
        'a number' => [7],
    ]);

    it('is not made on a server without a carrier: nothing asks the reasoning back', function (array $options): void {
        expect(answerWithReasoning('A thought.', $options)->opaque)->toBeNull();
    })->with([
        'the default' => [[]],
        'none' => [['reasoning_carrier' => 'none']],
    ]);

    it('is the same for a stream as for the whole answer', function (): void {
        $request = new Request(ChatCompletions::MODEL, [Message::user('Where to?')], 30.0, [ChatCompletions::lookup()]);
        $events = ChatCompletions::eventsOf([
            ChatCompletions::chunk(['role' => 'assistant', 'content' => '']),
            ChatCompletions::chunk(['reasoning_content' => 'The user asks ']),
            ChatCompletions::chunk(['reasoning_content' => 'about one city.']),
            ChatCompletions::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city":']]]]),
            ChatCompletions::chunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"Paris"}']]]]),
            ChatCompletions::chunk([], 'tool_calls'),
            ChatCompletions::DONE,
        ], REASONING_CARRIER, $request);
        $streamed = Talk::finishedIn($events)->answer;

        expect($streamed->opaque?->data)->not->toBeNull()
            ->and($streamed->opaque?->data)->toBe(answerWithReasoning('The user asks about one city.')->opaque?->data);

        // No event before the last holds the reasoning.
        foreach (array_slice($events, 0, -1) as $event) {
            expect(json_encode(valueForms($event)))->not->toContain('about one city');
        }
    });

    it('is kept for an answer with text too', function (): void {
        $response = ChatCompletions::answered(['role' => 'assistant', 'content' => 'Hello.', 'reasoning_content' => 'The user greets.']);
        $answer = ChatCompletions::answerTo($response, REASONING_CARRIER);
        $messages = ChatCompletions::bodyFor(ChatCompletions::request(Message::user('Hi.'), $answer->toMessage(), Message::user('Again.')), REASONING_CARRIER)['messages'];

        expect($answer->text)->toBe('Hello.')
            ->and($messages[1])->toBe(['role' => 'assistant', 'content' => 'Hello.', 'reasoning_content' => 'The user greets.']);
    });
});

describe('the next round', function (): void {
    it('sends the reasoning back on the message it came with, beside its content and its tool calls', function (): void {
        $answer = answerWithReasoning('The user asks about one city.');

        expect(sentBack($answer->toMessage()))->toBe([
            'role' => 'assistant',
            'content' => null,
            'reasoning_content' => 'The user asks about one city.',
            'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']]],
        ]);
    });

    it('sends each of several answers its own reasoning', function (): void {
        $first = answerWithReasoning('First thought.')->toMessage();
        $second = ChatCompletions::answerTo(ChatCompletions::answered(['role' => 'assistant', 'content' => 'It is 18 degrees.', 'reasoning_content' => 'Second thought.']), REASONING_CARRIER)->toMessage();
        $messages = [Message::user('Where to?'), $first, Message::toolResult('call_1', 'lookup', '{"degrees":18}'), $second, Message::user('And tomorrow?')];

        $sent = ChatCompletions::bodyFor(new Request(ChatCompletions::MODEL, $messages, 30.0, [ChatCompletions::lookup()]), REASONING_CARRIER)['messages'];

        expect($sent[1]['reasoning_content'])->toBe('First thought.')
            ->and($sent[3]['reasoning_content'])->toBe('Second thought.')
            ->and($sent[0])->not->toHaveKey('reasoning_content')
            ->and($sent[2])->not->toHaveKey('reasoning_content');
    });

    it('sends nothing of the part when the message was changed since, and still sends the message', function (callable $change): void {
        $message = answerWithReasoning('The user asks about one city.')->toMessage();
        $sent = sentBack($change($message));

        expect($sent['role'])->toBe('assistant')
            ->and($sent)->not->toHaveKey('reasoning_content')
            ->and(json_encode($sent))->not->toContain('about one city');
    })->with([
        'a text was added' => [fn(Message $message): Message => new Message(Role::Assistant, [...$message->parts, new TextPart('One moment.')], $message->toolCalls)],
        'the arguments of its tool call were changed' => [fn(Message $message): Message => new Message(Role::Assistant, $message->parts, [ToolCall::decoded('call_1', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}')])],
        'the id of its tool call was changed' => [fn(Message $message): Message => new Message(Role::Assistant, $message->parts, [ToolCall::decoded('call_9', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')])],
        'a tool call was added' => [fn(Message $message): Message => new Message(Role::Assistant, $message->parts, [...$message->toolCalls, ToolCall::decoded('call_2', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}')])],
        'the part was written again with other white space' => [fn(Message $message): Message => new Message(Role::Assistant, [new OpaquePart(' ' . ($message->parts[0] instanceof OpaquePart ? $message->parts[0]->data : ''))], $message->toolCalls)],
    ]);

    it('sends nothing of a part that is not this adapter\'s own', function (string $data): void {
        $call = ToolCall::decoded('call_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $sent = sentBack(new Message(Role::Assistant, [new OpaquePart($data)], [$call]));

        expect($sent)->toBe([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']]],
        ]);
    })->with([
        'plain text' => ['A thought of somebody else.'],
        'the mark of another adapter' => ['{"f":"t1","m":"0","c":{"reasoning":"\"A thought of somebody else.\""}}'],
        'the mark of this adapter and a hash of nothing' => ['{"f":"a1","m":"0","c":{"reasoning":"\"A thought of somebody else.\""}}'],
        'JSON of another shape' => ['{"reasoning_content":"A thought of somebody else."}'],
    ]);

    it('takes one string from a part and nothing else, also from a part that was made to fit the message', function (string $content): void {
        $call = ToolCall::decoded('call_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $sent = sentBack(new Message(Role::Assistant, [forgedPart('', [$call], $content)], [$call]));

        expect($sent)->not->toHaveKey('reasoning_content')
            ->and(array_keys($sent))->toBe(['role', 'content', 'tool_calls']);
    })->with([
        'another name' => ['{"reasoning_content":"\"A thought.\""}'],
        'a second name beside it' => ['{"reasoning":"\"A thought.\"","model":"another-model"}'],
        'a number' => ['{"reasoning":7}'],
        'a list' => ['{"reasoning":["\"A thought.\""]}'],
        'text that is no JSON' => ['{"reasoning":"A thought."}'],
        'JSON text of a list' => ['{"reasoning":"[\"A thought.\"]"}'],
        'JSON text of an object' => ['{"reasoning":"{\"role\":\"system\"}"}'],
        'JSON text of an empty string' => ['{"reasoning":"\"\""}'],
        'a list in place of the object' => ['["\"A thought.\""]'],
    ]);

    it('can be made to send one string in the one field, and no more, by a part that was made to fit', function (): void {
        // There is no secret in a part: whoever can write the caller's messages can write this. What it can reach
        // is the field the reasoning goes in, as a string.
        $call = ToolCall::decoded('call_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $sent = sentBack(new Message(Role::Assistant, [forgedPart('', [$call], '{"reasoning":"\"A thought.\""}')], [$call]));

        expect(array_keys($sent))->toBe(['role', 'content', 'reasoning_content', 'tool_calls'])
            ->and($sent['reasoning_content'])->toBe('A thought.');
    });

    it('sends nothing of any part on a server without a carrier', function (array $options): void {
        $message = answerWithReasoning('The user asks about one city.')->toMessage();

        expect(sentBack($message, $options))->not->toHaveKey('reasoning_content');
    })->with([
        'the default' => [[]],
        'none' => [['reasoning_carrier' => 'none']],
    ]);

    it('writes the empty content of the message as the option says, beside the reasoning', function (): void {
        $message = answerWithReasoning('A thought.')->toMessage();

        expect(sentBack($message, [...REASONING_CARRIER, 'assistant_content_without_text' => 'empty_string'])['content'])->toBe('');
    });
});
