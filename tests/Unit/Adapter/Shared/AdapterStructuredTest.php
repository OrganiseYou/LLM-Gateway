<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\StructuredOutputEmulation;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\StructuredIssueKind;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\RecordingObserver;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\AttemptOutcome;

/**
 * A response format with one required text.
 */
function cityFormat(): ResponseFormat
{
    return new ResponseFormat(JsonSchema::fromArray([
        'type' => 'object',
        'properties' => ['city' => ['type' => 'string']],
        'required' => ['city'],
    ]), 'verdict');
}

/**
 * A response format whose schema the validator cannot use.
 */
function brokenFormat(): ResponseFormat
{
    return new ResponseFormat(JsonSchema::fromJson('{"type":"object","required":"city"}'), 'verdict');
}

function cityRequest(?ResponseFormat $format = null): Request
{
    return Talk::request()->withResponseFormat($format ?? cityFormat());
}

/**
 * The chunks of a stream of the made-up format that gives the text in two pieces.
 *
 * @return list<string>
 */
function talkStreamOf(string $text, string $end = 'done'): array
{
    $half = intdiv(strlen($text), 2);
    $chunks = [];
    foreach ([substr($text, 0, $half), substr($text, $half)] as $piece) {
        if ($piece !== '') {
            $chunks[] = Talk::event('say', ['piece' => $piece]);
        }
    }
    $chunks[] = Talk::event('used', ['in' => 9, 'out' => 64]);
    $chunks[] = Talk::event('end', ['end' => $end, 'by' => 'model-a-0001']);

    return $chunks;
}

/**
 * The answer of the same text and end in the three ways an answer arrives: complete(), a stream, and a stream of a
 * format without streaming.
 *
 * @param array<string, bool|int|string> $options
 * @return array<string, Answer>
 */
function answersOf(string $text, string $end = 'done', ?ResponseFormat $format = null, array $options = []): array
{
    $whole = Talk::answered($text, ['end' => $end]);

    return [
        'complete' => Talk::gateway((new FakeTransport())->willReturn($whole), options: $options)->complete(cityRequest($format)),
        'stream' => Talk::finishedIn(Talk::events(
            Talk::gateway((new FakeTransport())->willStream(200, [], ...talkStreamOf($text, $end)), options: $options)->stream(cityRequest($format)),
        ))->answer,
        'stream without streaming' => Talk::finishedIn(Talk::events(
            Talk::gateway((new FakeTransport())->willReturn($whole), options: ['streams' => false, ...$options])->stream(cityRequest($format)),
        ))->answer,
    ];
}

describe('the structured result of an answer', function (): void {
    it('is null when the request has no response format, and given for every answer without tool calls when it has one', function (): void {
        $plain = Talk::gateway((new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}')))->complete(Talk::request());
        $streamed = Talk::finishedIn(Talk::events(
            Talk::gateway((new FakeTransport())->willStream(200, [], ...talkStreamOf('{"city":"Utrecht"}')))->stream(Talk::request()),
        ))->answer;

        expect($plain->structured)->toBeNull()
            ->and($streamed->structured)->toBeNull();

        foreach (['{"city":"Utrecht"}', 'No JSON here.', '{"city":7}'] as $text) {
            foreach (answersOf($text) as $way => $answer) {
                expect($answer->structured)->not->toBeNull($way . ' must carry a structured result for: ' . $text);
            }
        }
    });

    it('is usable for an answer that matches, the same for complete() and for a stream', function (): void {
        foreach (answersOf(" {\"city\":\"Utrecht\"}\n") as $way => $answer) {
            expect($answer->structured?->isUsable())->toBeTrue($way)
                ->and($answer->structured?->data)->toBe(['city' => 'Utrecht'])
                ->and($answer->structured?->reasons())->toBe([])
                ->and($answer->text)->toBe(" {\"city\":\"Utrecht\"}\n")
                ->and($answer->finishReason)->toBe(FinishReason::Stop);
        }
    });

    it('takes the JSON out of a code fence and leaves the text of the answer as it came', function (): void {
        $text = "```json\n{\"city\":\"Utrecht\"}\n```";

        foreach (answersOf($text) as $way => $answer) {
            expect($answer->structured?->isUsable())->toBeTrue($way)
                ->and($answer->structured?->data)->toBe(['city' => 'Utrecht'])
                ->and($answer->text)->toBe($text);
        }
    });

    it('returns an answer that is not JSON with its reason, and does not throw or retry', function (): void {
        foreach (answersOf('I would say Utrecht.') as $way => $answer) {
            expect($answer->structured?->isUsable())->toBeFalse($way)
                ->and($answer->structured?->data)->toBeNull()
                ->and($answer->structured?->issues[0]->kind)->toBe(StructuredIssueKind::NotJson)
                ->and($answer->structured?->reasons()[0])->toStartWith('not JSON: ')
                ->and($answer->text)->toBe('I would say Utrecht.')
                ->and($answer->attempts)->toBe(1);
        }
    });

    it('returns an answer that does not match with "path: message" and the decoded data', function (): void {
        foreach (answersOf('{"city":7}') as $way => $answer) {
            expect($answer->structured?->isUsable())->toBeFalse($way)
                ->and($answer->structured?->data)->toBe(['city' => 7])
                ->and($answer->structured?->reasons())->toBe(['/city: The data (integer) must match the type: string']);
        }
    });

    it('returns JSON that is no object as a mismatch at the root', function (): void {
        foreach (answersOf('["Utrecht"]') as $way => $answer) {
            expect($answer->structured?->reasons())->toBe(['/: must be an object'], $way)
                ->and($answer->structured?->data)->toBeNull();
        }
    });

    it('reports an answer that stopped at the token limit as "cut off at the token limit"', function (): void {
        foreach (answersOf('{"city":"Utre', 'cut') as $way => $answer) {
            expect($answer->finishReason)->toBe(FinishReason::Length, $way)
                ->and($answer->structured?->isUsable())->toBeFalse()
                ->and($answer->structured?->data)->toBeNull()
                ->and($answer->structured?->issues)->toHaveCount(1)
                ->and($answer->structured?->issues[0]->kind)->toBe(StructuredIssueKind::CutOff)
                ->and($answer->structured?->reasons())->toBe(['cut off at the token limit'])
                ->and($answer->text)->toBe('{"city":"Utre');
        }
    });

    it('reports an empty answer that stopped at the token limit the same way, with its usage, and does not throw', function (): void {
        foreach (answersOf('', 'cut') as $way => $answer) {
            expect($answer->text)->toBe('', $way)
                ->and($answer->toolCalls)->toBe([])
                ->and($answer->finishReason)->toBe(FinishReason::Length)
                ->and($answer->structured?->isUsable())->toBeFalse()
                ->and($answer->structured?->data)->toBeNull()
                ->and($answer->structured?->reasons())->toBe(['cut off at the token limit']);
        }

        $streamed = Talk::finishedIn(Talk::events(
            Talk::gateway((new FakeTransport())->willStream(200, [], ...talkStreamOf('', 'cut')))->stream(cityRequest()),
        ))->answer;

        expect($streamed->usage)->toEqual(new Usage(9, 64));
    });

    it('keeps the data of an answer that was cut off after its JSON was complete, and still calls it cut off', function (): void {
        foreach (answersOf('{"city":"Utrecht"}', 'cut') as $way => $answer) {
            expect($answer->structured?->isUsable())->toBeFalse($way)
                ->and($answer->structured?->data)->toBe(['city' => 'Utrecht'])
                ->and($answer->structured?->reasons())->toBe(['cut off at the token limit']);
        }
    });

    it('gives the text deltas of a stream before the finished event that carries the structured result', function (): void {
        $events = Talk::events(Talk::gateway((new FakeTransport())->willStream(200, [], ...talkStreamOf('{"city":7}')))->stream(cityRequest()));
        $text = '';
        foreach ($events as $event) {
            $text .= $event instanceof TextDelta ? $event->text : '';
        }

        expect($text)->toBe('{"city":7}')
            ->and($events[count($events) - 1])->toBeInstanceOf(StreamFinished::class)
            ->and(Talk::finishedIn($events)->answer->structured?->reasons())->toBe(['/city: The data (integer) must match the type: string']);
    });

    it('is null for an answer that asks for tool calls: that answer is not the final one, and its text is not checked', function (): void {
        $tool = new ToolDefinition('lookup', 'Looks something up.', JsonSchema::fromArray(['type' => 'object']));
        $request = cityRequest()->withTools([$tool]);
        $call = ['ref' => 'c1', 'tool' => 'lookup', 'input' => '{"q":"x"}'];
        $whole = Talk::answered('Let me look that up.', ['end' => 'calls', 'calls' => [$call]]);
        $chunks = [
            Talk::event('say', ['piece' => 'Let me look that up.']),
            Talk::event('call_open', ['ref' => 'c1', 'tool' => 'lookup']),
            Talk::event('call', $call),
            Talk::event('end', ['end' => 'calls', 'by' => 'model-a-0001']),
        ];

        $answers = [
            'complete' => Talk::gateway((new FakeTransport())->willReturn($whole))->complete($request),
            'stream' => Talk::finishedIn(Talk::events(Talk::gateway((new FakeTransport())->willStream(200, [], ...$chunks))->stream($request)))->answer,
            'stream without streaming' => Talk::finishedIn(Talk::events(
                Talk::gateway((new FakeTransport())->willReturn($whole), options: ['streams' => false])->stream($request),
            ))->answer,
        ];

        foreach ($answers as $way => $answer) {
            expect($answer->toolCalls)->toHaveCount(1, $way)
                ->and($answer->finishReason)->toBe(FinishReason::ToolCalls)
                ->and($answer->text)->toBe('Let me look that up.')
                ->and($answer->structured)->toBeNull();
        }
    });

    it('is null for an answer with tool calls also when the schema cannot be used and when the adapter gave a result of its own', function (): void {
        $tool = new ToolDefinition('lookup', 'Looks something up.', JsonSchema::fromArray(['type' => 'object']));
        $calls = ['end' => 'calls', 'calls' => [['ref' => 'c1', 'tool' => 'lookup', 'input' => '{"q":"x"}']]];

        $broken = Talk::gateway((new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}', $calls)))
            ->complete(cityRequest(brokenFormat())->withTools([$tool]));
        $ownWay = Talk::gateway((new FakeTransport())->willReturn(Talk::answered('', [...$calls, 'shaped' => ['city' => 'Zwolle']])))
            ->complete(cityRequest()->withTools([$tool]));

        expect($broken->structured)->toBeNull()
            ->and($broken->toolCalls)->toHaveCount(1)
            ->and($ownWay->structured)->toBeNull();
    });

    it('is given again for the answer without tool calls that follows', function (): void {
        $tool = new ToolDefinition('lookup', 'Looks something up.', JsonSchema::fromArray(['type' => 'object']));
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}'));

        $answer = Talk::gateway($transport)->complete(cityRequest()->withTools([$tool]));

        expect($answer->toolCalls)->toBe([])
            ->and($answer->structured?->isUsable())->toBeTrue();
    });
});

describe('a structured result the adapter got its own way', function (): void {
    it('is kept as it is and not checked again, also when the text would not pass', function (): void {
        $shaped = ['shaped' => ['city' => 'Zwolle']];
        $answers = [
            'complete' => Talk::gateway((new FakeTransport())->willReturn(Talk::answered('This text is no JSON.', $shaped)))->complete(cityRequest()),
            'stream without streaming' => Talk::finishedIn(Talk::events(
                Talk::gateway((new FakeTransport())->willReturn(Talk::answered('This text is no JSON.', $shaped)), options: ['streams' => false])->stream(cityRequest()),
            ))->answer,
        ];

        foreach ($answers as $way => $answer) {
            expect($answer->structured?->isUsable())->toBeTrue($way)
                ->and($answer->structured?->data)->toBe(['city' => 'Zwolle'])
                ->and($answer->text)->toBe('This text is no JSON.');
        }
    });

    it('is trusted, not checked against the schema: what the adapter calls usable stays usable', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}', ['shaped' => ['city' => 7]]));

        $answer = Talk::gateway($transport)->complete(cityRequest());

        expect($answer->structured?->isUsable())->toBeTrue()
            ->and($answer->structured?->data)->toBe(['city' => 7]);
    });

    it('keeps the issues of the adapter, also when the text would pass', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}', ['shaped' => ['city' => 7], 'shaped_issue' => 'said the adapter']));

        $answer = Talk::gateway($transport)->complete(cityRequest());

        expect($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->reasons())->toBe(['/: said the adapter']);
    });

    it('is not asked of the schema, so a schema that cannot be used is not found then', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}', ['shaped' => ['city' => 'Zwolle']]));

        expect(Talk::gateway($transport)->complete(cityRequest(brokenFormat()))->structured?->data)->toBe(['city' => 'Zwolle']);
    });

    it('is dropped when the request has no response format', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}', ['shaped' => ['city' => 'Zwolle']]));

        expect(Talk::gateway($transport)->complete(Talk::request())->structured)->toBeNull();
    });
});

describe('a response schema the validator cannot use', function (): void {
    it('ends complete() as rejected with the trace id of the call, after one attempt and without a retry', function (): void {
        $clock = new FakeClock();
        $observer = new RecordingObserver();
        $transport = (new FakeTransport($clock))->willReturn(Talk::answered('{"city":"Utrecht"}'));
        $gateway = Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.0], 5.0), observer: $observer);

        $error = Talk::errorOf(fn() => $gateway->complete(cityRequest(brokenFormat())));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->detail)->toBe('the response schema is not a valid JSON Schema: the keyword required is not valid')
            ->and($error->traceId)->toBe('trace-1')
            ->and($error->attempts)->toBe(1)
            ->and($error->httpStatus)->toBe(200)
            ->and($error->getPrevious())->toBeNull()
            ->and($transport->requests())->toHaveCount(1)
            ->and($clock->sleeps())->toBe([])
            ->and($observer->finished)->toHaveCount(1)
            ->and($observer->finished[0]->outcome)->toBe(AttemptOutcome::HttpError)
            ->and($observer->finished[0]->willRetry)->toBeFalse();
    });

    it('ends a stream as a failed event with the text so far, after the text was given', function (): void {
        $events = Talk::events(Talk::gateway((new FakeTransport())->willStream(200, [], ...talkStreamOf('{"city":"Utrecht"}')))->stream(cityRequest(brokenFormat())));
        $failed = Talk::failedIn($events);

        expect($failed->error->reason)->toBe(ErrorReason::Rejected)
            ->and($failed->error->detail)->toBe('the response schema is not a valid JSON Schema: the keyword required is not valid')
            ->and($failed->error->traceId)->toBe('trace-1')
            ->and($failed->error->attempts)->toBe(1)
            ->and($failed->textSoFar)->toBe('{"city":"Utrecht"}');
    });

    it('ends a stream of a format without streaming as one failed event', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}'));
        $events = Talk::events(Talk::gateway($transport, options: ['streams' => false])->stream(cityRequest(brokenFormat())));

        expect($events)->toHaveCount(1)
            ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::Rejected);
    });

    it('is not found for an answer that is not JSON or was cut off: that answer is returned with its reason', function (): void {
        expect(answersOf('no JSON', 'done', brokenFormat())['complete']->structured?->issues[0]->kind)->toBe(StructuredIssueKind::NotJson)
            ->and(answersOf('{"city":"Utrecht"}', 'cut', brokenFormat())['stream']->structured?->reasons())->toBe(['cut off at the token limit']);
    });

    it('leaves nothing of the answer or the schema in the error, in any output form', function (): void {
        foreach ([false, true] as $ignoreArgs) {
            withExceptionIgnoreArgs($ignoreArgs, function (): void {
                $format = new ResponseFormat(JsonSchema::fromJson('{"type":"object","properties":{"' . LEAK_MARKER . '":{"type":[null]}}}'));
                $text = '{"' . LEAK_MARKER . '":"' . LEAK_MARKER . '"}';

                $error = Talk::errorOf(fn() => Talk::gateway((new FakeTransport())->willReturn(Talk::answered($text)))->complete(cityRequest($format)));
                $failed = Talk::failedIn(Talk::events(Talk::gateway((new FakeTransport())->willStream(200, [], ...talkStreamOf($text)))->stream(cityRequest($format))));

                expect($error->reason)->toBe(ErrorReason::Rejected)
                    ->and($failed->error->reason)->toBe(ErrorReason::Rejected);
                expectNoMarker(exceptionForms($error));
                expectNoMarker(exceptionForms($failed->error));
            });
        }
    });
});

describe('an answer whose check goes over the limits of one check', function (): void {
    it('reaches the caller with its text, its usage and one reason, for complete() and for a stream', function (): void {
        $format = new ResponseFormat(JsonSchema::fromJson('{"type":"object","properties":{"next":{"allOf":[{"$ref":"#"},{"$ref":"#"}]}}}'), 'verdict');
        $text = str_repeat('{"next":', 30) . '{}' . str_repeat('}', 30);

        foreach (answersOf($text, 'done', $format) as $way => $answer) {
            expect($answer->text)->toBe($text, $way)
                ->and($answer->usage->outputTokens)->not->toBeNull()
                ->and($answer->attempts)->toBe(1)
                ->and($answer->finishReason)->toBe(FinishReason::Stop)
                ->and($answer->structured?->isUsable())->toBeFalse()
                ->and($answer->structured?->data)->toBe(json_decode($text, true))
                ->and($answer->structured?->reasons())->toBe(['/: could not be checked against the schema within the limits of one check']);
        }
    });

    it('still ends as rejected when the schema refers back to itself, whatever the answer is', function (): void {
        $format = new ResponseFormat(JsonSchema::fromJson('{"type":"object","$ref":"#"}'), 'verdict');

        $error = Talk::errorOf(fn() => Talk::gateway((new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}')))->complete(cityRequest($format)));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->detail)->toBe('the response schema is not a valid JSON Schema: a reference in it leads back to itself');
    });
});

describe('structured output that an adapter emulates', function (): void {
    it('sends the schema as the last of the system text and no response format, and returns the checked answer', function (): void {
        $instructions = (new StructuredOutputEmulation())->instructions(cityFormat());

        foreach (['complete', 'stream', 'stream without streaming'] as $way) {
            $transport = $way === 'stream'
                ? (new FakeTransport())->willStream(200, [], ...talkStreamOf('{"city":"Utrecht"}'))
                : (new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}'));
            $gateway = Talk::gateway($transport, options: ['structured' => 'emulated', 'streams' => $way !== 'stream without streaming']);

            $answer = $way === 'complete'
                ? $gateway->complete(cityRequest())
                : Talk::finishedIn(Talk::events($gateway->stream(cityRequest())))->answer;

            $body = json_decode($transport->requests()[0]->body, true);
            assert(is_array($body));

            expect($body['lines'])->toBe([
                ['who' => 'system', 'say' => $instructions],
                ['who' => 'user', 'say' => 'Say hello.'],
            ], $way)
                ->and($body['shape'])->toBeNull()
                ->and($answer->structured?->isUsable())->toBeTrue()
                ->and($answer->structured?->data)->toBe(['city' => 'Utrecht'])
                ->and($answer->toolCalls)->toBe([])
                ->and($answer->finishReason)->toBe(FinishReason::Stop);
        }
    });

    it('keeps the system messages of the caller first and unchanged, and adds the instructions after them', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}'));
        $request = new Request(
            'model-a',
            [
                Message::system('You are a guide.'),
                Message::system('Answer in Dutch.'),
                Message::user('Where?'),
                Message::assistant('Which country?'),
                Message::user('The Netherlands.'),
            ],
            30.0,
            responseFormat: cityFormat(),
        );

        Talk::gateway($transport, options: ['structured' => 'emulated'])->complete($request);

        $body = json_decode($transport->requests()[0]->body, true);
        assert(is_array($body));

        expect(array_column($body['lines'], 'who'))->toBe(['system', 'system', 'system', 'user', 'assistant', 'user'])
            ->and(array_column($body['lines'], 'say'))->toBe([
                'You are a guide.',
                'Answer in Dutch.',
                (new StructuredOutputEmulation())->instructions(cityFormat()),
                'Where?',
                'Which country?',
                'The Netherlands.',
            ])
            ->and($request->messages)->toHaveCount(5);
    });

    it('adds the instructions to a request that holds only system messages', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}'));
        $request = new Request('model-a', [Message::system('Name a city.')], 30.0, responseFormat: cityFormat());

        Talk::gateway($transport, options: ['structured' => 'emulated'])->complete($request);

        $body = json_decode($transport->requests()[0]->body, true);
        assert(is_array($body));

        expect(array_column($body['lines'], 'who'))->toBe(['system', 'system']);
    });

    it('adds nothing when the request has no response format, or when the model has structured output of its own', function (): void {
        $emulated = (new FakeTransport())->willReturn(Talk::answered());
        $native = (new FakeTransport())->willReturn(Talk::answered('{"city":"Utrecht"}'));

        Talk::gateway($emulated, options: ['structured' => 'emulated'])->complete(Talk::request());
        Talk::gateway($native)->complete(cityRequest());

        $plainBody = json_decode($emulated->requests()[0]->body, true);
        $nativeBody = json_decode($native->requests()[0]->body, true);
        assert(is_array($plainBody) && is_array($nativeBody));

        expect($plainBody['lines'])->toBe([['who' => 'user', 'say' => 'Say hello.']])
            ->and($nativeBody['lines'])->toBe([['who' => 'user', 'say' => 'Say hello.']])
            ->and($nativeBody['shape'])->toBe('verdict');
    });

    it('returns what an emulated model got wrong the same way as for any other model', function (string $text, string $end, array $reasons): void {
        foreach (answersOf($text, $end, null, ['structured' => 'emulated']) as $way => $answer) {
            expect($answer->structured?->isUsable())->toBeFalse($way)
                ->and($answer->structured?->reasons())->toBe($reasons);
        }
    })->with([
        'a mismatch' => ['{"city":7}', 'done', ['/city: The data (integer) must match the type: string']],
        'a missing property, in a fence' => ["```json\n{}\n```", 'done', ['/: The required properties (city) are missing']],
        'JSON that is no object' => ['"Utrecht"', 'done', ['/: must be an object']],
        'cut off' => ['{"city":"Ut', 'cut', ['cut off at the token limit']],
        'cut off before any output' => ['', 'cut', ['cut off at the token limit']],
    ]);

    it('returns prose around the JSON of an emulated model as not JSON', function (): void {
        foreach (answersOf('Sure! {"city":"Utrecht"}', 'done', null, ['structured' => 'emulated']) as $way => $answer) {
            expect($answer->structured?->issues[0]->kind)->toBe(StructuredIssueKind::NotJson, $way)
                ->and($answer->structured?->data)->toBeNull();
        }
    });
});
