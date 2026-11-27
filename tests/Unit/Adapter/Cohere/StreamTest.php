<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatV2;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// How the Chat v2 adapter reads a stream (docs/design/adapters.md, section 4.3): named events, text from the pieces
// of a text block, a tool call from its start to its end, the finish reason and the usage in the last event, and
// an error there as an error of the provider. The shapes the provider file does not pin down are read tolerantly.

/**
 * The classes of the events of a stream, without their namespace.
 *
 * @param list<StreamEvent> $events
 * @return list<string>
 */
function chatV2EventKinds(array $events): array
{
    return array_map(fn(StreamEvent $event): string => substr((string) strrchr($event::class, '\\'), 1), $events);
}

describe('a stream of the Chat v2 adapter', function (): void {
    it('gives the text of every piece as it arrives, the usage once, and the answer last', function (): void {
        $events = ChatV2::eventsOf(ChatV2::saying('Hel', 'lo', '.'));
        $answer = Talk::finishedIn($events)->answer;

        expect(chatV2EventKinds($events))->toBe(['TextDelta', 'TextDelta', 'TextDelta', 'UsageReported', 'StreamFinished'])
            ->and($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop)
            ->and($answer->model)->toBe(ChatV2::MODEL)
            ->and($answer->usage->inputTokens)->toBe(3)
            ->and($answer->usage->outputTokens)->toBe(2)
            ->and($answer->opaque)->toBeNull();
    });

    it('keeps thinking and the tool plan out of the text and gives no event for either', function (): void {
        $events = ChatV2::eventsOf([
            ChatV2::started(),
            ChatV2::blockStart(0, 'thinking'), ChatV2::piece('The user ', 0, 'thinking'), ChatV2::piece('greets me.', 0, 'thinking'), ChatV2::blockEnd(0),
            ChatV2::blockStart(1), ChatV2::piece('Hello.', 1), ChatV2::blockEnd(1),
            ChatV2::planPiece('I will '), ChatV2::planPiece('look.'),
            ChatV2::ended(),
        ]);

        expect(chatV2EventKinds($events))->toBe(['TextDelta', 'UsageReported', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });

    it('starts a tool call when its start arrives, joins the pieces of its arguments, and completes it at its end', function (): void {
        $events = ChatV2::eventsOf([
            ChatV2::started(),
            ChatV2::planPiece('I will look up two cities.'),
            ChatV2::callStart(0, 'lookup_a'), ChatV2::callPiece('{"ci'), ChatV2::callPiece('ty":"Pa'), ChatV2::callPiece('ris"}'), ChatV2::callEnd(0),
            ...ChatV2::calling(1, 'lookup_b', ['{"city":"Rome"}'], 'book'),
            ChatV2::ended('TOOL_CALL'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(chatV2EventKinds($events))->toBe(['ToolCallStarted', 'ToolCallCompleted', 'ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished'])
            ->and($events[0])->toEqual(new ToolCallStarted(0, 'lookup_a', 'lookup'))
            ->and($events[1])->toEqual(new ToolCallCompleted(0, ToolCall::decoded('lookup_a', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')))
            ->and($events[2])->toEqual(new ToolCallStarted(1, 'lookup_b', 'book'))
            ->and($answer->toolCalls)->toHaveCount(2)
            ->and($answer->text)->toBe('')
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls);
    });

    it('reads arguments that begin in the start of a call, and returns arguments that are no JSON object as a call that cannot be read', function (): void {
        $events = ChatV2::eventsOf([
            ChatV2::started(),
            ChatV2::callStart(0, 'lookup_a', 'lookup', '{"city":'), ChatV2::callPiece('"Paris"}'), ChatV2::callEnd(0),
            ChatV2::callStart(1, 'lookup_b'), ChatV2::callPiece('{"city": "Par', 1), ChatV2::callEnd(1),
            ChatV2::callStart(2, 'lookup_c'), ChatV2::callEnd(2),
            ChatV2::ended('MAX_TOKENS'),
        ]);
        $calls = Talk::finishedIn($events)->answer->toolCalls;

        expect($calls[0]->arguments)->toBe(['city' => 'Paris'])
            ->and($calls[1]->isReadable())->toBeFalse()
            ->and($calls[1]->rawArguments)->toBe('{"city": "Par')
            ->and($calls[2]->isReadable())->toBeFalse()
            ->and($calls[2]->rawArguments)->toBe('')
            ->and(Talk::finishedIn($events)->answer->finishReason)->toBe(FinishReason::Length);
    });

    it('gives a call without an id an id no earlier call of the stream has, the same in both events of the call', function (): void {
        $events = ChatV2::eventsOf([
            ChatV2::started(),
            ...ChatV2::calling(0, 'call_1', ['{}']),
            ...ChatV2::calling(1, null, ['{}']),
            ...ChatV2::calling(2, '', ['{}']),
            ChatV2::ended('TOOL_CALL'),
        ]);
        $ids = array_map(fn(ToolCall $call): string => $call->id, Talk::finishedIn($events)->answer->toolCalls);

        expect($ids)->toBe(['call_1', 'call_1_2', 'call_2'])
            ->and($events[2])->toEqual(new ToolCallStarted(1, 'call_1_2', 'lookup'))
            ->and($events[3])->toEqual(new ToolCallCompleted(1, ToolCall::decoded('call_1_2', 'lookup', [], '{}')));
    });

    it('gives a later call whose server id is an id the adapter made for an earlier call a suffix: an id that was handed on is final', function (): void {
        $events = ChatV2::eventsOf([
            ChatV2::started(),
            ...ChatV2::calling(0, null, ['{}']),
            ...ChatV2::calling(1, 'call_0', ['{}']),
            ...ChatV2::calling(2, 'call_0_2', ['{}']),
            ChatV2::ended('TOOL_CALL'),
        ]);
        $ids = array_map(fn(ToolCall $call): string => $call->id, Talk::finishedIn($events)->answer->toolCalls);

        // The second call's own id was one the adapter had made up; the third call's id is one the adapter made for
        // the second: each later call yields.
        expect($ids)->toBe(['call_0', 'call_0_2', 'call_0_2_2'])
            ->and($events[2])->toEqual(new ToolCallStarted(1, 'call_0_2', 'lookup'))
            ->and($events[3])->toEqual(new ToolCallCompleted(1, ToolCall::decoded('call_0_2', 'lookup', [], '{}')))
            ->and(array_unique($ids))->toHaveCount(3);
    });

    it('reads every finish reason of the last event', function (string $word, FinishReason $reason): void {
        expect(Talk::finishedIn(ChatV2::eventsOf([ChatV2::started(), ChatV2::blockStart(), ChatV2::piece('Hello.'), ChatV2::ended($word)]))->answer->finishReason)->toBe($reason);
    })->with([
        ['COMPLETE', FinishReason::Stop],
        ['STOP_SEQUENCE', FinishReason::Stop],
        ['MAX_TOKENS', FinishReason::Length],
        ['SOMETHING_NEW', FinishReason::Other],
    ]);
});

describe('an error in the last event of a stream of the Chat v2 adapter', function (): void {
    it('is an error of the provider, with the text and the calls that arrived before it, and is not tried again', function (): void {
        $transport = (new FakeTransport())
            ->willStream(200, [], ...[ChatV2::started(), ChatV2::blockStart(), ChatV2::piece('Hel'), ...ChatV2::calling(), ChatV2::ended('ERROR', ['error' => 'The model could not finish the generation.'])])
            ->willStream(200, [], ...ChatV2::saying());
        $events = Talk::events(ChatV2::gateway($transport, retry: new RetryPolicy([1.0], 60.0))->stream(ChatV2::request()));
        $failed = Talk::failedIn($events);

        expect($failed->error->reason)->toBe(ErrorReason::ProviderError)
            ->and($failed->error->providerCode)->toBe('error')
            ->and($failed->error->detail)->toBe('the stream ended with an error')
            ->and($failed->error->httpStatus)->toBe(200)
            ->and($failed->textSoFar)->toBe('Hel')
            ->and($failed->toolCallsSoFar)->toHaveCount(1)
            ->and($transport->requests())->toHaveCount(1);
        foreach ($events as $event) {
            expect($event)->not->toBeInstanceOf(StreamFinished::class);
        }
    });

    it('is one whatever finish reason stands beside it, with the code of the reason only where the reason itself says the generation failed', function (?string $finish, mixed $error, ?string $code): void {
        $failed = Talk::failedIn(ChatV2::eventsOf([ChatV2::started(), ChatV2::blockStart(), ChatV2::piece('Hello.'), ChatV2::ended($finish, ['error' => $error])]));

        expect($failed->error->reason)->toBe(ErrorReason::ProviderError)
            ->and($failed->error->providerCode)->toBe($code);
    })->with([
        'beside ERROR' => ['ERROR', 'internal error', 'error'],
        'beside TIMEOUT' => ['TIMEOUT', 'too slow', 'timeout'],
        'beside COMPLETE' => ['COMPLETE', 'internal error', null],
        'beside no finish reason' => [null, 'internal error', null],
        'an error that is an object' => ['COMPLETE', ['message' => 'internal error', 'code' => 'internal'], null],
    ]);

    it('is read from the finish reason alone too: a stream that ends as failed or as timed out without a message is an error of the provider with that word as its code', function (string $word, string $code): void {
        $failed = Talk::failedIn(ChatV2::eventsOf([ChatV2::started(), ChatV2::blockStart(), ChatV2::piece('Hello.'), ChatV2::ended($word)]));

        expect($failed->error->reason)->toBe(ErrorReason::ProviderError)
            ->and($failed->error->providerCode)->toBe($code)
            ->and($failed->error->detail)->toBe('the provider says it could not make the answer');
    })->with([['ERROR', 'error'], ['TIMEOUT', 'timeout']]);

    it('is not read from an error member that says nothing: the answer is given', function (mixed $error): void {
        $events = ChatV2::eventsOf([ChatV2::started(), ChatV2::blockStart(), ChatV2::piece('Hello.'), ChatV2::ended('COMPLETE', ['error' => $error])]);

        expect(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    })->with([[null], [''], [[]], [false]]);

    it('reads the error body of the format as an event without a type, which no page documents, as an error of the provider', function (): void {
        $failed = Talk::failedIn(ChatV2::eventsOf([ChatV2::started(), 'data: ' . ChatV2::errorBody('The server is too busy.') . "\n\n"]));

        expect($failed->error->reason)->toBe(ErrorReason::ProviderError)
            ->and($failed->error->detail)->toBe('the stream holds an error')
            ->and($failed->error->providerCode)->toBeNull();
    });
});

describe('the shapes of a stream the Chat v2 adapter tolerates', function (): void {
    it('reads past an event of a type it does not know, a citation, a debug event, a comment and data that is no JSON object', function (): void {
        $events = ChatV2::eventsOf([
            ": a comment\n\n",
            ChatV2::started(),
            ChatV2::event('debug', ['event_type' => 'prompt', 'prompt' => 'Say hello.']),
            ChatV2::blockStart(),
            ChatV2::piece('Hel'),
            ChatV2::event('citation-start', ['index' => 0, 'delta' => ['message' => ['citations' => ['start' => 0, 'end' => 3, 'text' => 'Hel', 'sources' => [], 'type' => 'TEXT_CONTENT', 'content_index' => 0]]]]),
            ChatV2::event('citation-end', ['index' => 0]),
            ChatV2::event('something-new', ['index' => 0, 'delta' => ['message' => ['content' => ['text' => 'not text'], 'tool_plan' => 'no plan']], 'message' => 'a word of a later version']),
            "event: content-delta\ndata: no JSON\n\n",
            "event: content-delta\ndata: [\"a list\"]\n\n",
            "event: content-delta\ndata: 7\n\n",
            ChatV2::piece('lo.'),
            ChatV2::blockEnd(),
            ChatV2::ended(),
        ]);

        expect(chatV2EventKinds($events))->toBe(['TextDelta', 'TextDelta', 'UsageReported', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.')
            ->and(Talk::finishedIn($events)->answer->opaque)->toBeNull();
    });

    it('reads past a piece and an end for a block or a call that never started, a second start of one that did, and a piece of another kind than its block', function (): void {
        $events = ChatV2::eventsOf([
            ChatV2::started(),
            ChatV2::piece('never started', 5),
            ChatV2::blockEnd(5),
            ChatV2::callPiece('{"never":"started"}', 5),
            ChatV2::callEnd(5),
            ChatV2::blockStart(),
            ChatV2::blockStart(0, 'thinking'),
            ChatV2::piece('Hello.'),
            ChatV2::piece('not of a text block', 0, 'thinking'),
            ChatV2::event('content-delta', ['delta' => ['message' => ['content' => ['text' => 'no index']]]]),
            ChatV2::event('content-delta', ['index' => 'zero', 'delta' => ['message' => ['content' => ['text' => 'an index that is a word']]]]),
            ChatV2::blockStart(1, 'image', 'no kind this adapter knows'),
            ChatV2::piece('of an unknown block', 1),
            ...ChatV2::calling(0, 'lookup_a', ['{}']),
            ChatV2::callStart(0, 'lookup_again', 'book'),
            ChatV2::callPiece('after its end'),
            ChatV2::callEnd(0),
            ChatV2::ended('TOOL_CALL'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(chatV2EventKinds($events))->toBe(['TextDelta', 'ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished'])
            ->and($answer->text)->toBe('Hello.')
            ->and($answer->toolCalls)->toEqual([ToolCall::decoded('lookup_a', 'lookup', [], '{}')]);
    });

    it('reads text that a block starts with, the type from the name line when the data names none, and an index written as a double', function (): void {
        $events = ChatV2::eventsOf([
            "event: message-start\ndata: {\"id\":\"synthetic-answer-1\"}\n\n",
            "event: content-start\ndata: {\"index\":0.0,\"delta\":{\"message\":{\"content\":{\"type\":\"text\",\"text\":\"Hel\"}}}}\n\n",
            "event: content-delta\ndata: {\"index\":0,\"delta\":{\"message\":{\"content\":{\"text\":\"lo.\"}}}}\n\n",
            "event: message-end\ndata: {\"delta\":{\"finish_reason\":\"COMPLETE\",\"usage\":{\"tokens\":{\"input_tokens\":12.0,\"output_tokens\":2.0}}}}\n\n",
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(chatV2EventKinds($events))->toBe(['TextDelta', 'TextDelta', 'UsageReported', 'StreamFinished'])
            ->and($answer->text)->toBe('Hello.')
            ->and($answer->usage->inputTokens)->toBe(12)
            ->and($answer->usage->outputTokens)->toBe(2);
    });

    it('reads a tool call whose events carry no index, and one that arrives as the first of a list', function (): void {
        $call = ChatV2::call('', 'lookup_a');
        $events = ChatV2::eventsOf([
            ChatV2::started(),
            ChatV2::event('tool-call-start', ['delta' => ['message' => ['tool_calls' => [$call]]]]),
            ChatV2::event('tool-call-delta', ['delta' => ['message' => ['tool_calls' => [['function' => ['arguments' => '{"city":"Paris"}']]]]]]),
            ChatV2::event('tool-call-end'),
            ChatV2::event('tool-call-start', ['delta' => ['message' => ['tool_calls' => ChatV2::call('{"city":"Rome"}', 'lookup_b')]]]),
            ChatV2::event('tool-call-end'),
            ChatV2::ended('TOOL_CALL'),
        ]);

        expect(Talk::finishedIn($events)->answer->toolCalls)->toEqual([
            ToolCall::decoded('lookup_a', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'),
            ToolCall::decoded('lookup_b', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}'),
        ]);
    });

    it('completes a tool call that never ended when the stream ends, with what arrived of it', function (): void {
        $events = ChatV2::eventsOf([ChatV2::started(), ChatV2::callStart(), ChatV2::callPiece('{"city":"Paris"}'), ChatV2::ended('TOOL_CALL')]);

        expect(chatV2EventKinds($events))->toBe(['ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris']);
    });

    it('reads nothing after the last event', function (): void {
        $events = ChatV2::eventsOf([...ChatV2::saying(), ChatV2::blockStart(1), ChatV2::piece(' And more.', 1), ChatV2::ended('ERROR', ['error' => 'late'])]);

        expect(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });

    it('ends a stream without its last event as a failure that holds the text and the calls that arrived, never as an answer', function (array $chunks, string $text, int $calls): void {
        $events = ChatV2::eventsOf($chunks);
        $last = $events[count($events) - 1];

        expect($last)->toBeInstanceOf(StreamFailed::class);
        assert($last instanceof StreamFailed);
        expect($last->error->reason)->toBe(ErrorReason::ProviderError)
            ->and($last->textSoFar)->toBe($text)
            ->and($last->toolCallsSoFar)->toHaveCount($calls);
        foreach ($events as $event) {
            expect($event)->not->toBeInstanceOf(StreamFinished::class);
        }
    })->with([
        'text and no end' => [[ChatV2::started(), ChatV2::blockStart(), ChatV2::piece('Hel'), ChatV2::piece('lo.'), ChatV2::blockEnd()], 'Hello.', 0],
        'a call that ended and one that did not' => [[ChatV2::started(), ...ChatV2::calling(), ChatV2::callStart(1, 'lookup_b'), ChatV2::callPiece('{"ci', 1)], '', 1],
        'no event at all' => [[": nothing\n\n"], '', 0],
        'an empty body' => [[''], '', 0],
        'lines that are no JSON' => [["data: no\n\n", "data: {\n\n"], '', 0],
    ]);

    it('fails a stream that ends by itself without text and without a call, and gives one that was cut off before any text as an answer', function (): void {
        $nothing = ChatV2::eventsOf([ChatV2::started(), ChatV2::ended()]);
        $cutOff = ChatV2::eventsOf([ChatV2::started(), ChatV2::ended('MAX_TOKENS')]);

        expect(Talk::failedIn($nothing)->error->reason)->toBe(ErrorReason::ProviderError)
            ->and(Talk::finishedIn($cutOff)->answer->finishReason)->toBe(FinishReason::Length)
            ->and(Talk::finishedIn($cutOff)->answer->text)->toBe('');
    });

    it('reports no usage for a stream that tells none', function (): void {
        $events = ChatV2::eventsOf([ChatV2::started(), ChatV2::blockStart(), ChatV2::piece('Hello.'), ChatV2::event('message-end', ['delta' => ['finish_reason' => 'COMPLETE']])]);

        expect(chatV2EventKinds($events))->toBe(['TextDelta', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->usage->inputTokens)->toBeNull()
            ->and(Talk::finishedIn($events)->answer->usage->outputTokens)->toBeNull();
    });

    it('reads events that are cut over several chunks of the transport, in fragments of any size', function (int $size): void {
        $stream = implode('', [ChatV2::started(), ChatV2::planPiece('I will look.'), ChatV2::blockStart(), ChatV2::piece('Hel'), ChatV2::piece('lo.'), ChatV2::blockEnd(), ...ChatV2::calling(), ChatV2::ended('TOOL_CALL')]);
        $events = ChatV2::eventsOf(str_split($stream, max(1, $size)));
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->text)->toBe('Hello.')
            ->and($answer->toolCalls)->toEqual([ToolCall::decoded('lookup_example01', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')])
            ->and($answer->opaque)->not->toBeNull();
        foreach ($events as $event) {
            expect($event)->not->toBeInstanceOf(StreamFailed::class);
        }
        expect(array_filter($events, fn(StreamEvent $event): bool => $event instanceof TextDelta))->toHaveCount(2);
    })->with([[1], [7], [64]]);
});
