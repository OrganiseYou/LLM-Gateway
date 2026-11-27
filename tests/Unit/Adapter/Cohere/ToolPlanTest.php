<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Cohere\ToolPlan;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\TextPart;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatV2;
use OrganiseYou\LlmGateway\Tests\Support\ChatV2Fixtures;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// The tool plan and the thinking of an answer in the Chat v2 adapter (docs/design/adapters.md, section 5.1;
// decisions G78, G100, G112): both are kept in the opaque part as the JSON text the server wrote, within limits
// that are measured as text, and go back only as what the format documents and only with the message they came
// with.

// A backslash and a "u", built from a constant, so that no escape of this file's own text stands in for it.
const CHAT_V2_ESCAPE = "\x5cu";

const CHAT_V2_PARIS_CALL = '{"id":"lookup_example01","type":"function","function":{"name":"lookup","arguments":"{\"city\":\"Paris\"}"}}';

/**
 * The content of the opaque part of an answer, as the adapter sealed it.
 *
 * @return array<string, mixed>
 */
function chatV2Sealed(Answer $answer): array
{
    assert($answer->opaque !== null);
    $envelope = json_decode($answer->opaque->data, true);
    assert(is_array($envelope) && is_array($envelope['c']));

    return $envelope['c'];
}

/**
 * One round and the next: the gateway gets this body as its answer, and then sends the message of that answer,
 * changed by `$alter` when one is given, with a result for each of its calls.
 *
 * @param (callable(Message): Message)|null $alter
 * @return array{Answer, string} the answer of the first round, and the assistant message of the second request as
 *                               the JSON text that was sent
 */
function chatV2Rounds(string $body, ?callable $alter = null): array
{
    $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], $body))->willReturn(ChatV2::answered());
    $gateway = ChatV2::gateway($transport);
    $request = ChatV2::request()->withTools([ChatV2::lookup()]);

    $answer = $gateway->complete($request);
    $message = $alter === null ? $answer->toMessage() : $alter($answer->toMessage());

    return [$answer, chatV2SentBack($message)];
}

/**
 * The assistant message the adapter sends for this message, as the JSON text of the request.
 */
function chatV2SentBack(Message $message): string
{
    $transport = (new FakeTransport())->willReturn(ChatV2::answered());
    $messages = [Message::user('Say hello.'), $message];
    foreach ($message->toolCalls as $toolCall) {
        $messages[] = Message::toolResult($toolCall->id, $toolCall->name, 'ok');
    }
    ChatV2::gateway($transport)->complete(ChatV2::request(...$messages));

    $written = OrganiseYou\LlmGateway\Adapter\Shared\RawJson::at($transport->requests()[0]->body, ['messages', 1]);
    assert($written !== null);

    return $written;
}

/**
 * An opaque part as the adapter base seals it for a mark, with a content of the test's choice: what someone who can
 * write where the caller keeps its messages could make.
 *
 * @param list<ToolCall> $toolCalls
 * @param array<string, mixed> $content
 */
function chatV2ForgedPart(string $text, array $toolCalls, array $content, string $mark = 'a4'): OpaquePart
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
 * The assistant message the adapter sends for a message with the text "Hello." and the call of Paris, to which
 * someone added a part with this content, made to fit the message.
 *
 * @param array<string, mixed> $content
 */
function chatV2SentWithAForgedPart(array $content): string
{
    $call = ToolCall::decoded('lookup_example01', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');

    return chatV2SentBack(new Message(Role::Assistant, [chatV2ForgedPart('Hello.', [$call], $content), new TextPart('Hello.')], [$call]));
}

const CHAT_V2_BUILT = '{"role":"assistant","content":"Hello.","tool_calls":[' . CHAT_V2_PARIS_CALL . ']}';

describe('the opaque part of an answer of the Chat v2 adapter with a tool plan', function (): void {
    it('holds the plan as JSON text in one string, bound to this adapter and to the answer', function (): void {
        $answer = ChatV2::answerTo(ChatV2::answered(null, [ChatV2::call()], plan: 'I will look up the city.'));

        expect($answer->text)->toBe('')
            ->and($answer->opaque)->not->toBeNull()
            ->and($answer->opaque?->data)->toMatch('/^\{"f":"a4","m":"[0-9a-f]{64}","c":\{"tool_plan":"\\\\"I will look up the city\.\\\\""\}\}$/D')
            ->and(chatV2Sealed($answer))->toBe(['tool_plan' => '"I will look up the city."'])
            ->and($answer->toMessage()->parts[0])->toBeInstanceOf(OpaquePart::class);
    });

    it('holds the content as JSON text in one string when it holds thinking, with its text blocks as they were cut', function (): void {
        $answer = ChatV2::answerTo(ChatV2::answered([ChatV2::thinking('A greeting.'), ChatV2::text('Hel'), ChatV2::text('lo.')]));

        expect($answer->text)->toBe('Hello.')
            ->and(chatV2Sealed($answer))->toBe(['content' => '[{"type":"thinking","thinking":"A greeting."},{"type":"text","text":"Hel"},{"type":"text","text":"lo."}]']);
    });

    it('holds both for an answer that has both, each under the name of its member', function (): void {
        $answer = ChatV2::answerTo(ChatV2::answered([ChatV2::thinking('One city.')], [ChatV2::call()], plan: 'I will look.'));

        expect(chatV2Sealed($answer))->toBe(['tool_plan' => '"I will look."', 'content' => '[{"type":"thinking","thinking":"One city."}]']);
    });

    it('writes every string in one spelling, whatever escapes and whitespace the server used', function (): void {
        $body = '{"finish_reason":"TOOL_CALL","message":{"role":"assistant","tool_plan" : "caf' . CHAT_V2_ESCAPE . '00e9 \/ ' . CHAT_V2_ESCAPE . '0041' . CHAT_V2_ESCAPE . '2028",'
            . '"content":[ {"type" : "thinking", "thinking":"' . CHAT_V2_ESCAPE . '0048m."} ],"tool_calls":[' . CHAT_V2_PARIS_CALL . ']}}';
        $answer = ChatV2::answerTo(new HttpResponse(200, [], $body));

        expect(chatV2Sealed($answer))->toBe(['tool_plan' => "\"café / A\u{2028}\"", 'content' => '[{"type":"thinking","thinking":"Hm."}]']);
    });

    it('is not made for an answer of text and tool calls alone: both have a neutral form', function (?array $content, array $calls, ?string $plan): void {
        expect(ChatV2::answerTo(ChatV2::answered($content === null ? null : array_values($content), array_values($calls), plan: $plan))->opaque)->toBeNull();
    })->with([
        'text' => [[ChatV2::text()], [], null],
        'text in two blocks' => [[ChatV2::text('Hel'), ChatV2::text('lo.')], [], null],
        'a tool call' => [null, [ChatV2::call()], null],
        'text and tool calls' => [[ChatV2::text()], [ChatV2::call(), ChatV2::call('{}', 'lookup_example02')], null],
        'an empty plan' => [[ChatV2::text()], [ChatV2::call()], ''],
    ]);

    it('is not made for a plan of another type, and the answer is read all the same', function (string $plan): void {
        $answer = ChatV2::answerTo(new HttpResponse(200, [], '{"finish_reason":"TOOL_CALL","message":{"role":"assistant","tool_plan":' . $plan . ',"tool_calls":[' . CHAT_V2_PARIS_CALL . ']}}'));

        expect($answer->opaque)->toBeNull()
            ->and($answer->toolCalls)->toHaveCount(1);
    })->with(['7', 'null', 'true', '["I will look."]', '{"text":"I will look."}']);

    it('keeps no content that holds anything but the two documented kinds of block with exactly their documented members; the answer is read all the same, and a plan beside it is still kept', function (string $content): void {
        $message = fn(string $plan): string => '{"finish_reason":"COMPLETE","message":{"role":"assistant",' . $plan . '"content":' . $content . '}}';
        $alone = ChatV2::answerTo(new HttpResponse(200, [], $message('')));
        $withAPlan = ChatV2::answerTo(new HttpResponse(200, [], $message('"tool_plan":"I will look.",')));

        expect($alone->opaque)->toBeNull()
            ->and($alone->text)->toBe('Hello.')
            ->and(chatV2Sealed($withAPlan))->toBe(['tool_plan' => '"I will look."']);
    })->with([
        'a block of another kind' => ['[{"type":"thinking","thinking":"Hm."},{"type":"image_url","image_url":{"url":"https://other.example.test/x.png"}},{"type":"text","text":"Hello."}]'],
        'a member no page names on the thinking' => ['[{"type":"thinking","thinking":"Hm.","signature":"c2ln"},{"type":"text","text":"Hello."}]'],
        'a member no page names on the text' => ['[{"type":"thinking","thinking":"Hm."},{"type":"text","text":"Hello.","citations":[]}]'],
        'a thinking block without its thinking' => ['[{"type":"thinking"},{"type":"text","text":"Hello."}]'],
        'thinking of another type' => ['[{"type":"thinking","thinking":["Hm."]},{"type":"text","text":"Hello."}]'],
        'a text block whose text stands under the name of the other kind' => ['[{"type":"thinking","thinking":"Hm."},{"type":"text","thinking":"x"},{"type":"text","text":"Hello."}]'],
        'something that is no block' => ['[{"type":"thinking","thinking":"Hm."},"a string",{"type":"text","text":"Hello."}]'],
        'a name twice in a block' => ['[{"type":"thinking","thinking":"first","thinking":"last"},{"type":"text","text":"Hello."}]'],
    ]);

    it('is the same for a stream as for the whole answer, byte for byte, for the fixture pair of the shared set', function (): void {
        $fixtures = new ChatV2Fixtures();
        $whole = $fixtures->answer(ContractFixture::ToolCallWithOpaqueData);
        $chunks = $fixtures->stream(ContractFixture::ToolCallWithOpaqueData);
        assert($whole instanceof HttpResponse && is_array($chunks));
        $request = ChatV2::request()->withTools([ChatV2::lookup()]);

        $answered = ChatV2::answerTo($whole, request: $request);
        $streamed = Talk::finishedIn(ChatV2::eventsOf($chunks, request: $request))->answer;

        expect($answered->opaque)->not->toBeNull()
            ->and($streamed->opaque?->data)->toBe($answered->opaque?->data);
    });
});

describe('the plan and the thinking of a stream of the Chat v2 adapter', function (): void {
    it('are joined from their pieces into what a whole answer holds: the plan as one string, the blocks in the order of their index', function (): void {
        $whole = ChatV2::answerTo(ChatV2::answered([ChatV2::thinking('One city, so one call.'), ChatV2::text('Let me look.')], [ChatV2::call()], plan: 'I will look up the city.'));
        $events = ChatV2::eventsOf([
            ChatV2::started(),
            ChatV2::blockStart(1), ChatV2::piece('Let me ', 1),
            ChatV2::blockStart(0, 'thinking'), ChatV2::piece('One city, ', 0, 'thinking'), ChatV2::piece('so one call.', 0, 'thinking'), ChatV2::blockEnd(0),
            ChatV2::piece('look.', 1), ChatV2::blockEnd(1),
            ChatV2::planPiece('I will look '), ChatV2::planPiece(''), ChatV2::planPiece('up the city.'),
            ...ChatV2::calling(),
            ChatV2::ended('TOOL_CALL'),
        ]);
        $streamed = Talk::finishedIn($events)->answer;

        expect(chatV2Sealed($streamed))->toBe([
            'tool_plan' => '"I will look up the city."',
            'content' => '[{"type":"thinking","thinking":"One city, so one call."},{"type":"text","text":"Let me look."}]',
        ])
            ->and($streamed->opaque?->data)->toBe($whole->opaque?->data);
    });

    it('are kept with the text a block starts with, and for thinking alone', function (): void {
        $events = ChatV2::eventsOf([ChatV2::started(), ChatV2::blockStart(0, 'thinking', 'Hm'), ChatV2::piece('.', 0, 'thinking'), ChatV2::blockStart(1, 'text', 'Hel'), ChatV2::piece('lo.', 1), ChatV2::ended()]);

        expect(chatV2Sealed(Talk::finishedIn($events)->answer))->toBe(['content' => '[{"type":"thinking","thinking":"Hm."},{"type":"text","text":"Hello."}]']);
    });

    it('are not kept for a stream of text and tool calls alone', function (): void {
        expect(Talk::finishedIn(ChatV2::eventsOf(ChatV2::saying()))->answer->opaque)->toBeNull()
            ->and(Talk::finishedIn(ChatV2::eventsOf([ChatV2::started(), ...ChatV2::calling(), ChatV2::ended('TOOL_CALL')]))->answer->opaque)->toBeNull()
            ->and(Talk::finishedIn(ChatV2::eventsOf([ChatV2::started(), ChatV2::planPiece(''), ...ChatV2::calling(), ChatV2::ended('TOOL_CALL')]))->answer->opaque)->toBeNull();
    });

    it('keep no content of a stream with a block of a kind that is not kept, or with an index that starts twice, and still keep its plan', function (string $event): void {
        $events = ChatV2::eventsOf([
            ChatV2::started(), ChatV2::blockStart(0, 'thinking'), ChatV2::piece('Hm.', 0, 'thinking'), $event, ChatV2::blockStart(1), ChatV2::piece('Hello.', 1),
            ChatV2::planPiece('I will look.'), ...ChatV2::calling(), ChatV2::ended('TOOL_CALL'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->text)->toBe('Hello.')
            ->and(chatV2Sealed($answer))->toBe(['tool_plan' => '"I will look."']);
    })->with([
        'a block of another kind' => [ChatV2::blockStart(7, 'image', 'x')],
        'a block without a kind' => [ChatV2::event('content-start', ['index' => 7, 'delta' => ['message' => ['content' => ['text' => '']]]])],
        'an index that starts twice' => [ChatV2::blockStart(0, 'thinking', 'Again.')],
        'an index that starts twice, as another kind' => [ChatV2::blockStart(0, 'text', 'Hello.')],
    ]);

    it('hold nothing of an answer whose plan repeats a credential of the gateway: the part is dropped and the answer is kept', function (): void {
        $events = ChatV2::eventsOf([ChatV2::started(), ChatV2::planPiece('The caller signs in with Key-Of-'), ChatV2::planPiece('The-Test.'), ...ChatV2::calling(), ChatV2::ended('TOOL_CALL')]);
        $whole = ChatV2::answerTo(ChatV2::answered(null, [ChatV2::call()], plan: 'The caller signs in with key-of-the-test.'));

        expect(Talk::finishedIn($events)->answer->opaque)->toBeNull()
            ->and(Talk::finishedIn($events)->answer->toolCalls)->toHaveCount(1)
            ->and($whole->opaque)->toBeNull()
            ->and($whole->toolCalls)->toHaveCount(1);
    });
});

describe('the round after an answer of the Chat v2 adapter with a tool plan', function (): void {
    it('sends the plan back with the message it came with, beside the calls built from the message, and the tool results after it', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered(null, [ChatV2::call(), ChatV2::call('{"city":"Rome"}', 'lookup_example02')], plan: 'I will look up both.'))->willReturn(ChatV2::answered());
        $gateway = ChatV2::gateway($transport);
        $request = ChatV2::request()->withTools([ChatV2::lookup()]);

        $answer = $gateway->complete($request);
        $gateway->complete($request->withMessages([...$request->messages, $answer->toMessage(), Message::toolResult('lookup_example01', 'lookup', 'sunny'), Message::toolResult('lookup_example02', 'lookup', 'rain')]));

        expect(ChatV2::sent($transport, 1)['messages'])->toBe([
            ['role' => 'user', 'content' => 'Say hello.'],
            ['role' => 'assistant', 'tool_plan' => 'I will look up both.', 'tool_calls' => [
                ['id' => 'lookup_example01', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']],
                ['id' => 'lookup_example02', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Rome"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'lookup_example01', 'content' => 'sunny'],
            ['role' => 'tool', 'tool_call_id' => 'lookup_example02', 'content' => 'rain'],
        ]);
    });

    it('sends the content back as the list of blocks it came as, in place of the text, with the plan and the calls', function (): void {
        [, $sent] = chatV2Rounds(ChatV2::json(ChatV2::body([ChatV2::thinking('One city.'), ChatV2::text('Let me '), ChatV2::text('look.')], [ChatV2::call()], plan: 'I will look.')));

        expect($sent)->toBe('{"role":"assistant","tool_plan":"I will look.","content":[{"type":"thinking","thinking":"One city."},{"type":"text","text":"Let me "},{"type":"text","text":"look."}],"tool_calls":[' . CHAT_V2_PARIS_CALL . ']}');
    });

    it('sends each of several answers its own plan', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(ChatV2::answered(null, [ChatV2::call()], plan: 'First the city.'))
            ->willReturn(ChatV2::answered(null, [ChatV2::call('{"city":"Rome"}', 'lookup_example02')], plan: 'Then the other.'))
            ->willReturn(ChatV2::answered());
        $gateway = ChatV2::gateway($transport);
        $request = ChatV2::request()->withTools([ChatV2::lookup()]);

        $first = $gateway->complete($request);
        $messages = [...$request->messages, $first->toMessage(), Message::toolResult('lookup_example01', 'lookup', 'sunny')];
        $second = $gateway->complete($request->withMessages($messages));
        $gateway->complete($request->withMessages([...$messages, $second->toMessage(), Message::toolResult('lookup_example02', 'lookup', 'rain')]));
        $sent = ChatV2::sent($transport, 2)['messages'];

        expect($sent[1]['tool_plan'])->toBe('First the city.')
            ->and($sent[3]['tool_plan'])->toBe('Then the other.')
            ->and(substr_count($transport->requests()[2]->body, 'tool_plan'))->toBe(2);
    });

    it('sends nothing of the part when the message was changed since or built by hand, and still sends the message', function (callable $change, string $built): void {
        [$answer, $sent] = chatV2Rounds(ChatV2::json(ChatV2::body([ChatV2::thinking('One city.'), ChatV2::text('Hello.')], [ChatV2::call()], plan: 'I will look.')), $change);

        expect($answer->opaque)->not->toBeNull()
            ->and($sent)->toBe($built)
            ->and($sent)->not->toContain('I will look.')
            ->and($sent)->not->toContain('One city.');
    })->with([
        'another text' => [
            fn(Message $message): Message => new Message(Role::Assistant, [...array_filter($message->parts, fn($part): bool => $part instanceof OpaquePart), new TextPart('Hallo.')], $message->toolCalls),
            '{"role":"assistant","content":"Hallo.","tool_calls":[' . CHAT_V2_PARIS_CALL . ']}',
        ],
        'other arguments of the call' => [
            fn(Message $message): Message => new Message(Role::Assistant, $message->parts, [ToolCall::decoded('lookup_example01', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}')]),
            '{"role":"assistant","content":"Hello.","tool_calls":[{"id":"lookup_example01","type":"function","function":{"name":"lookup","arguments":"{\"city\":\"Rome\"}"}}]}',
        ],
        'the same arguments in another spelling' => [
            fn(Message $message): Message => new Message(Role::Assistant, $message->parts, [ToolCall::decoded('lookup_example01', 'lookup', ['city' => 'Paris'], '{"city": "Paris"}')]),
            '{"role":"assistant","content":"Hello.","tool_calls":[{"id":"lookup_example01","type":"function","function":{"name":"lookup","arguments":"{\"city\": \"Paris\"}"}}]}',
        ],
        'another id of the call' => [
            fn(Message $message): Message => new Message(Role::Assistant, $message->parts, [ToolCall::decoded('lookup_other', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')]),
            '{"role":"assistant","content":"Hello.","tool_calls":[{"id":"lookup_other","type":"function","function":{"name":"lookup","arguments":"{\"city\":\"Paris\"}"}}]}',
        ],
        'no call any more' => [
            fn(Message $message): Message => new Message(Role::Assistant, $message->parts, []),
            '{"role":"assistant","content":"Hello."}',
        ],
        'built by hand' => [
            fn(Message $message): Message => Message::assistant($message->text(), ...$message->toolCalls),
            CHAT_V2_BUILT,
        ],
    ]);

    it('sends nothing of a part that is not this adapter\'s own', function (callable $part): void {
        $call = ToolCall::decoded('lookup_example01', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $sent = chatV2SentBack(new Message(Role::Assistant, [$part($call), new TextPart('Hello.')], [$call]));

        expect($sent)->toBe(CHAT_V2_BUILT);
    })->with([
        'the mark of another adapter' => [fn(ToolCall $call): OpaquePart => chatV2ForgedPart('Hello.', [$call], ['tool_plan' => '"forged"'], 'a1')],
        'the mark of an adapter of a host' => [fn(ToolCall $call): OpaquePart => chatV2ForgedPart('Hello.', [$call], ['tool_plan' => '"forged"'], 'x7')],
        'data that is no JSON' => [fn(): OpaquePart => new OpaquePart('a plan, and this is no JSON')],
        'the right mark and the hash of another message' => [fn(ToolCall $call): OpaquePart => chatV2ForgedPart('Another text.', [$call], ['tool_plan' => '"forged"'])],
        'the right mark and the hash of a message with another call' => [fn(ToolCall $call): OpaquePart => chatV2ForgedPart('Hello.', [], ['tool_plan' => '"forged"'])],
    ]);

    it('takes two strings from a part and nothing else, each only as what the format documents, also from a part that was made to fit the message', function (array $content): void {
        $sent = chatV2SentWithAForgedPart($content);

        expect($sent)->toBe(CHAT_V2_BUILT)
            ->and(str_contains($sent, 'forged'))->toBeFalse();
    })->with([
        'another member name' => [['plan' => '"forged"']],
        'a member beside the two' => [['tool_plan' => '"a plan"', 'tool_calls' => '[{"id":"forged"}]']],
        'a member beside the two that names a field of the request' => [['tool_plan' => '"a plan"', 'model' => 'forged']],
        'a plan that is a decoded string, not JSON text' => [['tool_plan' => 'forged']],
        'a plan that is no string in the part' => [['tool_plan' => ['forged']]],
        'a plan whose JSON text is a number' => [['tool_plan' => '7']],
        'a plan whose JSON text is an object' => [['tool_plan' => '{"forged":true}']],
        'a plan whose JSON text is a list' => [['tool_plan' => '["forged"]']],
        'a plan with something after it' => [['tool_plan' => '"a plan","tool_calls":[{"id":"forged"}]']],
        'an empty plan' => [['tool_plan' => '""']],
        'a plan with whitespace around it: not the one spelling' => [['tool_plan' => ' "forged"']],
        'a plan written with an escape it does not need: not the one spelling' => [['tool_plan' => '"' . CHAT_V2_ESCAPE . '0066orged"']],
        'a good plan beside a content that is not kept: all or nothing' => [['tool_plan' => '"forged"', 'content' => '[{"type":"text","text":"Hello."}]']],
        'a content that is a list of decoded blocks, not a string' => [['content' => [['type' => 'thinking', 'thinking' => 'forged']]]],
        'a content that is no JSON' => [['content' => 'forged']],
        'a content that is an object, not a list' => [['content' => '{"type":"thinking","thinking":"forged"}']],
        'an empty list' => [['content' => '[]']],
        'a list without thinking' => [['content' => '[{"type":"text","text":"Hello."}]']],
        'a list with whitespace in it: not the one spelling' => [['content' => '[{"type":"thinking","thinking":"forged"}, {"type":"text","text":"Hello."}]']],
        'a list whose text is not the text of the message' => [['content' => '[{"type":"thinking","thinking":"forged"},{"type":"text","text":"Hallo."}]']],
        'a list without the text of the message' => [['content' => '[{"type":"thinking","thinking":"forged"}]']],
        'a list that says the text of the message twice' => [['content' => '[{"type":"thinking","thinking":"forged"},{"type":"text","text":"Hello."},{"type":"text","text":"Hello."}]']],
        'a list whose text blocks are in another order' => [['content' => '[{"type":"text","text":"lo."},{"type":"thinking","thinking":"forged"},{"type":"text","text":"Hel"}]']],
        'a list that holds a block of a kind the format documents for another role' => [['content' => '[{"type":"thinking","thinking":"forged"},{"type":"document","document":{"data":"forged"}},{"type":"text","text":"Hello."}]']],
        'a list that holds an image' => [['content' => '[{"type":"thinking","thinking":"forged"},{"type":"image_url","image_url":{"url":"https://forged.example.test/"}},{"type":"text","text":"Hello."}]']],
        'a list that holds a string' => [['content' => '[{"type":"thinking","thinking":"forged"},"forged",{"type":"text","text":"Hello."}]']],
        'a block with a member no page names' => [['content' => '[{"type":"thinking","thinking":"forged","tool_calls":[]},{"type":"text","text":"Hello."}]']],
        'a block that is two blocks by a name written twice' => [['content' => '[{"type":"text","text":"forged","type":"thinking","thinking":"t"},{"type":"text","text":"Hello."}]']],
        'a text block that says two things by a name written twice' => [['content' => '[{"type":"thinking","thinking":"t"},{"text":"forged","type":"text","text":"Hello."}]']],
        'a thinking block whose thinking is a list' => [['content' => '[{"type":"thinking","thinking":["forged"]},{"type":"text","text":"Hello."}]']],
        'a text block whose text is an object' => [['content' => '[{"type":"thinking","thinking":"forged"},{"type":"text","text":{"forged":"Hello."}}]']],
    ]);

    it('compares what a part says as text in one spelling and in its order: the text blocks, one after the other, are the text of the message', function (string $content, bool $agrees): void {
        $sent = chatV2SentWithAForgedPart(['content' => $content]);

        expect($sent === CHAT_V2_BUILT)->toBe(! $agrees)
            ->and(str_contains($sent, '"content":' . $content))->toBe($agrees);
    })->with([
        'the text in one block' => ['[{"type":"thinking","thinking":"t"},{"type":"text","text":"Hello."}]', true],
        'the text in two blocks, around the thinking' => ['[{"type":"text","text":"Hel"},{"type":"thinking","thinking":"t"},{"type":"text","text":"lo."}]', true],
        'the text in two blocks and an empty one' => ['[{"type":"thinking","thinking":"t"},{"type":"text","text":"Hel"},{"type":"text","text":""},{"type":"text","text":"lo."}]', true],
        'the two blocks the other way round' => ['[{"type":"thinking","thinking":"t"},{"type":"text","text":"lo."},{"type":"text","text":"Hel"}]', false],
        'the members of a block in another order: still one spelling of each string' => ['[{"thinking":"t","type":"thinking"},{"text":"Hello.","type":"text"}]', true],
        'the same text written with an escape' => ['[{"type":"thinking","thinking":"t"},{"type":"text","text":"' . CHAT_V2_ESCAPE . '0048ello."}]', false],
        'the same text with a space after a colon' => ['[{"type":"thinking","thinking":"t"},{"type":"text","text": "Hello."}]', false],
    ]);

    it('can be made to send a plan and thinking of someone\'s choice, and nothing else, by a part that was made to fit', function (): void {
        // The limit of a part: nothing in it is secret, so whoever can write where the caller keeps its messages
        // can choose what has no neutral form: the plan, the thinking, and over how many blocks the text is cut.
        // The kinds of block, their members, the text and the tool calls are held to the message.
        $sent = chatV2SentWithAForgedPart(['tool_plan' => '"made up"', 'content' => '[{"type":"thinking","thinking":"made up too"},{"type":"text","text":"Hel"},{"type":"text","text":"lo."}]']);

        expect($sent)->toBe('{"role":"assistant","tool_plan":"made up","content":[{"type":"thinking","thinking":"made up too"},{"type":"text","text":"Hel"},{"type":"text","text":"lo."}],"tool_calls":[' . CHAT_V2_PARIS_CALL . ']}');
    });

    it('never takes a field of the request, an address or a header from a part', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered());
        $call = ToolCall::decoded('lookup_example01', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $part = chatV2ForgedPart('Hello.', [$call], ['tool_plan' => '"a plan"', 'model' => 'forged', 'url' => 'https://forged.example.test/v2/chat', 'stream' => 'true', 'headers' => 'X-Forged: 1']);
        ChatV2::gateway($transport)->complete(ChatV2::request(Message::user('Hi.'), new Message(Role::Assistant, [$part, new TextPart('Hello.')], [$call]), Message::toolResult('lookup_example01', 'lookup', 'ok')));
        $sent = $transport->requests()[0];

        expect($sent->url)->toBe('https://llm.example.test/v2/chat')
            ->and($sent->headers)->toBe(['content-type' => 'application/json'])
            ->and($sent->body)->not->toContain('forged')
            ->and($sent->body)->not->toContain('a plan')
            ->and(array_keys(ChatV2::sent($transport)))->toBe(['model', 'messages', 'stream']);
    });
});

describe('the limits of what the Chat v2 adapter keeps', function (): void {
    $megabyte = 1_048_576;

    it('has the limits of an opaque part', function () use ($megabyte): void {
        expect(ToolPlan::LIMIT_BYTES)->toBe($megabyte)
            ->and(ToolPlan::LIMIT_CONTAINERS)->toBe(10_000);
    });

    it('keeps a plan of 1 MiB as text and not one of a byte more, measured before anything is decoded', function () use ($megabyte): void {
        expect(ToolPlan::plan('"' . str_repeat('a', $megabyte - 2) . '"'))->not->toBeNull()
            ->and(ToolPlan::plan('"' . str_repeat('a', $megabyte - 1) . '"'))->toBeNull()
            // Not even looked at: a text of this length that is no JSON at all gives the same answer.
            ->and(ToolPlan::plan(str_repeat('"', $megabyte + 1)))->toBeNull();
    });

    it('keeps a content of 10,000 objects and lists, and not one of 10,001', function (): void {
        $list = fn(int $blocks): string => '[{"type":"thinking","thinking":"t"}' . str_repeat(',{"type":"text","text":""}', $blocks - 1) . ']';

        expect(ToolPlan::content($list(9_999)))->toBe($list(9_999))
            ->and(ToolPlan::content($list(10_000)))->toBeNull()
            ->and(ToolPlan::isWithinTheLimits($list(9_999)))->toBeTrue()
            ->and(ToolPlan::isWithinTheLimits($list(10_000)))->toBeFalse();
    });

    it('counts a bracket inside a string as text, not as a list', function (): void {
        $list = '[{"type":"thinking","thinking":"' . str_repeat('[{', 20_000) . '"}]';

        expect(ToolPlan::content($list))->toBe($list);
    });

    it('gives a whole answer whose plan or content is over a limit without the part, and reads the answer all the same', function (string $message): void {
        $answer = ChatV2::answerTo(new HttpResponse(200, [], '{"finish_reason":"TOOL_CALL","message":{"role":"assistant",' . $message . ',"tool_calls":[' . CHAT_V2_PARIS_CALL . ']}}'));

        expect($answer->opaque)->toBeNull()
            ->and($answer->toolCalls)->toHaveCount(1);
    })->with([
        'a plan of more than 1 MiB' => ['"tool_plan":"' . str_repeat('a', 1_048_576) . '"'],
        'a content of more than 1 MiB' => ['"content":[{"type":"thinking","thinking":"' . str_repeat('a', 1_048_576) . '"}]'],
        'a content of too many blocks' => ['"content":[{"type":"thinking","thinking":"t"}' . str_repeat(',{"type":"text","text":""}', 10_000) . ']'],
    ]);

    it('keeps, end to end, a plan of at most 1,048,470 bytes and a content of at most 9,999 blocks: the base measures the part as it is sealed', function (): void {
        $kept = fn(string $message): bool => ChatV2::answerTo(new HttpResponse(200, [], '{"finish_reason":"TOOL_CALL","message":{"role":"assistant",' . $message . ',"tool_calls":[' . CHAT_V2_PARIS_CALL . ']}}'))->opaque !== null;
        $plan = fn(int $bytes): string => '"tool_plan":"' . str_repeat('a', $bytes) . '"';
        $blocks = fn(int $count): string => '"content":[{"type":"thinking","thinking":"t"}' . str_repeat(',{"type":"text","text":""}', $count - 1) . ']';

        // The plan's own limit is 1 MiB of its JSON text; the part around it, with the text escaped once more and
        // the envelope of the base, takes 106 bytes beside the plan.
        expect(ToolPlan::plan('"' . str_repeat('a', 1_048_471) . '"'))->not->toBeNull()
            ->and($kept($plan(1_048_470)))->toBeTrue()
            ->and($kept($plan(1_048_471)))->toBeFalse()
            // One list and 9,999 blocks are the 10,000 objects and lists of the adapter's own limit.
            ->and($kept($blocks(9_999)))->toBeTrue()
            ->and($kept($blocks(10_000)))->toBeFalse();
    });

    it('keeps the plan of an answer whose content has too many blocks: each is measured on its own', function (): void {
        $answer = ChatV2::answerTo(new HttpResponse(200, [], '{"finish_reason":"TOOL_CALL","message":{"role":"assistant","tool_plan":"I will look.","content":[{"type":"thinking","thinking":"t"}' . str_repeat(',{"type":"text","text":""}', 10_000) . '],"tool_calls":[' . CHAT_V2_PARIS_CALL . ']}}'));

        expect(chatV2Sealed($answer))->toBe(['tool_plan' => '"I will look."']);
    });

    it('sends a message whose part was made with too many blocks, or too long, without the part, and does not decode the list', function (): void {
        $tooMany = '[{"type":"thinking","thinking":"forged"}' . str_repeat(',{"type":"text","text":""}', 10_000) . ',{"type":"text","text":"Hello."}]';
        $fits = '[{"type":"thinking","thinking":"fits"}' . str_repeat(',{"type":"text","text":""}', 9_997) . ',{"type":"text","text":"Hello."}]';

        expect(chatV2SentWithAForgedPart(['content' => $tooMany]))->toBe(CHAT_V2_BUILT)
            ->and(chatV2SentWithAForgedPart(['content' => $fits]))->toContain('"content":' . $fits)
            ->and(ToolPlan::agreeing(['tool_plan' => '"' . str_repeat('a', 600_000) . '"', 'content' => '[{"type":"thinking","thinking":"' . str_repeat('a', 600_000) . '"}]'], ''))->toBeNull()
            ->and(ToolPlan::agreeing(['tool_plan' => '"' . str_repeat('a', 400_000) . '"', 'content' => '[{"type":"thinking","thinking":"' . str_repeat('a', 400_000) . '"}]'], ''))->not->toBeNull();
    });

    it('stops keeping one kind of a stream when its bytes pass 1 MiB, lets go of what it joined, and keeps the other kind and the answer', function (string $kind, ?array $kept) use ($megabyte): void {
        $piece = str_repeat('a', 65_536);
        $chunks = [ChatV2::started()];
        if ($kind === 'thinking') {
            $chunks[] = ChatV2::blockStart(0, 'thinking');
        }
        for ($at = 0; $at < 17; $at++) {
            $chunks[] = $kind === 'plan' ? ChatV2::planPiece($piece) : ChatV2::piece($piece, 0, 'thinking');
        }
        // After its limit was passed: a small plan is not kept either; a plan is kept beside a content that was not.
        $chunks[] = ChatV2::planPiece('I will look.');
        $answer = Talk::finishedIn(ChatV2::eventsOf([...$chunks, ChatV2::blockStart(1), ChatV2::piece('Hello.', 1), ...ChatV2::calling(), ChatV2::ended('TOOL_CALL')]))->answer;

        expect(17 * 65_536)->toBeGreaterThan($megabyte)
            ->and($answer->opaque === null ? null : chatV2Sealed($answer))->toBe($kept)
            ->and($answer->text)->toBe('Hello.')
            ->and($answer->toolCalls)->toHaveCount(1);
    })->with([
        'the plan' => ['plan', null],
        'the thinking' => ['thinking', ['tool_plan' => '"I will look."']],
    ]);

    it('keeps the plan of an answer whose text is over 1 MiB, or whose thinking and text are, the same whole and as a stream', function (array $blocks): void {
        $request = ChatV2::request()->withTools([ChatV2::lookup()]);
        $whole = ChatV2::answerTo(ChatV2::answered(array_values($blocks), [ChatV2::call()], plan: 'I will look.'), request: $request);
        $chunks = [ChatV2::started(), ChatV2::planPiece('I will look.')];
        foreach (array_values($blocks) as $index => $block) {
            $chunks[] = ChatV2::blockStart($index, $block['type']);
            foreach (str_split($block[$block['type']], 65_536) as $piece) {
                $chunks[] = ChatV2::piece($piece, $index, $block['type']);
            }
        }
        $streamed = Talk::finishedIn(ChatV2::eventsOf([...$chunks, ...ChatV2::calling(), ChatV2::ended('TOOL_CALL')], request: $request))->answer;

        expect(chatV2Sealed($whole))->toBe(['tool_plan' => '"I will look."'])
            ->and($streamed->opaque?->data)->toBe($whole->opaque?->data)
            ->and($streamed->text)->toBe($whole->text);
    })->with([
        'one text block of 1,100,000 bytes' => [[ChatV2::text(str_repeat('a', 1_100_000))]],
        'thinking and text of more than 1 MiB together' => [[ChatV2::thinking('One city.'), ChatV2::text(str_repeat('a', 1_048_576))]],
    ]);

    it('counts what the pieces are joined to, not the pieces: a long plan in many small pieces is kept', function (): void {
        $chunks = [ChatV2::started()];
        for ($at = 0; $at < 5_000; $at++) {
            $chunks[] = ChatV2::planPiece('I will look up a city. ');
        }
        $answer = Talk::finishedIn(ChatV2::eventsOf([...$chunks, ...ChatV2::calling(), ChatV2::ended('TOOL_CALL')]))->answer;

        expect(chatV2Sealed($answer)['tool_plan'])->toBe('"' . str_repeat('I will look up a city. ', 5_000) . '"');
    });

    it('measures a piece as the text it will be kept as: a piece that grows when it is written counts as it is written', function (): void {
        $pieces = ToolPlan::none();
        // A quote takes two bytes in JSON text, and the plan's own two quotes count too: 2 + 524,287 × 2 = 1 MiB.
        ToolPlan::addToPlan($pieces, str_repeat('"', 524_287));
        $bytes = $pieces['planBytes'];
        $atTheLimit = ToolPlan::sealable($pieces);
        ToolPlan::addToPlan($pieces, 'a');
        $over = ToolPlan::none();
        ToolPlan::addToPlan($over, str_repeat('"', 524_288));

        expect($bytes)->toBe(1_048_576)
            ->and(strlen($atTheLimit['tool_plan'] ?? ''))->toBe(1_048_576)
            // What was joined is let go at once, and no later piece is kept.
            ->and($pieces['planKept'])->toBeFalse()
            ->and($pieces['plan'])->toBe('')
            ->and(ToolPlan::sealable($pieces))->toBe([])
            ->and($over['plan'])->toBe('')
            ->and(ToolPlan::sealable($over))->toBe([]);
    });

    it('counts a streamed content list exactly as it is written: brackets, braces, names, kinds and commas', function (array $starts, int $length) use ($megabyte): void {
        // Blocks that start empty, the last one filled to the length that makes the list exactly 1 MiB.
        $fill = function (int $length) use ($starts): array {
            $pieces = ToolPlan::none();
            foreach ($starts as $index => [$kind, $text]) {
                ToolPlan::start($pieces, $index, $kind, $text);
            }
            foreach (str_split(str_repeat('a', $length), 100_000) as $piece) {
                ToolPlan::add($pieces, count($starts) - 1, $piece);
            }

            return $pieces;
        };
        $atTheLimit = $fill($length);
        $over = $fill($length + 1);

        expect($atTheLimit['blockBytes'])->toBe($megabyte)
            ->and(strlen(ToolPlan::sealable($atTheLimit)['content'] ?? ''))->toBe($megabyte)
            ->and($over['listed'])->toBeFalse()
            ->and($over['blocks'])->toBe([])
            ->and(ToolPlan::sealable($over))->toBe([]);
    })->with([
        // 2 brackets, `{"type":"thinking","thinking":` and `}` (31), the quotes (2): 35 bytes beside the text.
        'one thinking block' => [[['thinking', '']], 1_048_541],
        // The first block takes 36 with its text `t`; the second a comma, 15, the kind twice (8) and its quotes: 26.
        'a thinking block and a text block' => [[['thinking', 't'], ['text', '']], 1_048_514],
    ]);

    it('keeps no content of a stream of more blocks than a kept list may hold, and still its plan', function (): void {
        $pieces = ToolPlan::none();
        ToolPlan::addToPlan($pieces, 'I will look.');
        for ($index = 0; $index < 9_999; $index++) {
            ToolPlan::start($pieces, $index, $index === 0 ? 'thinking' : 'text', '');
        }
        $atTheLimit = ToolPlan::sealable($pieces);
        ToolPlan::start($pieces, 9_999, 'text', '');

        expect(array_keys($atTheLimit))->toBe(['tool_plan', 'content'])
            ->and(ToolPlan::sealable($pieces))->toBe(['tool_plan' => '"I will look."'])
            ->and($pieces['blocks'])->toBe([]);
    });
});

describe('the pieces of a stream as the Chat v2 adapter keeps them', function (): void {
    it('adds a piece only to a block that started, and nothing for an index that never did', function (): void {
        $pieces = ToolPlan::none();

        ToolPlan::add($pieces, 3, 'never started');
        $before = $pieces;
        ToolPlan::start($pieces, 3, 'thinking', 'Hm');
        ToolPlan::add($pieces, 3, '.');

        expect($before)->toBe(ToolPlan::none())
            ->and(ToolPlan::sealable($pieces))->toBe(['content' => '[{"type":"thinking","thinking":"Hm."}]']);
    });

    it('writes the blocks in the order of their index, whatever order they started in', function (): void {
        $pieces = ToolPlan::none();
        ToolPlan::start($pieces, 2, 'text', 'lo.');
        ToolPlan::start($pieces, 0, 'thinking', 'Hm.');
        ToolPlan::start($pieces, 1, 'text', 'Hel');

        expect(ToolPlan::sealable($pieces))->toBe(['content' => '[{"type":"thinking","thinking":"Hm."},{"type":"text","text":"Hel"},{"type":"text","text":"lo."}]']);
    });

    it('tells what is kept from what is not, as text', function (): void {
        expect(ToolPlan::plan('"I will look."'))->toBe('"I will look."')
            ->and(ToolPlan::plan(' "I will look." '))->toBe('"I will look."')
            ->and(ToolPlan::plan('""'))->toBeNull()
            ->and(ToolPlan::plan('7'))->toBeNull()
            ->and(ToolPlan::plan('"open'))->toBeNull()
            ->and(ToolPlan::plan(''))->toBeNull()
            ->and(ToolPlan::content('[{"type":"text","text":"Hello."}]'))->toBeNull()
            ->and(ToolPlan::content('[]'))->toBeNull()
            ->and(ToolPlan::content('{"type":"thinking","thinking":"t"}'))->toBeNull()
            ->and(ToolPlan::content('[{"type":"thinking","thinking":"t"}'))->toBeNull()
            ->and(ToolPlan::content('[ {"type" : "thinking","thinking":"t"} ]'))->toBe('[{"type":"thinking","thinking":"t"}]')
            ->and(ToolPlan::holdsThinking([['type' => 'text', 'text' => 'x'], ['type' => 'thinking', 'thinking' => 't']]))->toBeTrue()
            ->and(ToolPlan::holdsThinking([['type' => 'text', 'text' => 'thinking']]))->toBeFalse()
            ->and(ToolPlan::holdsThinking(['a' => ['type' => 'thinking']]))->toBeFalse()
            ->and(ToolPlan::holdsThinking('thinking'))->toBeFalse()
            ->and(ToolPlan::agreeing([], ''))->toBeNull()
            ->and(ToolPlan::agreeing(['tool_plan' => '"p"'], 'any text: a plan says nothing of it'))->toBe(['tool_plan' => '"p"', 'content' => null]);
    });
});
