<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// The stream shapes the chat-completions adapter tolerates (docs/design/adapters.md, section 2.1): servers of this
// format cut a stream differently, and each of these is read to the same neutral events.

/**
 * The kinds of the events, in their order, by their short names.
 *
 * @param list<StreamEvent> $events
 * @return list<string>
 */
function kindsOf(array $events): array
{
    return array_map(fn(StreamEvent $event): string => substr((string) strrchr($event::class, '\\'), 1), $events);
}

/**
 * The events of a stream that answers a request with the tool "lookup".
 *
 * @param list<string> $chunks
 * @return list<StreamEvent>
 */
function eventsWithTools(array $chunks): array
{
    return ChatCompletions::eventsOf($chunks, [], new Request(ChatCompletions::MODEL, [Message::user('Where to?')], 30.0, [ChatCompletions::lookup()]));
}

/**
 * A piece of a tool call in a delta.
 *
 * @param array<string, mixed> $piece
 */
function callPiece(array $piece): string
{
    return ChatCompletions::chunk(['tool_calls' => [$piece]]);
}

const USAGE_CHUNK = 'data: {"id":"chatcmpl-example-1","object":"chat.completion.chunk","model":"example-chat-1-0001","choices":[],"usage":{"prompt_tokens":3,"completion_tokens":2,"total_tokens":5}}' . "\n\n";

describe('the text of a stream', function (): void {
    it('arrives in pieces, after a delta that holds only the role', function (): void {
        $events = ChatCompletions::eventsOf([
            ChatCompletions::chunk(['role' => 'assistant', 'content' => '']),
            ChatCompletions::chunk(['content' => 'Hel']),
            ChatCompletions::chunk(['content' => 'lo.']),
            ChatCompletions::chunk([], 'stop'),
            USAGE_CHUNK,
            ChatCompletions::DONE,
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(kindsOf($events))->toBe(['TextDelta', 'TextDelta', 'UsageReported', 'StreamFinished'])
            ->and($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop)
            ->and($answer->model)->toBe('example-chat-1-0001')
            ->and($answer->usage)->toEqual(new Usage(3, 2))
            ->and($answer->opaque)->toBeNull();
    });

    it('is read past a delta that holds only a role, no delta at all, a null and fields it does not know', function (): void {
        $events = ChatCompletions::eventsOf([
            ChatCompletions::chunk(['role' => 'assistant']),
            ChatCompletions::chunk(['role' => 'assistant', 'content' => null, 'refusal' => null, 'tool_calls' => null]),
            ChatCompletions::data(['id' => 'chatcmpl-example-1', 'choices' => [['index' => 0, 'finish_reason' => null, 'content_filter_results' => ['hate' => ['filtered' => false]], 'content_filter_offsets' => ['check_offset' => 0]]]]),
            ChatCompletions::data(['id' => 'chatcmpl-example-1', 'choices' => [['index' => 0, 'delta' => null]]]),
            ChatCompletions::data(['id' => 'chatcmpl-example-1', 'choices' => [['index' => 0, 'delta' => 'a word']]]),
            ChatCompletions::data(['id' => 'chatcmpl-example-1', 'choices' => ['a word']]),
            ChatCompletions::data(['id' => 'chatcmpl-example-1', 'choices' => 'none']),
            ChatCompletions::chunk(['content' => 'Hello.'], null, ['obfuscation' => 'aB3xY', 'stats' => ['tokens_per_second' => 31.5], 'system_fingerprint' => 'fp_example']),
            ChatCompletions::chunk(['content' => 7]),
            ChatCompletions::chunk([], 'stop'),
            ChatCompletions::DONE,
        ]);

        expect(kindsOf($events))->toBe(['TextDelta', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });

    it('skips a chunk without a choice, also the first one with an empty id and an empty model', function (): void {
        $events = ChatCompletions::eventsOf([
            ChatCompletions::data(['id' => '', 'object' => '', 'created' => 0, 'model' => '', 'choices' => [], 'prompt_filter_results' => [['prompt_index' => 0, 'content_filter_results' => []]]]),
            ChatCompletions::data(['id' => '', 'object' => '', 'created' => 0, 'model' => '', 'choices' => [], 'prompt_annotations' => []]),
            ChatCompletions::chunk(['role' => 'assistant', 'content' => 'Hello.']),
            ChatCompletions::chunk([], 'stop'),
            ChatCompletions::DONE,
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->text)->toBe('Hello.')
            ->and($answer->model)->toBe('example-chat-1-0001');
    });

    it('names the model of the request when no chunk names one', function (): void {
        $events = ChatCompletions::eventsOf([ChatCompletions::data(['choices' => [['delta' => ['content' => 'Hello.'], 'finish_reason' => 'stop']]])]);

        expect(Talk::finishedIn($events)->answer->model)->toBe(ChatCompletions::MODEL);
    });

    it('takes a delta whose content is a list of entries as its text entries', function (): void {
        $events = ChatCompletions::eventsOf([
            ChatCompletions::chunk(['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'A thought.']]]]]),
            ChatCompletions::chunk(['content' => [['type' => 'thinking', 'thinking' => [], 'closed' => true], ['type' => 'text', 'text' => 'Hel']]]),
            ChatCompletions::chunk(['content' => 'lo.'], 'stop'),
            ChatCompletions::DONE,
        ]);

        expect(kindsOf($events))->toBe(['TextDelta', 'TextDelta', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });

    it('keeps the reasoning of a delta out of the text, under either of its two names', function (): void {
        $events = ChatCompletions::eventsOf([
            ChatCompletions::chunk(['role' => 'assistant', 'reasoning_content' => 'The user ']),
            ChatCompletions::chunk(['reasoning_content' => 'greets.']),
            ChatCompletions::chunk(['reasoning' => 'So greet back.']),
            ChatCompletions::chunk(['content' => 'Hello.']),
            ChatCompletions::chunk([], 'stop'),
            ChatCompletions::DONE,
        ]);

        expect(kindsOf($events))->toBe(['TextDelta', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.')
            ->and(Talk::finishedIn($events)->answer->opaque)->toBeNull();
    });

    it('skips a line that is no JSON object, a comment and a line of another field', function (): void {
        $events = ChatCompletions::eventsOf([
            ": keep-alive\n\n",
            "data: not json\n\n",
            "data: 7\n\n",
            "data: \"Hello.\"\n\n",
            "event: ping\ndata: {}\n\n",
            "retry: 3000\n\n",
            ChatCompletions::chunk(['content' => 'Hello.'], 'stop'),
            ChatCompletions::DONE,
        ]);

        expect(kindsOf($events))->toBe(['TextDelta', 'StreamFinished']);
    });
});

describe('the end of a stream', function (): void {
    it('is its last line, or the end of the body when that line is missing', function (array $tail): void {
        $events = ChatCompletions::eventsOf([ChatCompletions::chunk(['content' => 'Hello.']), ChatCompletions::chunk([], 'stop'), ...$tail]);
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop);
    })->with([
        'with its last line' => [[ChatCompletions::DONE]],
        'without it' => [[]],
        'with the usage and without the last line' => [[USAGE_CHUNK]],
        'with a last line that has no blank line after it' => [['data: [DONE]']],
        'with spaces around the last line' => [["data:  [DONE] \n\n"]],
    ]);

    it('is the end of the body also for a last chunk that is not closed by a blank line', function (): void {
        $events = ChatCompletions::eventsOf([ChatCompletions::chunk(['content' => 'Hel']), rtrim(ChatCompletions::chunk(['content' => 'lo.'], 'stop'))]);

        expect(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });

    it('returns the text of a stream that ends without a finish reason and without its last line, with the reason "other"', function (): void {
        $events = ChatCompletions::eventsOf([ChatCompletions::chunk(['role' => 'assistant', 'content' => 'Hel']), ChatCompletions::chunk(['content' => 'lo.'])]);
        $answer = Talk::finishedIn($events)->answer;

        expect(kindsOf($events))->toBe(['TextDelta', 'TextDelta', 'StreamFinished'])
            ->and($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Other);
    });

    it('ends as "other" when the server gives the word for tool calls and the stream held none', function (): void {
        $events = ChatCompletions::eventsOf([ChatCompletions::chunk(['content' => 'Hello.']), ChatCompletions::chunk([], 'tool_calls'), ChatCompletions::DONE]);

        expect(Talk::finishedIn($events)->answer->finishReason)->toBe(FinishReason::Other);
    });

    it('keeps the answer of a chunk that also holds an error member: an error counts only in a chunk without a choice', function (): void {
        $events = ChatCompletions::eventsOf([
            ChatCompletions::chunk(['content' => 'Hello.'], 'stop', ['error' => ['message' => 'A warning of the server.', 'type' => 'server_error']]),
            ChatCompletions::DONE,
        ]);

        expect(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });

    it('reads nothing after the last line', function (): void {
        $events = ChatCompletions::eventsOf([
            ChatCompletions::chunk(['content' => 'Hello.'], 'stop'),
            ChatCompletions::DONE,
            ChatCompletions::chunk(['content' => ' And more.'], 'length'),
            ChatCompletions::data(['error' => ['message' => 'late', 'type' => 'server_error']]),
        ]);

        expect(kindsOf($events))->toBe(['TextDelta', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });

    it('is cut off at the token limit, with what arrived, also before any text', function (): void {
        $cut = Talk::finishedIn(ChatCompletions::eventsOf([ChatCompletions::chunk(['content' => '{"city":"Par']), ChatCompletions::chunk([], 'length'), ChatCompletions::DONE]))->answer;
        $nothing = Talk::finishedIn(ChatCompletions::eventsOf([ChatCompletions::chunk(['reasoning_content' => 'A long thought.']), ChatCompletions::chunk([], 'length'), ChatCompletions::DONE]))->answer;

        expect($cut->finishReason)->toBe(FinishReason::Length)
            ->and($cut->text)->toBe('{"city":"Par')
            ->and($nothing->finishReason)->toBe(FinishReason::Length)
            ->and($nothing->text)->toBe('');
    });

    it('is a failed event when the stream gave nothing at all', function (array $chunks): void {
        $events = ChatCompletions::eventsOf($chunks);

        // The usage a stream told is reported before the end shows that the answer holds nothing.
        expect(array_values(array_diff(kindsOf($events), ['UsageReported'])))->toBe(['StreamFailed'])
            ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::ProviderError)
            ->and(Talk::failedIn($events)->error->httpStatus)->toBe(200)
            ->and(Talk::failedIn($events)->textSoFar)->toBe('');
    })->with([
        'only the last line' => [[ChatCompletions::DONE]],
        'only a comment' => [[": nothing to say\n\n"]],
        'a role and an end' => [[ChatCompletions::chunk(['role' => 'assistant', 'content' => '']), ChatCompletions::chunk([], 'stop'), ChatCompletions::DONE]],
        'only the usage' => [[USAGE_CHUNK, ChatCompletions::DONE]],
        'a body that is no event stream' => [['{"choices":[{"message":{"content":"Hello."}}]}']],
    ]);

    it('is a failed event with the text so far when the server ends the answer with the word "error"', function (): void {
        $events = ChatCompletions::eventsOf([ChatCompletions::chunk(['content' => 'Half an ans']), ChatCompletions::chunk([], 'error'), ChatCompletions::DONE]);
        $failed = Talk::failedIn($events);

        expect(kindsOf($events))->toBe(['TextDelta', 'StreamFailed'])
            ->and($failed->error->reason)->toBe(ErrorReason::ProviderError)
            ->and($failed->error->providerCode)->toBe('error')
            ->and($failed->textSoFar)->toBe('Half an ans');
    });

    it('is a failed event with the code of the chunk when a chunk holds an error, before or after the first text', function (array $before, string $chunk, ?string $code): void {
        $events = ChatCompletions::eventsOf([...$before, $chunk, ChatCompletions::chunk(['content' => 'never read']), ChatCompletions::DONE]);
        $failed = Talk::failedIn($events);

        expect(kindsOf($events))->toBe([...array_fill(0, count($before), 'TextDelta'), 'StreamFailed'])
            ->and($failed->error->reason)->toBe(ErrorReason::ProviderError)
            ->and($failed->error->providerCode)->toBe($code)
            ->and($failed->error->detail)->toBe('the stream holds an error')
            ->and($failed->error->getMessage())->not->toContain('overloaded right now');
    })->with([
        'an error object first' => [[], ChatCompletions::data(['error' => ['message' => 'The server is overloaded right now.', 'type' => 'server_error', 'code' => 'server_is_overloaded']]), 'server_is_overloaded'],
        'an error object after text' => [[ChatCompletions::chunk(['content' => 'Hel'])], ChatCompletions::data(['error' => ['message' => 'The server is overloaded right now.', 'type' => 'server_error', 'code' => null]]), 'server_error'],
        'a flat error' => [[], ChatCompletions::data(['error' => 'model_timeout', 'code' => 504, 'detail' => 'The server is overloaded right now.']), 'model_timeout'],
        'an error without a word' => [[], ChatCompletions::data(['error' => ['message' => 'The server is overloaded right now.']]), null],
    ]);
});

describe('the usage of a stream', function (): void {
    it('is reported once, with the last value seen, wherever it arrives', function (array $chunks, Usage $usage): void {
        $events = ChatCompletions::eventsOf([...$chunks, ChatCompletions::DONE]);

        expect(array_count_values(kindsOf($events))['UsageReported'])->toBe(1)
            ->and(kindsOf($events)[count($events) - 2])->toBe('UsageReported')
            ->and(Talk::finishedIn($events)->answer->usage)->toEqual($usage);

        foreach ($events as $event) {
            if ($event instanceof UsageReported) {
                expect($event->usage)->toEqual($usage);
            }
        }
    })->with([
        'in an extra chunk without a choice' => [[ChatCompletions::chunk(['content' => 'Hello.'], 'stop', ['usage' => null]), USAGE_CHUNK], new Usage(3, 2)],
        'in the chunk that ends the answer' => [[ChatCompletions::chunk(['content' => 'Hello.'], 'stop', ['usage' => ['prompt_tokens' => 8, 'completion_tokens' => 2]])], new Usage(8, 2)],
        'inside the choice' => [[ChatCompletions::data(['choices' => [['index' => 0, 'delta' => ['content' => 'Hello.'], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 2]]]])], new Usage(8, 2)],
        'inside the choice and in an extra chunk: not added up' => [[ChatCompletions::data(['choices' => [['index' => 0, 'delta' => ['content' => 'Hello.'], 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2]]], 'usage' => null]), USAGE_CHUNK], new Usage(3, 2)],
        'on every chunk, with a rising count' => [[
            ChatCompletions::chunk(['content' => 'Hel'], null, ['usage' => ['prompt_tokens' => 8, 'completion_tokens' => 1]]),
            ChatCompletions::chunk(['content' => 'lo.'], null, ['usage' => ['prompt_tokens' => 8, 'completion_tokens' => 2]]),
            ChatCompletions::chunk([], 'stop', ['usage' => ['prompt_tokens' => 8, 'completion_tokens' => 3]]),
        ], new Usage(8, 3)],
        'in a chunk that holds only the usage, with nothing else in it' => [[ChatCompletions::chunk(['content' => 'Hello.'], 'stop'), ChatCompletions::data(['usage' => ['prompt_tokens' => 4, 'completion_tokens' => 1]])], new Usage(4, 1)],
        'followed by a chunk whose usage is null' => [[USAGE_CHUNK, ChatCompletions::chunk(['content' => 'Hello.'], 'stop', ['usage' => null])], new Usage(3, 2)],
    ]);

    it('is not reported, and unknown in the answer, when the stream tells none', function (array $options): void {
        $events = ChatCompletions::eventsOf([ChatCompletions::chunk(['content' => 'Hello.'], 'stop', ['usage' => null]), ChatCompletions::DONE], $options);

        expect(kindsOf($events))->toBe(['TextDelta', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->usage)->toEqual(new Usage(null, null));
    })->with([
        'a server that was asked for it' => [[]],
        'a server that is not asked' => [['stream_usage' => 'unasked']],
    ]);
});

describe('a tool call in a stream', function (): void {
    it('has its argument pieces joined per index before it is complete', function (): void {
        $events = eventsWithTools([
            ChatCompletions::chunk(['role' => 'assistant', 'content' => null]),
            callPiece(['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '']]),
            callPiece(['index' => 0, 'function' => ['arguments' => '{"ci']]),
            callPiece(['index' => 0, 'function' => ['arguments' => 'ty":"Pa']]),
            callPiece(['index' => 0, 'function' => ['arguments' => 'ris"}']]),
            ChatCompletions::chunk([], 'tool_calls'),
            USAGE_CHUNK,
            ChatCompletions::DONE,
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(kindsOf($events))->toBe(['ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished'])
            ->and($events[0])->toEqual(new ToolCallStarted(0, 'call_a', 'lookup'))
            ->and($events[1])->toBeInstanceOf(ToolCallCompleted::class)
            ->and($answer->toolCalls)->toHaveCount(1)
            ->and($answer->toolCalls[0]->id)->toBe('call_a')
            ->and($answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris'])
            ->and($answer->toolCalls[0]->rawArguments)->toBe('{"city":"Paris"}')
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls);
    });

    it('keeps the pieces of two calls apart, also when they arrive mixed and in one delta', function (): void {
        $events = eventsWithTools([
            ChatCompletions::chunk(['tool_calls' => [
                ['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city":']],
                ['index' => 1, 'id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"ci']],
            ]]),
            callPiece(['index' => 1, 'function' => ['arguments' => 'ty":"Rome"}']]),
            callPiece(['index' => 0, 'function' => ['arguments' => '"Paris"}']]),
            ChatCompletions::chunk([], 'tool_calls'),
            ChatCompletions::DONE,
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(kindsOf($events))->toBe(['ToolCallStarted', 'ToolCallStarted', 'ToolCallCompleted', 'ToolCallCompleted', 'StreamFinished'])
            ->and($events[0])->toEqual(new ToolCallStarted(0, 'call_a', 'lookup'))
            ->and($events[1])->toEqual(new ToolCallStarted(1, 'call_b', 'lookup'))
            ->and($answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris'])
            ->and($answer->toolCalls[1]->arguments)->toBe(['city' => 'Rome']);
    });

    it('numbers the calls by the order they appear in, whatever index the server gives them', function (): void {
        $events = eventsWithTools([
            callPiece(['index' => 5, 'id' => 'call_a', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']]),
            callPiece(['index' => 2, 'id' => 'call_b', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Rome"}']]),
            ChatCompletions::chunk([], 'tool_calls'),
        ]);

        expect($events[0])->toEqual(new ToolCallStarted(0, 'call_a', 'lookup'))
            ->and($events[1])->toEqual(new ToolCallStarted(1, 'call_b', 'lookup'))
            ->and(array_map(fn($call): string => $call->id, Talk::finishedIn($events)->answer->toolCalls))->toBe(['call_a', 'call_b']);
    });

    it('takes a call that arrives whole in one chunk, with its arguments as a text or as a map', function (mixed $arguments): void {
        $events = eventsWithTools([
            callPiece(['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => $arguments]]),
            ChatCompletions::chunk([], 'stop'),
            ChatCompletions::DONE,
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris'])
            ->and($answer->toolCalls[0]->rawArguments)->toBe('{"city":"Paris"}')
            // The server said the answer ended by itself: an answer that holds a tool call ends for tool calls.
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls);
    })->with([
        'a text' => ['{"city":"Paris"}'],
        'a map' => [['city' => 'Paris']],
    ]);

    it('tells calls without an index apart by their id, and joins a piece without both to the last call', function (): void {
        $events = eventsWithTools([
            callPiece(['id' => 'call_a', 'function' => ['name' => 'lookup', 'arguments' => '{"city":']]),
            callPiece(['function' => ['arguments' => '"Paris"}']]),
            callPiece(['id' => 'call_b', 'function' => ['name' => 'lookup', 'arguments' => '{"city":']]),
            callPiece(['function' => ['arguments' => '"Rome"}']]),
            callPiece(['id' => 'call_a', 'function' => ['arguments' => '']]),
            ChatCompletions::chunk([], 'tool_calls'),
        ]);
        $calls = Talk::finishedIn($events)->answer->toolCalls;

        expect($calls)->toHaveCount(2)
            ->and($calls[0]->arguments)->toBe(['city' => 'Paris'])
            ->and($calls[1]->arguments)->toBe(['city' => 'Rome']);
    });

    it('gives a call an id of its own when the server gives none, the same in both of its events', function (): void {
        $events = eventsWithTools([
            callPiece(['index' => 0, 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']]),
            callPiece(['index' => 1, 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Rome"}']]),
            ChatCompletions::chunk([], 'tool_calls'),
        ]);

        expect($events[0])->toEqual(new ToolCallStarted(0, 'call_0', 'lookup'))
            ->and($events[1])->toEqual(new ToolCallStarted(1, 'call_1', 'lookup'))
            ->and(array_map(fn($call): string => $call->id, Talk::finishedIn($events)->answer->toolCalls))->toBe(['call_0', 'call_1']);
    });

    it('announces a call when its arguments begin, with the id and the name that came before them', function (): void {
        $events = eventsWithTools([
            callPiece(['index' => 0, 'function' => ['name' => 'look']]),
            // A name in two pieces is joined, and an id that comes after the name is the id of the call.
            callPiece(['index' => 0, 'function' => ['name' => 'up']]),
            callPiece(['index' => 0, 'id' => 'call_a']),
            callPiece(['index' => 0, 'function' => ['arguments' => '{"city":']]),
            callPiece(['index' => 0, 'function' => ['arguments' => '"Paris"}']]),
            ChatCompletions::chunk([], 'tool_calls'),
        ]);
        $call = Talk::finishedIn($events)->answer->toolCalls[0];

        expect(kindsOf($events))->toBe(['ToolCallStarted', 'ToolCallCompleted', 'StreamFinished'])
            ->and($events[0])->toEqual(new ToolCallStarted(0, 'call_a', 'lookup'))
            ->and($call->id)->toBe('call_a')
            ->and($call->name)->toBe('lookup')
            ->and($call->arguments)->toBe(['city' => 'Paris']);
    });

    it('keeps the id and the name a call was announced with: what comes later for them is not taken', function (): void {
        $events = eventsWithTools([
            callPiece(['index' => 0, 'function' => ['name' => 'lookup', 'arguments' => '{"city":']]),
            // The call was announced with an id of the adapter; an id that arrives now would change it.
            callPiece(['index' => 0, 'id' => 'call_late', 'function' => ['arguments' => '"Paris"}']]),
            ChatCompletions::chunk([], 'tool_calls'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(kindsOf($events))->toBe(['ToolCallStarted', 'ToolCallCompleted', 'StreamFinished'])
            ->and($events[0])->toEqual(new ToolCallStarted(0, 'call_0', 'lookup'))
            ->and($answer->toolCalls)->toHaveCount(1)
            ->and($answer->toolCalls[0]->id)->toBe('call_0')
            ->and($answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris']);
    });

    it('does not join two whole calls that say the same index, or no index and no id', function (array $first, array $second, array $ids): void {
        $events = eventsWithTools([callPiece($first), callPiece($second), ChatCompletions::chunk([], 'tool_calls')]);
        $calls = Talk::finishedIn($events)->answer->toolCalls;

        expect(kindsOf($events))->toBe(['ToolCallStarted', 'ToolCallStarted', 'ToolCallCompleted', 'ToolCallCompleted', 'StreamFinished'])
            ->and(array_map(fn($call): string => $call->id, $calls))->toBe($ids)
            ->and($calls[0]->arguments)->toBe(['city' => 'Paris'])
            ->and($calls[1]->arguments)->toBe(['city' => 'Rome']);
    })->with([
        'the same index and two ids' => [
            ['index' => 0, 'id' => 'call_a', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']],
            ['index' => 0, 'id' => 'call_b', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Rome"}']],
            ['call_a', 'call_b'],
        ],
        'the same index and no id' => [
            ['index' => 0, 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']],
            ['index' => 0, 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Rome"}']],
            ['call_0', 'call_1'],
        ],
        'no index and no id' => [
            ['function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']],
            ['function' => ['name' => 'lookup', 'arguments' => '{"city":"Rome"}']],
            ['call_0', 'call_1'],
        ],
        'no index and no id, the arguments as maps' => [
            ['function' => ['name' => 'lookup', 'arguments' => ['city' => 'Paris']]],
            ['function' => ['name' => 'lookup', 'arguments' => ['city' => 'Rome']]],
            ['call_0', 'call_1'],
        ],
        'the same index, and the second id is one the adapter would make' => [
            ['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']],
            ['index' => 0, 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Rome"}']],
            ['call_1', 'call_1_2'],
        ],
    ]);

    it('gives a call without an id one that no other call of the stream has', function (): void {
        $events = eventsWithTools([
            callPiece(['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']]),
            callPiece(['index' => 1, 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Rome"}']]),
            ChatCompletions::chunk([], 'tool_calls'),
        ]);

        expect($events[1])->toEqual(new ToolCallStarted(1, 'call_1_2', 'lookup'))
            ->and(array_map(fn($call): string => $call->id, Talk::finishedIn($events)->answer->toolCalls))->toBe(['call_1', 'call_1_2']);
    });

    it('announces a call without a name at the end, just before it is complete', function (): void {
        $events = eventsWithTools([callPiece(['index' => 0, 'id' => 'call_a', 'function' => ['arguments' => '{}']]), ChatCompletions::chunk([], 'tool_calls')]);

        expect(kindsOf($events))->toBe(['ToolCallStarted', 'ToolCallCompleted', 'StreamFinished'])
            ->and($events[0])->toEqual(new ToolCallStarted(0, 'call_a', ''));
    });

    it('returns a call whose joined arguments are no JSON object as not readable, in its event and in the answer', function (): void {
        $events = eventsWithTools([
            callPiece(['index' => 0, 'id' => 'call_a', 'function' => ['name' => 'lookup', 'arguments' => '{"city":']]),
            callPiece(['index' => 0, 'function' => ['arguments' => ' "Par']]),
            ChatCompletions::chunk([], 'length'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->toolCalls[0]->isReadable())->toBeFalse()
            ->and($answer->toolCalls[0]->rawArguments)->toBe('{"city": "Par')
            // Cut off at the token limit: the one finish reason that a tool call does not replace.
            ->and($answer->finishReason)->toBe(FinishReason::Length);
    });

    it('may stand beside text, and reads past a piece that is no piece', function (): void {
        $events = eventsWithTools([
            ChatCompletions::chunk(['content' => 'One moment.']),
            ChatCompletions::chunk(['tool_calls' => ['no piece', null, 7]]),
            ChatCompletions::chunk(['tool_calls' => 'none']),
            callPiece(['index' => 0, 'id' => 'call_a', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']]),
            callPiece(['index' => 0, 'function' => 'no function']),
            ChatCompletions::chunk([], 'tool_calls'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(kindsOf($events))->toBe(['TextDelta', 'ToolCallStarted', 'ToolCallCompleted', 'StreamFinished'])
            ->and($answer->text)->toBe('One moment.')
            ->and($answer->toolCalls)->toHaveCount(1);
    });
});

describe('a refusal and withheld output in a stream', function (): void {
    it('gives the words of a refusal as the text when there is no other, and ends as a refusal', function (): void {
        $events = ChatCompletions::eventsOf([
            ChatCompletions::chunk(['role' => 'assistant', 'content' => null, 'refusal' => 'I cannot ']),
            ChatCompletions::chunk(['refusal' => 'help with that.']),
            ChatCompletions::chunk([], 'stop'),
            ChatCompletions::DONE,
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(kindsOf($events))->toBe(['TextDelta', 'StreamFinished'])
            ->and($events[0])->toEqual(new TextDelta('I cannot help with that.'))
            ->and($answer->text)->toBe('I cannot help with that.')
            ->and($answer->finishReason)->toBe(FinishReason::Refusal);
    });

    it('takes an empty refusal for no refusal: a stream that holds nothing else fails, one with text ends by itself', function (): void {
        $nothing = ChatCompletions::eventsOf([ChatCompletions::chunk(['role' => 'assistant', 'content' => null, 'refusal' => '']), ChatCompletions::chunk([], 'stop'), ChatCompletions::DONE]);
        $text = ChatCompletions::eventsOf([ChatCompletions::chunk(['content' => 'Hello.', 'refusal' => '']), ChatCompletions::chunk([], 'stop'), ChatCompletions::DONE]);

        expect(Talk::failedIn($nothing)->error->reason)->toBe(ErrorReason::ProviderError)
            ->and(Talk::finishedIn($text)->answer->finishReason)->toBe(FinishReason::Stop);
    });

    it('keeps the content as the text when a stream has both', function (): void {
        $events = ChatCompletions::eventsOf([ChatCompletions::chunk(['content' => 'No.']), ChatCompletions::chunk(['refusal' => 'I cannot help with that.']), ChatCompletions::chunk([], 'stop')]);
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->text)->toBe('No.')
            ->and($answer->finishReason)->toBe(FinishReason::Refusal);
    });

    it('ends with an answer when the output was withheld, with the part that arrived or with none', function (array $chunks, string $text): void {
        $events = ChatCompletions::eventsOf([...$chunks, ChatCompletions::chunk([], 'content_filter'), ChatCompletions::DONE]);
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->text)->toBe($text)
            ->and($answer->finishReason)->toBe(FinishReason::ContentFilter);
    })->with([
        'nothing arrived' => [[ChatCompletions::chunk(['role' => 'assistant', 'content' => ''])], ''],
        'a part arrived' => [[ChatCompletions::chunk(['content' => 'The first part'])], 'The first part'],
    ]);

    it('fails where content_filter means only that the reason was not understood, and nothing arrived', function (): void {
        $events = ChatCompletions::eventsOf([ChatCompletions::chunk(['content' => '']), ChatCompletions::chunk([], 'content_filter')], ['finish_content_filter' => 'unknown']);

        expect(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::ProviderError);
    });
});

describe('inline reasoning in a stream', function (): void {
    it('is cut from the text piece by piece, wherever the stream cuts it', function (array $pieces, string $text): void {
        $chunks = array_map(fn(string $piece): string => ChatCompletions::chunk(['content' => $piece]), $pieces);
        $events = ChatCompletions::eventsOf([...$chunks, ChatCompletions::chunk([], 'stop'), ChatCompletions::DONE], ['inline_reasoning' => 'strip']);

        $joined = '';
        foreach ($events as $event) {
            if ($event instanceof TextDelta) {
                $joined .= $event->text;
            }
        }
        $answer = $events[count($events) - 1] instanceof StreamFinished ? $events[count($events) - 1]->answer->text : null;

        expect($joined)->toBe($text)
            ->and($answer)->toBe($text)
            // The same text as the whole answer gives.
            ->and(ChatCompletions::answerTo(ChatCompletions::answered(['role' => 'assistant', 'content' => implode('', $pieces)]), ['inline_reasoning' => 'strip'])->text)->toBe($text);
    })->with([
        'in one piece' => [["<think>A thought.</think>\n\nHello."], 'Hello.'],
        'cut inside both marks' => [['<th', 'ink>A tho', 'ught.</th', "ink>\n", "\nHel", 'lo.'], 'Hello.'],
        'after white space' => [["\n ", '<think>', 'A thought.', '</think>', ' ', 'Hello.'], 'Hello.'],
        'no block' => [['Hel', 'lo.'], 'Hello.'],
        'no block, and white space first' => [[' ', 'Hello.'], ' Hello.'],
        'a text that only begins like the mark' => [['<', 'thin', 'g>Hello.'], '<thing>Hello.'],
        'a block later in the text is left' => [['Hello. ', '<think>later</think>'], 'Hello. <think>later</think>'],
        'only the first block is cut' => [['<think>one</think>', '<think>two</think>Hello.'], '<think>two</think>Hello.'],
        'a block that never closes is left as it is' => [['<think>A thought', ' without an end'], '<think>A thought without an end'],
    ]);

    it('leaves the text alone on a server that keeps it', function (): void {
        $events = ChatCompletions::eventsOf([ChatCompletions::chunk(['content' => '<think>A thought.</think>Hello.'], 'stop')]);

        expect(Talk::finishedIn($events)->answer->text)->toBe('<think>A thought.</think>Hello.');
    });
});
