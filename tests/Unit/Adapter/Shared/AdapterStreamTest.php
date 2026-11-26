<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
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
use OrganiseYou\LlmGateway\Tests\Support\RecordingObserver;
use OrganiseYou\LlmGateway\Tests\Support\SlowStreamTransport;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

/**
 * The chunks of a whole stream of the made-up format: two pieces of text, one tool call, the usage, the end.
 *
 * @return list<string>
 */
function talkStream(): array
{
    return [
        Talk::event('say', ['piece' => 'Hel']),
        Talk::event('say', ['piece' => 'lo.']),
        Talk::event('call_open', ['ref' => 'c1', 'tool' => 'lookup']),
        Talk::event('call', ['ref' => 'c1', 'tool' => 'lookup', 'input' => '{"q":"x"}']),
        Talk::event('used', ['in' => 3, 'out' => 2]),
        Talk::event('end', ['end' => 'calls', 'by' => 'model-a-0001']),
    ];
}

/**
 * @param list<StreamEvent> $events
 * @return list<string>
 */
function eventKinds(array $events): array
{
    return array_map(fn(StreamEvent $event): string => (new ReflectionClass($event))->getShortName(), $events);
}

/**
 * The one failed event a stream ended with.
 *
 * @param list<StreamEvent> $events
 */
function failedEvent(array $events): StreamFailed
{
    $last = $events[count($events) - 1] ?? null;
    if (! $last instanceof StreamFailed) {
        throw new LogicException('The stream did not end with a failed event.');
    }

    return $last;
}

describe('stream() on a format that streams', function (): void {
    it('sends nothing before the caller starts to iterate', function (): void {
        $tokens = Talk::tokens();
        $transport = (new FakeTransport())->willStream(200, [], ...talkStream());

        $stream = Talk::gateway($transport, credentials: new BearerToken($tokens))->stream(Talk::request());

        expect($transport->requests())->toBe([])
            ->and($tokens->asked)->toBe(0);

        Talk::events($stream);

        expect($transport->requests())->toHaveCount(1)
            ->and($tokens->asked)->toBe(1);
    });

    it('gives the neutral events in order, with keys that count up, and ends with the answer a completion would return', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willDelay(0.75)->willStream(200, ['Content-Type' => 'text/event-stream'], ...talkStream());

        $events = [];
        foreach (Talk::gateway($transport, $clock)->stream(Talk::request()) as $key => $event) {
            expect($key)->toBe(count($events));
            $events[] = $event;
        }

        expect(eventKinds($events))->toBe(['TextDelta', 'TextDelta', 'ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished']);

        [$first, $second, $started, $completed, $usage, $finished] = $events;
        assert($first instanceof TextDelta && $second instanceof TextDelta && $started instanceof ToolCallStarted);
        assert($completed instanceof ToolCallCompleted && $usage instanceof UsageReported && $finished instanceof StreamFinished);

        expect($first->text . $second->text)->toBe('Hello.')
            ->and($started->index)->toBe(0)
            ->and($started->id)->toBe('c1')
            ->and($started->name)->toBe('lookup')
            ->and($completed->index)->toBe(0)
            ->and($completed->toolCall->arguments)->toBe(['q' => 'x'])
            ->and($usage->usage)->toEqual(new Usage(3, 2))
            ->and($finished->answer->text)->toBe('Hello.')
            ->and($finished->answer->toolCalls)->toBe([$completed->toolCall])
            ->and($finished->answer->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($finished->answer->model)->toBe('model-a-0001')
            ->and($finished->answer->usage)->toEqual(new Usage(3, 2))
            ->and($finished->answer->attempts)->toBe(1)
            ->and($finished->answer->latencyMs)->toBe(750)
            ->and($finished->answer->traceId)->toBe('trace-1')
            ->and($transport->requests()[0]->url)->toBe('https://talk.example.test/api/talk?flow=1')
            ->and(json_decode($transport->requests()[0]->body, true))->toMatchArray(['stream' => true]);
    });

    it('gives the same events when the bytes arrive cut anywhere', function (): void {
        $bytes = implode('', talkStream());
        $transport = (new FakeTransport())->willStream(200, [], ...str_split($bytes, 7));

        $events = Talk::events(Talk::gateway($transport)->stream(Talk::request()));

        expect(eventKinds($events))->toBe(['TextDelta', 'TextDelta', 'ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished']);
    });

    it('stops reading at the end of the answer, also when the server sends more', function (): void {
        $transport = (new FakeTransport())->willStream(200, [], Talk::event('say', ['piece' => 'Hi']), Talk::event('end', ['end' => 'done']), Talk::event('say', ['piece' => 'more']));

        expect(eventKinds(Talk::events(Talk::gateway($transport)->stream(Talk::request()))))->toBe(['TextDelta', 'StreamFinished']);
    });
});

describe('stream() on a format without streaming', function (): void {
    it('gives the whole answer as one text event, then the tool calls, the usage and the end', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('Hello.', ['end' => 'calls', 'calls' => [
            ['ref' => 'c1', 'tool' => 'lookup', 'input' => '{"q":"x"}'],
            ['ref' => 'c2', 'tool' => 'lookup', 'input' => '{"q":'],
        ]]));

        $events = [];
        foreach (Talk::gateway($transport, options: ['streams' => false])->stream(Talk::request()) as $key => $event) {
            expect($key)->toBe(count($events));
            $events[] = $event;
        }

        expect(eventKinds($events))->toBe(['TextDelta', 'ToolCallStarted', 'ToolCallCompleted', 'ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished']);

        $text = $events[0];
        $secondStarted = $events[3];
        $secondCompleted = $events[4];
        $finished = $events[6];
        assert($text instanceof TextDelta && $secondStarted instanceof ToolCallStarted && $secondCompleted instanceof ToolCallCompleted && $finished instanceof StreamFinished);

        expect($text->text)->toBe('Hello.')
            ->and($secondStarted->index)->toBe(1)
            ->and($secondStarted->id)->toBe('c2')
            ->and($secondCompleted->index)->toBe(1)
            ->and($secondCompleted->toolCall->isReadable())->toBeFalse()
            ->and($finished->answer->text)->toBe('Hello.')
            ->and($finished->answer->toolCalls)->toHaveCount(2)
            ->and($finished->answer->attempts)->toBe(1)
            ->and($finished->answer->traceId)->toBe('trace-1')
            // A normal request, not a stream.
            ->and($transport->requests()[0]->url)->toBe('https://talk.example.test/api/talk')
            ->and(json_decode($transport->requests()[0]->body, true))->toMatchArray(['stream' => false]);
    });

    it('gives no text event for an answer without text and no usage event when no tokens are known', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('', ['end' => 'calls', 'used' => null, 'calls' => [['ref' => 'c1', 'tool' => 'lookup', 'input' => '{}']]]));

        expect(eventKinds(Talk::events(Talk::gateway($transport, options: ['streams' => false])->stream(Talk::request()))))
            ->toBe(['ToolCallStarted', 'ToolCallCompleted', 'StreamFinished']);
    });

    it('sends nothing before the caller starts to iterate', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());

        $stream = Talk::gateway($transport, options: ['streams' => false])->stream(Talk::request());
        expect($transport->requests())->toBe([]);

        Talk::events($stream);
        expect($transport->requests())->toHaveCount(1);
    });

    it('retries like a completion, and gives a failure as the one failed event', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(503))->willReturn(Talk::answered())->willReturn(Talk::failed(401, 'key_unknown'));
        $gateway = Talk::gateway($transport, $clock, new RetryPolicy([1.0], 0.0), options: ['streams' => false]);

        $retried = Talk::events($gateway->stream(Talk::request()));
        $failed = Talk::events($gateway->stream(Talk::request()));

        $finished = $retried[count($retried) - 1];
        assert($finished instanceof StreamFinished);

        expect($finished->answer->attempts)->toBe(2)
            ->and($clock->sleeps())->toBe([1.0])
            ->and($failed)->toHaveCount(1)
            ->and(failedEvent($failed)->error->reason)->toBe(ErrorReason::CredentialsRejected)
            ->and(failedEvent($failed)->error->providerCode)->toBe('key_unknown')
            ->and(failedEvent($failed)->textSoFar)->toBe('')
            ->and(failedEvent($failed)->toolCallsSoFar)->toBe([]);
    });
});

describe('a stream that fails before its first event', function (): void {
    it('is retried by the retry policy', function (Closure $script): void {
        $clock = new FakeClock();
        $transport = new FakeTransport($clock);
        $script($transport);
        $transport->willStream(200, [], ...talkStream());

        $events = Talk::events(Talk::gateway($transport, $clock, new RetryPolicy([1.5], 10.0))->stream(Talk::request()));

        $finished = $events[count($events) - 1];
        assert($finished instanceof StreamFinished);

        expect(eventKinds($events))->toBe(['TextDelta', 'TextDelta', 'ToolCallStarted', 'ToolCallCompleted', 'UsageReported', 'StreamFinished'])
            ->and($finished->answer->attempts)->toBe(2)
            ->and($finished->answer->latencyMs)->toBe(1500)
            ->and($clock->sleeps())->toBe([1.5])
            ->and($transport->requests())->toHaveCount(2);
    })->with([
        'a connection that cannot be made' => [fn(FakeTransport $transport) => $transport->willFail(TransportFailure::ConnectionFailed)],
        'a connection that breaks before the first chunk' => [fn(FakeTransport $transport) => $transport->willStream(200, [], Talk::event('say', ['piece' => 'never read']))->willBreakStreamAfter(0, TransportFailure::ConnectionFailed)],
        'a connection that breaks inside the first event' => [fn(FakeTransport $transport) => $transport->willStream(200, [], 'event: say', "\ndata: {\"pie")->willBreakStreamAfter(2, TransportFailure::ConnectionFailed)],
        'a server error' => [fn(FakeTransport $transport) => $transport->willStream(503, [], '{"fault":{"code":"busy"}}')],
        'a rate limit' => [fn(FakeTransport $transport) => $transport->willStream(429, [], '')],
    ]);

    it('waits as long as the provider asks, and fails at once when that is longer than the caller accepts', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))
            ->willStream(429, ['Retry-After' => '3'], '{"fault":{"code":"slow_down"}}')
            ->willStream(429, ['Retry-After' => '30'], '{"fault":', '{"code":"slow_down"}}');

        $events = Talk::events(Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.0, 1.0], 10.0))->stream(Talk::request()));

        expect($events)->toHaveCount(1)
            ->and(failedEvent($events)->error->reason)->toBe(ErrorReason::RateLimited)
            ->and(failedEvent($events)->error->retryAfterSeconds)->toBe(30)
            ->and(failedEvent($events)->error->providerCode)->toBe('slow_down')
            ->and(failedEvent($events)->error->httpStatus)->toBe(429)
            ->and(failedEvent($events)->error->attempts)->toBe(2)
            ->and($clock->sleeps())->toBe([3.0]);
    });

    it('ends in one failed event with nothing so far when it still fails after the retries', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willFail(TransportFailure::ConnectionFailed)->willStream(503, [], '');

        $events = Talk::events(Talk::gateway($transport, $clock, new RetryPolicy([1.0], 0.0))->stream(Talk::request()));

        expect($events)->toHaveCount(1)
            ->and(failedEvent($events)->error->reason)->toBe(ErrorReason::Unavailable)
            ->and(failedEvent($events)->error->httpStatus)->toBe(503)
            ->and(failedEvent($events)->error->attempts)->toBe(2)
            ->and(failedEvent($events)->error->traceId)->toBe('trace-1')
            ->and(failedEvent($events)->textSoFar)->toBe('')
            ->and(failedEvent($events)->toolCallsSoFar)->toBe([]);
    });

    it('is not retried for what a completion does not retry either', function (Closure $script, ErrorReason $reason, ?int $status): void {
        $clock = new FakeClock();
        $transport = new FakeTransport($clock);
        $script($transport);

        $events = Talk::events(Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.0], 60.0))->stream(Talk::request()));

        expect($events)->toHaveCount(1)
            ->and(failedEvent($events)->error->reason)->toBe($reason)
            ->and(failedEvent($events)->error->httpStatus)->toBe($status)
            ->and(failedEvent($events)->error->attempts)->toBe(1)
            ->and($transport->requests())->toHaveCount(1)
            ->and($clock->sleeps())->toBe([]);
    })->with([
        'a refused sign-in' => [fn(FakeTransport $transport) => $transport->willStream(401, [], '{"fault":{"code":"key_unknown"}}'), ErrorReason::CredentialsRejected, 401],
        'a refused request' => [fn(FakeTransport $transport) => $transport->willStream(400, [], '{"fault":{"code":"bad_input"}}'), ErrorReason::Rejected, 400],
        'a redirect the transport reports' => [fn(FakeTransport $transport) => $transport->willFail(TransportFailure::Redirected), ErrorReason::Unavailable, null],
        'a 3xx response' => [fn(FakeTransport $transport) => $transport->willStream(302, ['Location' => 'https://other.example.test/'], ''), ErrorReason::Unavailable, 302],
        'a request the host blocked' => [fn(FakeTransport $transport) => $transport->willFail(TransportFailure::Blocked), ErrorReason::NotConfigured, null],
        'a timeout of the transport' => [fn(FakeTransport $transport) => $transport->willFail(TransportFailure::TimedOut), ErrorReason::Timeout, null],
        'an error inside the stream' => [fn(FakeTransport $transport) => $transport->willStream(200, [], Talk::event('fault', ['fault' => ['code' => 'model_busy']])), ErrorReason::ProviderError, 200],
        'an answer without text and without a tool call' => [fn(FakeTransport $transport) => $transport->willStream(200, [], Talk::event('end', ['end' => 'done'])), ErrorReason::ProviderError, 200],
        'a stream that ends without its last event' => [fn(FakeTransport $transport) => $transport->willStream(200, [], ''), ErrorReason::ProviderError, 200],
        'a body that cannot be decoded' => [fn(FakeTransport $transport) => $transport->willStream(200, [], "event: say\ndata: not json\n\n"), ErrorReason::ProviderError, 200],
        'an exception of the adapter' => [fn(FakeTransport $transport) => $transport->willStream(200, [], Talk::event('say', ['explode' => 'exception'])), ErrorReason::ProviderError, 200],
    ]);

    it('keeps the code of the provider for an error status and for an error inside the stream', function (): void {
        $transport = (new FakeTransport())
            ->willStream(401, [], '{"fault":{"co', 'de":"Key_Unknown"}}')
            ->willStream(200, [], Talk::event('fault', ['fault' => ['code' => 'Model_Busy']]));
        $gateway = Talk::gateway($transport);

        expect(failedEvent(Talk::events($gateway->stream(Talk::request())))->error->providerCode)->toBe('key_unknown')
            ->and(failedEvent(Talk::events($gateway->stream(Talk::request())))->error->providerCode)->toBe('model_busy');
    });
});

describe('a stream that breaks after its first event', function (): void {
    it('reports failed with what arrived, and is not retried', function (): void {
        $clock = new FakeClock();
        $chunks = talkStream();
        $transport = (new FakeTransport($clock))
            ->willStream(200, [], ...$chunks)->willBreakStreamAfter(4, TransportFailure::ConnectionFailed)
            ->willStream(200, [], ...$chunks);

        $events = Talk::events(Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.0], 60.0))->stream(Talk::request()));

        $failed = failedEvent($events);
        expect(eventKinds($events))->toBe(['TextDelta', 'TextDelta', 'ToolCallStarted', 'ToolCallCompleted', 'StreamFailed'])
            ->and($failed->error->reason)->toBe(ErrorReason::Unavailable)
            ->and($failed->error->attempts)->toBe(1)
            ->and($failed->error->traceId)->toBe('trace-1')
            ->and($failed->textSoFar)->toBe('Hello.')
            ->and($failed->toolCallsSoFar)->toHaveCount(1)
            ->and($failed->toolCallsSoFar[0]->id)->toBe('c1')
            ->and($transport->requests())->toHaveCount(1)
            ->and($transport->remaining())->toBe(1)
            ->and($clock->sleeps())->toBe([]);
    });

    it('is not retried after one event either', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))
            ->willStream(200, [], Talk::event('say', ['piece' => 'Hel']), Talk::event('say', ['piece' => 'lo.']))->willBreakStreamAfter(1, TransportFailure::ConnectionFailed)
            ->willStream(200, [], ...talkStream());

        $events = Talk::events(Talk::gateway($transport, $clock, new RetryPolicy([1.0], 60.0))->stream(Talk::request()));

        expect(eventKinds($events))->toBe(['TextDelta', 'StreamFailed'])
            ->and(failedEvent($events)->textSoFar)->toBe('Hel')
            ->and(failedEvent($events)->toolCallsSoFar)->toBe([])
            ->and($transport->requests())->toHaveCount(1)
            ->and($clock->sleeps())->toBe([]);
    });

    it('reports failed with what arrived for an error inside the stream and for a stream that ends without its last event', function (string $lastChunk, ?string $code): void {
        $transport = (new FakeTransport())->willStream(200, [], Talk::event('say', ['piece' => 'Hel']), $lastChunk);

        $events = Talk::events(Talk::gateway($transport, null, new RetryPolicy([1.0], 60.0))->stream(Talk::request()));

        expect(eventKinds($events))->toBe(['TextDelta', 'StreamFailed'])
            ->and(failedEvent($events)->error->reason)->toBe(ErrorReason::ProviderError)
            ->and(failedEvent($events)->error->providerCode)->toBe($code)
            ->and(failedEvent($events)->textSoFar)->toBe('Hel')
            ->and($transport->requests())->toHaveCount(1);
    })->with([
        'an error event' => [Talk::event('fault', ['fault' => ['code' => 'model_busy']]), 'model_busy'],
        'no last event' => ['', null],
    ]);
});

describe('stream() and the gateway exception', function (): void {
    it('never throws one: a token supplier that fails is the failed event, and nothing is sent', function (): void {
        $tokens = Talk::tokens();
        $tokens->fails = 'exception';
        $transport = new FakeTransport();

        $events = Talk::events(Talk::gateway($transport, credentials: new BearerToken($tokens))->stream(Talk::request()));

        expect($events)->toHaveCount(1)
            ->and(failedEvent($events)->error->reason)->toBe(ErrorReason::CredentialsRejected)
            ->and(failedEvent($events)->error->attempts)->toBe(0)
            ->and(failedEvent($events)->error->traceId)->toBe('trace-1')
            ->and($transport->requests())->toBe([]);
    });

    it('lets an error in the code of the adapter pass as a new error with a fixed text', function (): void {
        $transport = (new FakeTransport())->willStream(200, [], Talk::event('say', ['explode' => 'error']));
        $gateway = Talk::gateway($transport, null, new RetryPolicy([1.0], 60.0));

        expect(fn() => Talk::events($gateway->stream(Talk::request())))
            ->toThrow(Error::class, 'The adapter failed with an error of class DivisionByZeroError, thrown in TalkAdapter.php on line')
            ->and($transport->requests())->toHaveCount(1);
    });
});

describe('an adapter that gives a value that is not a stream event', function (): void {
    it('ends in an error with a fixed text, after the report of the attempt: the value never reaches the caller', function (): void {
        $observer = new RecordingObserver();
        $transport = (new FakeTransport())->willStream(200, [], Talk::event('say', ['piece' => 'Hi']), Talk::event('junk', ['piece' => 'words of the provider']), Talk::event('end', ['end' => 'done']));
        $gateway = Talk::gateway($transport, null, new RetryPolicy([0.0], 0.0), observer: $observer);

        $seen = [];
        $message = null;
        try {
            foreach ($gateway->stream(Talk::request()) as $event) {
                $seen[] = $event;
            }
        } catch (Error $error) {
            $message = $error->getMessage();
        }

        expect(eventKinds($seen))->toBe(['TextDelta'])
            ->and($message)->toBe('The adapter gave a value that is not a stream event.')
            ->and($observer->started)->toHaveCount(1)
            ->and($observer->finished)->toHaveCount(1)
            ->and($transport->requests())->toHaveCount(1);
    });
});

describe('the deadline of a stream', function (): void {
    it('covers the whole stream: a chunk that arrives after it ends the stream as timeout, with what arrived', function (): void {
        $clock = new FakeClock();
        $transport = new SlowStreamTransport($clock, talkStream(), 4.0);

        $events = Talk::events(Talk::gateway($transport, $clock, new RetryPolicy([1.0], 60.0))->stream(Talk::request(10.0)));

        $failed = failedEvent($events);
        expect(eventKinds($events))->toBe(['TextDelta', 'TextDelta', 'StreamFailed'])
            ->and($failed->error->reason)->toBe(ErrorReason::Timeout)
            ->and($failed->error->attempts)->toBe(1)
            ->and($failed->textSoFar)->toBe('Hello.')
            ->and($transport->chunksGiven)->toBe(3)
            ->and($transport->released)->toBeTrue()
            ->and($transport->requests)->toHaveCount(1)
            ->and($clock->sleeps())->toBe([]);
    });

    it('does not retry a stream whose first chunk arrives after the deadline', function (): void {
        $clock = new FakeClock();
        $transport = new SlowStreamTransport($clock, talkStream(), 11.0);

        $events = Talk::events(Talk::gateway($transport, $clock, new RetryPolicy([0.0], 60.0))->stream(Talk::request(10.0)));

        expect($events)->toHaveCount(1)
            ->and(failedEvent($events)->error->reason)->toBe(ErrorReason::Timeout)
            ->and($transport->requests)->toHaveCount(1);
    });

    it('lets a stream that is slow but in time finish', function (): void {
        $clock = new FakeClock();
        $transport = new SlowStreamTransport($clock, talkStream(), 1.0);

        $events = Talk::events(Talk::gateway($transport, $clock)->stream(Talk::request(10.0)));

        $finished = $events[count($events) - 1];
        assert($finished instanceof StreamFinished);
        expect($finished->answer->latencyMs)->toBe(6000);
    });

    it('ends as timeout when the error body of a failed stream arrives after it', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willDelay(11.0)->willStream(503, [], '{"fault":{"code":"busy"}}');

        $events = Talk::events(Talk::gateway($transport, $clock, new RetryPolicy([0.0], 60.0))->stream(Talk::request(10.0)));

        expect(failedEvent($events)->error->reason)->toBe(ErrorReason::Timeout)
            ->and($transport->requests())->toHaveCount(1);
    });
});

describe('a caller that stops reading a stream', function (): void {
    it('makes the adapter stop reading and lets the chunks of the transport go', function (): void {
        $clock = new FakeClock();
        $transport = new SlowStreamTransport($clock, talkStream(), 0.1);

        foreach (Talk::gateway($transport, $clock)->stream(Talk::request()) as $event) {
            expect($event)->toBeInstanceOf(TextDelta::class);

            break;
        }

        expect($transport->chunksGiven)->toBe(1)
            ->and($transport->released)->toBeTrue();
    });
});

describe('a 2xx stream answered by a server that does not stream', function (): void {
    it('is read by the adapter, or ends as provider_error: never a warning', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"say":"Hello."}'));

        $events = Talk::events(Talk::gateway($transport)->stream(Talk::request()));

        expect(failedEvent($events)->error->reason)->toBe(ErrorReason::ProviderError);
    });
});
