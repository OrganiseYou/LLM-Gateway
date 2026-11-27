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
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// A stream in the Messages adapter (docs/design/adapters.md, section 4.1; docs/providers/anthropic.md, "Streaming"):
// named events in a fixed order, text and tool input in pieces, the usage twice (the second time as totals), and an
// error that can arrive as an event at any point. A client must read past what it does not know.

/**
 * The kinds of the events of a stream, by their short class names.
 *
 * @param list<StreamEvent> $events
 * @return list<string>
 */
function messagesKindsOf(array $events): array
{
    return array_map(fn(StreamEvent $event): string => substr((string) strrchr($event::class, '\\'), 1), $events);
}

describe('the events of a stream of the Messages adapter', function (): void {
    it('gives a text event per piece of text, the usage once and the answer last', function (): void {
        $events = Messages::eventsOf(Messages::saying('Hel', 'lo', '.'));
        $answer = Talk::finishedIn($events)->answer;

        expect(messagesKindsOf($events))->toBe(['TextDelta', 'TextDelta', 'TextDelta', 'UsageReported', 'StreamFinished'])
            ->and($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop)
            ->and($answer->model)->toBe('example-messages-1')
            ->and($answer->usage)->toEqual(new Usage(3, 2))
            ->and($answer->opaque)->toBeNull();
    });

    it('starts a tool call when its block starts and completes it when its block stops, with the input joined from its pieces', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, Messages::text('')),
            Messages::delta(0, 'text_delta', 'One moment.'),
            Messages::blockStopped(0),
            Messages::blockStarted(1, Messages::call([], 'toolu_example01')),
            Messages::delta(1, 'input_json_delta', ''),
            Messages::delta(1, 'input_json_delta', '{"ci'),
            Messages::delta(1, 'input_json_delta', 'ty": "Par'),
            Messages::delta(1, 'input_json_delta', 'is", "far": 1.0}'),
            Messages::blockStopped(1),
            Messages::blockStarted(2, Messages::call([], 'toolu_example02')),
            Messages::delta(2, 'input_json_delta', '{"city":"Rome"}'),
            Messages::blockStopped(2),
            ...Messages::stopped('tool_use'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(messagesKindsOf($events))->toBe(['TextDelta', 'ToolCallStarted', 'ToolCallCompleted', 'ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished']);

        /** @var ToolCallStarted $started */
        $started = $events[1];
        /** @var ToolCallCompleted $completed */
        $completed = $events[2];
        /** @var ToolCallStarted $second */
        $second = $events[3];

        expect([$started->index, $started->id, $started->name])->toBe([0, 'toolu_example01', 'lookup'])
            ->and($completed->index)->toBe(0)
            ->and($completed->toolCall->arguments)->toBe(['city' => 'Paris', 'far' => 1.0])
            // The pieces joined, in one spelling: the number keeps its form.
            ->and($completed->toolCall->rawArguments)->toBe('{"city":"Paris","far":1.0}')
            ->and([$second->index, $second->id])->toBe([1, 'toolu_example02'])
            ->and($answer->toolCalls)->toHaveCount(2)
            ->and($answer->toolCalls[1]->arguments)->toBe(['city' => 'Rome'])
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($answer->text)->toBe('One moment.');
    });

    it('reads a tool call without a piece of input as a call without arguments', function (array $deltas): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, ['type' => 'tool_use', 'id' => 'toolu_example01', 'name' => 'now', 'input' => (object) []]),
            ...$deltas,
            Messages::blockStopped(0),
            ...Messages::stopped('tool_use'),
        ]);
        $call = Talk::finishedIn($events)->answer->toolCalls[0];

        expect($call->arguments)->toBe([])
            ->and($call->rawArguments)->toBe('{}')
            ->and($call->name)->toBe('now');
    })->with([
        'no delta at all' => [[]],
        'one empty piece' => [[Messages::delta(0, 'input_json_delta', '')]],
        'pieces of white space' => [[Messages::delta(0, 'input_json_delta', ' '), Messages::delta(0, 'input_json_delta', "\n")]],
    ]);

    it('takes the input a block starts with when no piece follows it', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, Messages::call(['city' => 'Paris'])),
            Messages::blockStopped(0),
            ...Messages::stopped('tool_use'),
        ]);

        expect(Talk::finishedIn($events)->answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris']);
    });

    it('gives a tool call whose pieces are no JSON object as a call that cannot be read, not as an error', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, Messages::call([])),
            Messages::delta(0, 'input_json_delta', '{"city": "Par'),
            Messages::blockStopped(0),
            ...Messages::stopped('max_tokens'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->toolCalls[0]->isReadable())->toBeFalse()
            ->and($answer->toolCalls[0]->rawArguments)->toBe('{"city": "Par')
            ->and($answer->toolCalls[0]->argumentsError)->toBe('the arguments are not a JSON object')
            ->and($answer->finishReason)->toBe(FinishReason::Length);
    });

    it('completes a tool call whose block never stopped, when the stream ends as it should', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, Messages::call([])),
            Messages::delta(0, 'input_json_delta', '{"city":"Paris"}'),
            ...Messages::stopped('tool_use'),
        ]);

        expect(messagesKindsOf($events))->toBe(['ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris']);
    });

    it('gives a tool call without an id an id of its own, the same in both of its events', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, ['type' => 'tool_use', 'name' => 'lookup', 'input' => (object) []]),
            Messages::blockStopped(0),
            Messages::blockStarted(1, Messages::call([], 'call_1')),
            Messages::blockStopped(1),
            Messages::blockStarted(2, ['type' => 'tool_use', 'id' => null, 'name' => 'lookup']),
            Messages::blockStopped(2),
            ...Messages::stopped('tool_use'),
        ]);

        $ids = [];
        foreach ($events as $event) {
            if ($event instanceof ToolCallStarted) {
                $ids[] = 'started ' . $event->id;
            } elseif ($event instanceof ToolCallCompleted) {
                $ids[] = 'completed ' . $event->toolCall->id;
            }
        }

        expect($ids)->toBe(['started call_0', 'completed call_0', 'started call_1', 'completed call_1', 'started call_2', 'completed call_2']);
    });

    it('never gives thinking as text, in no event', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, Messages::thinking('', '')),
            Messages::delta(0, 'thinking_delta', 'Not for the caller.'),
            Messages::delta(0, 'signature_delta', 'c2lnLWV4YW1wbGU='),
            Messages::blockStopped(0),
            Messages::blockStarted(1, Messages::text('')),
            Messages::delta(1, 'text_delta', 'Hello.'),
            Messages::blockStopped(1),
            ...Messages::stopped(),
        ]);

        expect(messagesKindsOf($events))->toBe(['TextDelta', 'UsageReported', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
        foreach (array_slice($events, 0, -1) as $event) {
            expect(str_contains(serialize($event), 'Not for the caller'))->toBeFalse();
        }
    });
});

describe('the usage of a stream', function (): void {
    it('reads the first counts and then the totals: of each count the last value counts, and nothing is added up', function (array $first, array $last, ?int $input, ?int $output): void {
        $events = Messages::eventsOf([
            Messages::started($first),
            Messages::blockStarted(0, Messages::text('')),
            Messages::delta(0, 'text_delta', 'Hello.'),
            Messages::blockStopped(0),
            Messages::event('message_delta', ['delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => $last]),
            Messages::event('message_stop'),
        ]);
        $answer = Talk::finishedIn($events)->answer;
        $reports = array_values(array_filter($events, fn(StreamEvent $event): bool => $event instanceof UsageReported));

        expect($answer->usage)->toEqual(new Usage($input, $output))
            ->and(count($reports))->toBe($input === null && $output === null ? 0 : 1);
    })->with([
        'the documented shape' => [['input_tokens' => 40, 'output_tokens' => 1], ['output_tokens' => 25], 40, 25],
        'totals that repeat the input' => [['input_tokens' => 40, 'output_tokens' => 1], ['input_tokens' => 41, 'output_tokens' => 25], 41, 25],
        'totals whose other counts are null' => [['input_tokens' => 40, 'cache_read_input_tokens' => 10, 'output_tokens' => 1], ['input_tokens' => null, 'cache_creation_input_tokens' => null, 'cache_read_input_tokens' => null, 'output_tokens' => 25], 50, 25],
        'cache counts that arrive with the totals' => [['input_tokens' => 5, 'output_tokens' => 1], ['cache_creation_input_tokens' => 100, 'cache_read_input_tokens' => 1000, 'output_tokens' => 25], 1105, 25],
        'a sum past the largest whole number: the input is not known' => [['input_tokens' => PHP_INT_MAX, 'cache_read_input_tokens' => 1, 'output_tokens' => 1], ['output_tokens' => 2], null, 2],
        'a sum that passes the largest whole number with the totals' => [['input_tokens' => PHP_INT_MAX, 'output_tokens' => 1], ['cache_creation_input_tokens' => 1, 'output_tokens' => 2], null, 2],
        'the largest whole number, alone' => [['input_tokens' => PHP_INT_MAX, 'output_tokens' => 1], ['output_tokens' => PHP_INT_MAX], PHP_INT_MAX, PHP_INT_MAX],
        'no usage in the first event' => [[], ['output_tokens' => 25], null, 25],
        'no usage in the last event' => [['input_tokens' => 40, 'output_tokens' => 1], [], 40, 1],
        'no usage at all' => [[], [], null, null],
    ]);

    it('reports the usage it read before the failure of an answer that holds nothing', function (): void {
        $events = Messages::eventsOf([Messages::started(), ...Messages::stopped()]);

        expect(messagesKindsOf($events))->toBe(['UsageReported', 'StreamFailed'])
            ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::ProviderError);
    });
});

describe('the end of a stream', function (): void {
    it('maps the stop reason as a whole answer does', function (?string $stop, FinishReason $reason): void {
        $events = Messages::eventsOf([Messages::started(), Messages::blockStarted(0, Messages::text('Hello.')), Messages::blockStopped(0), ...Messages::stopped($stop)]);

        expect(Talk::finishedIn($events)->answer->finishReason)->toBe($reason)
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    })->with([
        ['end_turn', FinishReason::Stop],
        ['stop_sequence', FinishReason::Stop],
        ['max_tokens', FinishReason::Length],
        ['model_context_window_exceeded', FinishReason::Length],
        ['refusal', FinishReason::Refusal],
        ['pause_turn', FinishReason::Other],
        ['tool_use', FinishReason::Other],
        [null, FinishReason::Other],
    ]);

    it('returns a refusal without text as an answer with the finish reason "refusal"', function (): void {
        $events = Messages::eventsOf([Messages::started(), ...Messages::stopped('refusal')]);

        expect(messagesKindsOf($events))->toBe(['UsageReported', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->finishReason)->toBe(FinishReason::Refusal)
            ->and(Talk::finishedIn($events)->answer->text)->toBe('');
    });

    it('ends a stream without its last event as a failure, with the text and the tool calls that arrived', function (array $chunks, string $text, int $calls): void {
        $events = Messages::eventsOf($chunks);
        $failed = Talk::failedIn($events);

        expect($failed->error->reason)->toBe(ErrorReason::ProviderError)
            ->and($failed->error->detail)->toBe('the stream ended without its last event')
            ->and($failed->error->httpStatus)->toBe(200)
            ->and($failed->textSoFar)->toBe($text)
            ->and($failed->toolCallsSoFar)->toHaveCount($calls);
    })->with([
        'nothing at all' => [[''], '', 0],
        'only the first event' => [[Messages::started()], '', 0],
        'text, and then the body ends' => [[Messages::started(), Messages::blockStarted(0, Messages::text('')), Messages::delta(0, 'text_delta', 'Hel')], 'Hel', 0],
        'everything but the last event' => [array_slice(Messages::saying('Hello.'), 0, -1), 'Hello.', 0],
        'a tool call that completed, and then the body ends' => [[Messages::started(), Messages::blockStarted(0, Messages::call([])), Messages::delta(0, 'input_json_delta', '{}'), Messages::blockStopped(0)], '', 1],
        'a tool call that started and never stopped' => [[Messages::started(), Messages::blockStarted(0, Messages::call([])), Messages::delta(0, 'input_json_delta', '{"city":')], '', 0],
    ]);

    it('reads nothing after the last event', function (): void {
        $events = Messages::eventsOf([...Messages::saying('Hello.'), Messages::blockStarted(5, Messages::text('More.')), Messages::event('error', ['error' => ['type' => 'api_error', 'message' => 'Late.']])]);

        expect(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });
});

describe('an error event in a stream', function (): void {
    it('gives the reason of its type, with the code of the provider', function (string $type, ?string $code, ErrorReason $reason, string $shown): void {
        $error = ['type' => $type, 'message' => 'Words of the provider.'];
        if ($code !== null) {
            $error['details'] = ['error_code' => $code];
        }

        // Before anything else, and after a piece of text.
        $first = Talk::failedIn(Messages::eventsOf([Messages::event('error', ['error' => $error])]));
        $later = Talk::failedIn(Messages::eventsOf([Messages::started(), Messages::blockStarted(0, Messages::text('')), Messages::delta(0, 'text_delta', 'Hel'), Messages::event('error', ['error' => $error])]));

        foreach ([$first, $later] as $failed) {
            expect($failed->error->reason)->toBe($reason)
                ->and($failed->error->providerCode)->toBe($shown)
                ->and($failed->error->detail)->toBe('the stream holds an error')
                ->and($failed->error->httpStatus)->toBe(200)
                ->and($failed->error->retryAfterSeconds)->toBeNull()
                ->and($failed->error->attempts)->toBe(1);
        }
        expect($first->textSoFar)->toBe('')
            ->and($later->textSoFar)->toBe('Hel');
    })->with([
        'overloaded' => ['overloaded_error', null, ErrorReason::Unavailable, 'overloaded_error'],
        'a rate limit' => ['rate_limit_error', null, ErrorReason::RateLimited, 'rate_limit_error'],
        'a rate limit with the code of a ramp limit' => ['rate_limit_error', 'ramp_limit_exceeded', ErrorReason::RateLimited, 'ramp_limit_exceeded'],
        'a rate limit with the code of the spend cap' => ['rate_limit_error', 'enforced_spend_limit_reached', ErrorReason::Rejected, 'enforced_spend_limit_reached'],
        'an error of the provider' => ['api_error', null, ErrorReason::ProviderError, 'api_error'],
        'a timeout' => ['timeout_error', null, ErrorReason::ProviderError, 'timeout_error'],
        'a refused request' => ['invalid_request_error', null, ErrorReason::ProviderError, 'invalid_request_error'],
        'a type of a later version' => ['some_new_error', null, ErrorReason::ProviderError, 'some_new_error'],
    ]);

    it('is not tried again, also when it is the first event and says the provider is overloaded', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))
            ->willStream(200, [], Messages::event('error', ['error' => ['type' => 'overloaded_error', 'message' => 'Busy.']]))
            ->willStream(200, [], ...Messages::saying());
        $events = Talk::events(Messages::gateway($transport, retry: new RetryPolicy([1.0], 60.0), clock: $clock)->stream(Messages::request()));

        expect(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::Unavailable)
            ->and($transport->requests())->toHaveCount(1)
            ->and($clock->sleeps())->toBe([]);
    });

    it('is read without a type, without an error object and with members of the wrong type', function (array $data): void {
        $failed = Talk::failedIn(Messages::eventsOf([Messages::event('error', $data)]));

        expect($failed->error->reason)->toBe(ErrorReason::ProviderError)
            ->and($failed->error->providerCode)->toBeNull();
    })->with([
        'nothing beside the type of the event' => [[]],
        'an error that is a word' => [['error' => 'overloaded_error']],
        'an error without a type' => [['error' => ['message' => 'No.']]],
        'a type that is a number' => [['error' => ['type' => 529]]],
        'details that are a word' => [['error' => ['type' => ['x'], 'details' => 'none']]],
    ]);
});

describe('the shapes of a stream the Messages adapter reads past', function (): void {
    it('reads past ping events, wherever they stand', function (): void {
        $ping = Messages::event('ping');
        $chunks = [];
        foreach (Messages::saying('Hel', 'lo.') as $event) {
            $chunks[] = $ping;
            $chunks[] = $event;
        }

        expect(messagesKindsOf(Messages::eventsOf([...$chunks, $ping])))->toBe(['TextDelta', 'TextDelta', 'UsageReported', 'StreamFinished']);
    });

    it('reads past an event of a type it does not know, a comment line and data that is no JSON object', function (): void {
        $events = Messages::eventsOf([
            ": a comment line\n\n",
            Messages::event('some_new_event', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Not this.']]),
            Messages::started(),
            "event: content_block_delta\ndata: this is no JSON\n\n",
            "event: message_delta\ndata: \"a JSON string\"\n\n",
            "data: 7\n\n",
            "event: ping\n\n",
            Messages::blockStarted(0, Messages::text('')),
            Messages::delta(0, 'text_delta', 'Hello.'),
            Messages::blockStopped(0),
            ...Messages::stopped(),
        ]);

        expect(messagesKindsOf($events))->toBe(['TextDelta', 'UsageReported', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });

    it('reads the type of an event from its name line when its data names none', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, Messages::text('')),
            "event: content_block_delta\ndata: {\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello.\"}}\n\n",
            "event: message_delta\ndata: {\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":2}}\n\n",
            "event: message_stop\ndata: {}\n\n",
        ]);

        expect(Talk::finishedIn($events)->answer->text)->toBe('Hello.')
            ->and(Talk::finishedIn($events)->answer->finishReason)->toBe(FinishReason::Stop);
    });

    it('reads past a block of a kind it does not know, with its deltas', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, ['type' => 'server_tool_use', 'id' => 'srvtoolu_example01', 'name' => 'search', 'input' => (object) []]),
            Messages::delta(0, 'input_json_delta', '{"q":"x"}'),
            Messages::delta(0, 'text_delta', 'Not text of the answer.'),
            Messages::blockStopped(0),
            Messages::blockStarted(1, ['text' => 'A block without a type.']),
            Messages::delta(1, 'text_delta', 'Not this either.'),
            Messages::blockStopped(1),
            Messages::blockStarted(2, Messages::text('')),
            Messages::delta(2, 'text_delta', 'Hello.'),
            Messages::blockStopped(2),
            ...Messages::stopped(),
        ]);

        expect(messagesKindsOf($events))->toBe(['TextDelta', 'UsageReported', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.')
            ->and(Talk::finishedIn($events)->answer->toolCalls)->toBe([]);
    });

    it('reads past a delta and a stop for an index that never started, and a delta without an index', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::delta(3, 'text_delta', 'Not this.'),
            Messages::delta(4, 'input_json_delta', '{"city":"Paris"}'),
            Messages::blockStopped(4),
            Messages::event('content_block_delta', ['delta' => ['type' => 'text_delta', 'text' => 'Not this.']]),
            Messages::event('content_block_delta', ['index' => '0', 'delta' => ['type' => 'text_delta', 'text' => 'Not this.']]),
            Messages::event('content_block_start', ['content_block' => Messages::text('Not this.')]),
            Messages::blockStarted(0, Messages::text('')),
            Messages::delta(0, 'text_delta', 'Hello.'),
            Messages::blockStopped(0),
            ...Messages::stopped(),
        ]);

        expect(messagesKindsOf($events))->toBe(['TextDelta', 'UsageReported', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });

    it('reads past a delta of a type that does not fit its block, a delta of a type it does not know and a delta of the wrong shape', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, Messages::text('')),
            Messages::delta(0, 'input_json_delta', '{"city":"Paris"}'),
            Messages::delta(0, 'thinking_delta', 'Not this.'),
            Messages::delta(0, 'citations_delta', 'Not this.'),
            Messages::event('content_block_delta', ['index' => 0, 'delta' => 'text']),
            Messages::event('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ['a', 'list']]]),
            Messages::event('content_block_delta', ['index' => 0]),
            Messages::delta(0, 'text_delta', 'Hello.'),
            Messages::blockStopped(0),
            Messages::blockStarted(1, Messages::call([])),
            Messages::delta(1, 'text_delta', 'Not text.'),
            Messages::delta(1, 'input_json_delta', '{"city":"Paris"}'),
            Messages::blockStopped(1),
            Messages::delta(1, 'input_json_delta', ', "late": true'),
            Messages::blockStopped(1),
            ...Messages::stopped('tool_use'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(messagesKindsOf($events))->toBe(['TextDelta', 'ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished'])
            ->and($answer->text)->toBe('Hello.')
            ->and($answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris']);
    });

    it('keeps the first block of an index that starts twice', function (): void {
        $events = Messages::eventsOf([
            Messages::started(),
            Messages::blockStarted(0, Messages::text('Hel')),
            Messages::blockStarted(0, Messages::call([])),
            Messages::delta(0, 'text_delta', 'lo.'),
            Messages::blockStopped(0),
            ...Messages::stopped(),
        ]);

        expect(messagesKindsOf($events))->toBe(['TextDelta', 'TextDelta', 'UsageReported', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });

    it('reads a stream that arrives in chunks cut at any byte', function (): void {
        $whole = implode('', [
            Messages::started(),
            Messages::blockStarted(0, Messages::text('')),
            Messages::delta(0, 'text_delta', 'Hé, '),
            Messages::delta(0, 'text_delta', 'hello.'),
            Messages::blockStopped(0),
            Messages::blockStarted(1, Messages::call([])),
            Messages::delta(1, 'input_json_delta', '{"city":"Paris"}'),
            Messages::blockStopped(1),
            ...Messages::stopped('tool_use'),
        ]);

        foreach ([1, 7, 64] as $size) {
            $events = Messages::eventsOf(str_split($whole, $size));
            $answer = Talk::finishedIn($events)->answer;

            expect($answer->text)->toBe('Hé, hello.', 'chunks of ' . $size)
                ->and($answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris'], 'chunks of ' . $size);
        }
    });

    it('ends with exactly one last event, whatever the stream holds', function (array $chunks): void {
        $events = Messages::eventsOf($chunks);
        $last = $events[count($events) - 1];

        expect($last instanceof StreamFinished || $last instanceof StreamFailed)->toBeTrue();
        foreach (array_slice($events, 0, -1) as $event) {
            expect($event instanceof StreamFinished || $event instanceof StreamFailed)->toBeFalse()
                ->and($event instanceof TextDelta || $event instanceof ToolCallStarted || $event instanceof ToolCallCompleted || $event instanceof UsageReported)->toBeTrue();
        }
    })->with([
        'two last events' => [[...Messages::saying(), Messages::event('message_stop')]],
        'an error after text' => [[Messages::started(), Messages::blockStarted(0, Messages::text('Hel')), Messages::event('error', ['error' => ['type' => 'api_error']])]],
        'a message_start that is a list' => [[Messages::event('message_start', ['message' => [1, 2]]), ...Messages::stopped()]],
        'a message_delta without a delta' => [[Messages::started(), Messages::blockStarted(0, Messages::text('Hello.')), Messages::event('message_delta', ['usage' => 'none']), Messages::event('message_stop')]],
    ]);
});

describe('a stream of very many tool calls', function (): void {
    // The ids that are taken are kept as a set. No bound on time here: the test says the answer is right.
    it('gives every one of 30,000 calls its own id, the same in both of its events, also the calls without an id', function (): void {
        $chunks = [Messages::started()];
        for ($at = 0; $at < 30_000; $at++) {
            $block = ['type' => 'tool_use', 'name' => 'lookup', 'input' => (object) []];
            if ($at % 2 === 1) {
                // The id the adapter would give the call after it, which has none.
                $block['id'] = 'call_' . ($at + 1);
            }
            $chunks[] = Messages::blockStarted($at, $block) . Messages::delta($at, 'input_json_delta', '{"n":' . $at . '}') . Messages::blockStopped($at);
        }

        $started = [];
        $completed = [];
        $last = null;
        foreach (Messages::eventsOf([...$chunks, ...Messages::stopped('tool_use')]) as $event) {
            if ($event instanceof ToolCallStarted) {
                $started[$event->index] = $event->id;
            } elseif ($event instanceof ToolCallCompleted) {
                $completed[$event->index] = $event->toolCall->id;
            }
            $last = $event;
        }

        expect($last)->toBeInstanceOf(StreamFinished::class);
        assert($last instanceof StreamFinished);
        expect($started)->toHaveCount(30_000)
            ->and($completed)->toBe($started)
            ->and(count(array_unique($started)))->toBe(30_000)
            ->and(array_slice($started, 0, 4))->toBe(['call_0', 'call_2', 'call_2_2', 'call_4'])
            ->and($last->answer->toolCalls)->toHaveCount(30_000)
            ->and($last->answer->toolCalls[29_999]->arguments)->toBe(['n' => 29_999]);
    });
});
