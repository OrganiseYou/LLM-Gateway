<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContent;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// How the generate-content adapter reads a stream (docs/design/adapters.md, section 4.2): every event is a whole
// response with the new parts only, the usage of the last chunk counts, and the stream is complete when its body
// ends. The shapes the provider file does not pin down are read tolerantly.

/**
 * The classes of the events of a stream, without their namespace.
 *
 * @param list<StreamEvent> $events
 * @return list<string>
 */
function generateEventKinds(array $events): array
{
    return array_map(fn(StreamEvent $event): string => substr((string) strrchr($event::class, '\\'), 1), $events);
}

describe('a stream of the generate-content adapter', function (): void {
    it('gives the text of every chunk as it arrives, the usage once, and the answer last', function (): void {
        $events = GenerateContent::eventsOf([
            GenerateContent::chunk([GenerateContent::text('Hel')], null, ['usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 1]]),
            GenerateContent::chunk([GenerateContent::text('lo')], null, ['usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2, 'thoughtsTokenCount' => 4]]),
            GenerateContent::chunk([GenerateContent::text('.')], 'STOP', ['usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 3], 'modelVersion' => 'example-generate-1-001']),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(generateEventKinds($events))->toBe(['TextDelta', 'TextDelta', 'TextDelta', 'UsageReported', 'StreamFinished'])
            ->and($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop)
            ->and($answer->model)->toBe('example-generate-1-001')
            // Of each count the last value seen: the thoughts count of the second chunk stays.
            ->and($answer->usage->inputTokens)->toBe(3)
            ->and($answer->usage->outputTokens)->toBe(7)
            ->and($events[3])->toBeInstanceOf(UsageReported::class);
    });

    it('keeps a thought out of the text and gives no event for it', function (): void {
        $events = GenerateContent::eventsOf([
            GenerateContent::chunk([GenerateContent::text('Thinking.', ['thought' => true])]),
            GenerateContent::chunk([GenerateContent::text('Hello.')], 'STOP'),
        ]);

        expect(generateEventKinds($events))->toBe(['TextDelta', 'UsageReported', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });

    it('gives a tool call as started and completed at once: its arguments arrive whole', function (): void {
        $events = GenerateContent::eventsOf([
            GenerateContent::chunk([GenerateContent::text('Let me look.')]),
            GenerateContent::chunk([GenerateContent::call(['city' => 'Paris']), GenerateContent::call(['city' => 'Rome'], null)]),
            GenerateContent::chunk([GenerateContent::call(['city' => 'Oslo'], null)], 'STOP'),
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(generateEventKinds($events))->toBe([
            'TextDelta', 'ToolCallStarted', 'ToolCallCompleted', 'ToolCallStarted', 'ToolCallCompleted', 'ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished',
        ])
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($answer->toolCalls)->toEqual([
                ToolCall::decoded('fc-example01', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'),
                ToolCall::decoded('call-1', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}'),
                ToolCall::decoded('call-2', 'lookup', ['city' => 'Oslo'], '{"city":"Oslo"}'),
            ]);

        $started = $events[3];
        $completed = $events[4];
        expect($started)->toBeInstanceOf(ToolCallStarted::class)
            ->and($completed)->toBeInstanceOf(ToolCallCompleted::class);
        assert($started instanceof ToolCallStarted && $completed instanceof ToolCallCompleted);
        expect($started->index)->toBe(1)
            ->and($started->id)->toBe('call-1')
            ->and($completed->index)->toBe(1)
            ->and($completed->toolCall->id)->toBe('call-1');
    });

    it('gives a call without an id an id no call of an earlier or a later chunk has', function (): void {
        $events = GenerateContent::eventsOf([
            GenerateContent::chunk([GenerateContent::call([], 'call-1')]),
            GenerateContent::chunk([GenerateContent::call([], null)]),
            GenerateContent::chunk([GenerateContent::call([], null), GenerateContent::call([], 'call-2')], 'STOP'),
        ]);

        expect(array_map(fn(ToolCall $call): string => $call->id, Talk::finishedIn($events)->answer->toolCalls))->toBe(['call-1', 'call-1-2', 'call-2-2', 'call-2']);
    });

    it('returns a call whose arguments are no object as a call that cannot be read', function (): void {
        $events = GenerateContent::eventsOf([GenerateContent::chunk([GenerateContent::call('{"city": "Par')], 'STOP')]);
        $call = Talk::finishedIn($events)->answer->toolCalls[0];

        expect($call->isReadable())->toBeFalse()
            ->and($call->rawArguments)->toBe('{"city": "Par');
    });

    it('reads both spellings of a finish reason', function (string $word, FinishReason $reason): void {
        foreach ([$word, 'FINISH_REASON_' . $word] as $spelling) {
            $events = GenerateContent::eventsOf([GenerateContent::chunk([GenerateContent::text()], $spelling)]);

            expect(Talk::finishedIn($events)->answer->finishReason)->toBe($reason, $spelling);
        }
    })->with([
        ['STOP', FinishReason::Stop],
        ['MAX_TOKENS', FinishReason::Length],
        ['SAFETY', FinishReason::ContentFilter],
        ['RECITATION', FinishReason::ContentFilter],
        ['OTHER', FinishReason::Other],
    ]);

    it('ends a stream whose finish reason says the answer is a fault as a failure with that word as its code', function (): void {
        foreach (['MALFORMED_FUNCTION_CALL', 'FINISH_REASON_MALFORMED_FUNCTION_CALL'] as $spelling) {
            $events = GenerateContent::eventsOf([GenerateContent::chunk([GenerateContent::text('Hel')]), GenerateContent::chunk([], $spelling)]);
            $failed = Talk::failedIn($events);

            expect(generateEventKinds($events))->toBe(['TextDelta', 'StreamFailed'])
                ->and($failed->error->reason)->toBe(ErrorReason::ProviderError)
                ->and($failed->error->providerCode)->toBe('malformed_function_call');
        }
    });

    it('ends a stream whose prompt the provider refused as "rejected" with the block reason as its code', function (): void {
        $events = GenerateContent::eventsOf([
            GenerateContent::event(['promptFeedback' => ['blockReason' => 'SAFETY', 'blockReasonMessage' => 'The words of the provider.'], 'usageMetadata' => ['promptTokenCount' => 3]]),
            GenerateContent::chunk([GenerateContent::text()], 'STOP'),
        ]);
        $failed = Talk::failedIn($events);

        expect($events)->toHaveCount(1)
            ->and($failed->error->reason)->toBe(ErrorReason::Rejected)
            ->and($failed->error->providerCode)->toBe('safety')
            ->and($failed->error->detail)->toBe('the prompt was refused before an answer was made')
            ->and($failed->error->attempts)->toBe(1);
    });

    it('ends a stream that holds an error object as an error of the provider with its code, only in a chunk without an answer', function (): void {
        $fault = 'data: ' . GenerateContent::errorBody(503, 'UNAVAILABLE', 'EXAMPLE_REASON') . "\n\n";
        $events = GenerateContent::eventsOf([GenerateContent::chunk([GenerateContent::text('Hel')]), $fault]);
        $failed = Talk::failedIn($events);

        $beside = GenerateContent::eventsOf([GenerateContent::chunk([GenerateContent::text()], 'STOP', ['error' => ['code' => 500, 'status' => 'INTERNAL']])]);

        expect($failed->error->reason)->toBe(ErrorReason::ProviderError)
            ->and($failed->error->providerCode)->toBe('example_reason')
            ->and($failed->textSoFar)->toBe('Hel')
            ->and(Talk::finishedIn($beside)->answer->text)->toBe('Hello.');
    });

    it('returns an answer the provider withheld, with no content at all, as an answer', function (): void {
        $events = GenerateContent::eventsOf([GenerateContent::event(['candidates' => [['index' => 0, 'finishReason' => 'SAFETY']], 'usageMetadata' => ['promptTokenCount' => 3]])]);
        $answer = Talk::finishedIn($events)->answer;

        expect($answer->text)->toBe('')
            ->and($answer->finishReason)->toBe(FinishReason::ContentFilter);
    });
});

describe('the shapes of a stream the generate-content adapter tolerates', function (): void {
    it('reads past a chunk without candidates, a chunk that holds only the usage, an empty list of parts and a part of an unknown kind', function (): void {
        $events = GenerateContent::eventsOf([
            ": a comment line\n\n",
            GenerateContent::event(['responseId' => 'resp-example01']),
            GenerateContent::event(['candidates' => []]),
            GenerateContent::event(['candidates' => null, 'promptFeedback' => ['safetyRatings' => []]]),
            GenerateContent::chunk([GenerateContent::text('Hel')]),
            GenerateContent::chunk([]),
            GenerateContent::event(['candidates' => [['index' => 0]]]),
            GenerateContent::event(['candidates' => [['index' => 0, 'content' => ['role' => 'model']]]]),
            GenerateContent::event(['candidates' => [['index' => 0, 'content' => 'Hello.']]]),
            GenerateContent::event(['candidates' => ['Hello.']]),
            GenerateContent::chunk([['inlineData' => ['mimeType' => 'image/png', 'data' => 'AAAA']], 'a bare string', ['text' => ['x']], ['functionCall' => 7]]),
            GenerateContent::chunk([GenerateContent::text('lo.')], 'STOP', ['usageMetadata' => null]),
            GenerateContent::event(['usageMetadata' => ['promptTokenCount' => 9, 'candidatesTokenCount' => 8]]),
            "data: not JSON\n\n",
            "data: [1,2]\n\n",
            "data: \"text\"\n\n",
            "event: some_name\ndata: {\"someNewField\":true}\n\n",
        ]);
        $answer = Talk::finishedIn($events)->answer;

        expect(generateEventKinds($events))->toBe(['TextDelta', 'TextDelta', 'UsageReported', 'StreamFinished'])
            ->and($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop)
            ->and($answer->usage->inputTokens)->toBe(9)
            ->and($answer->usage->outputTokens)->toBe(8);
    });

    it('gives a stream that ends without a finish reason as an answer that ended for another reason, when it held text or a call', function (): void {
        $text = GenerateContent::eventsOf([GenerateContent::chunk([GenerateContent::text('Hel')]), GenerateContent::chunk([GenerateContent::text('lo.')])]);
        $call = GenerateContent::eventsOf([GenerateContent::chunk([GenerateContent::call()])]);

        expect(Talk::finishedIn($text)->answer->text)->toBe('Hello.')
            ->and(Talk::finishedIn($text)->answer->finishReason)->toBe(FinishReason::Other)
            ->and(Talk::finishedIn($call)->answer->finishReason)->toBe(FinishReason::ToolCalls);
    });

    it('fails a stream that holds nothing, with or without a finish reason, as an error of the provider', function (array $chunks): void {
        $events = GenerateContent::eventsOf($chunks);
        $last = $events[count($events) - 1];

        expect($last)->toBeInstanceOf(StreamFailed::class);
        assert($last instanceof StreamFailed);
        expect($last->error->reason)->toBe(ErrorReason::ProviderError);
        foreach ($events as $event) {
            expect($event)->not->toBeInstanceOf(StreamFinished::class)
                ->and($event)->not->toBeInstanceOf(TextDelta::class);
        }
    })->with([
        'no event at all' => [[": nothing\n\n"]],
        'an empty body' => [['']],
        'only the usage' => [[GenerateContent::event(['usageMetadata' => ['promptTokenCount' => 3]])]],
        'an empty answer that ended by itself' => [[GenerateContent::chunk([], 'STOP')]],
        'lines that are no JSON' => [["data: no\n\n", "data: {\n\n"]],
    ]);

    it('reports no usage for a stream that tells none', function (): void {
        $events = GenerateContent::eventsOf([GenerateContent::event(['candidates' => [['content' => ['parts' => [GenerateContent::text()]], 'finishReason' => 'STOP']]])]);

        expect(generateEventKinds($events))->toBe(['TextDelta', 'StreamFinished'])
            ->and(Talk::finishedIn($events)->answer->usage->inputTokens)->toBeNull();
    });

    it('reads an event whose data is cut over several lines and several chunks of the transport', function (): void {
        $event = GenerateContent::chunk([GenerateContent::text()], 'STOP');
        $events = GenerateContent::eventsOf(str_split($event, 7));

        expect(Talk::finishedIn($events)->answer->text)->toBe('Hello.');
    });
});
