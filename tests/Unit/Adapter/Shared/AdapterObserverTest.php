<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Operation;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\RecordingObserver;
use OrganiseYou\LlmGateway\Tests\Support\SlowStreamTransport;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\AttemptOutcome;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

/**
 * What a caller can see of a stream, without the objects themselves.
 *
 * @param iterable<int, StreamEvent> $stream
 * @return list<string>
 */
function streamSeen(iterable $stream): array
{
    $seen = [];
    foreach ($stream as $event) {
        $seen[] = match (true) {
            $event instanceof StreamFinished => 'finished ' . json_encode([$event->answer->text, $event->answer->attempts, $event->answer->latencyMs, $event->answer->traceId]),
            $event instanceof StreamFailed => 'failed ' . json_encode([$event->error, $event->textSoFar, count($event->toolCallsSoFar)]),
            default => $event::class . ' ' . json_encode($event),
        };
    }

    return $seen;
}

describe('the attempt observer', function (): void {
    it('is told before and after every attempt, also the attempts that are retried', function (): void {
        $clock = new FakeClock();
        $observer = new RecordingObserver();
        $transport = (new FakeTransport($clock))
            ->willDelay(0.2)->willReturn(Talk::failed(503, 'busy'))
            ->willFail(TransportFailure::ConnectionFailed)
            ->willDelay(0.4)->willReturn(Talk::answered());

        Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.0], 0.0), observer: $observer)->complete(Talk::request());

        expect($observer->started)->toHaveCount(3)
            ->and($observer->finished)->toHaveCount(3);

        foreach ($observer->started as $index => $started) {
            expect($started->presetId)->toBe('talk-test')
                ->and($started->operation)->toBe(Operation::Complete)
                ->and($started->traceId)->toBe('trace-1')
                ->and($started->attempt)->toBe($index + 1)
                ->and($started->request)->toBe($transport->requests()[$index]);
        }

        [$first, $second, $third] = $observer->finished;
        expect($first->presetId)->toBe('talk-test')
            ->and($first->operation)->toBe(Operation::Complete)
            ->and($first->traceId)->toBe('trace-1')
            ->and($first->attempt)->toBe(1)
            ->and($first->request)->toBe($transport->requests()[0])
            ->and($first->response?->status)->toBe(503)
            ->and($first->response?->body)->toBe('{"fault":{"code":"busy"}}')
            ->and($first->outcome)->toBe(AttemptOutcome::HttpError)
            ->and($first->transportFailure)->toBeNull()
            ->and($first->willRetry)->toBeTrue()
            ->and($first->durationMs)->toBe(200)
            ->and($second->attempt)->toBe(2)
            ->and($second->response)->toBeNull()
            ->and($second->outcome)->toBe(AttemptOutcome::TransportError)
            ->and($second->transportFailure)->toBe(TransportFailure::ConnectionFailed)
            ->and($second->willRetry)->toBeTrue()
            ->and($second->durationMs)->toBe(0)
            ->and($third->attempt)->toBe(3)
            ->and($third->response?->status)->toBe(200)
            ->and($third->outcome)->toBe(AttemptOutcome::Succeeded)
            ->and($third->willRetry)->toBeFalse()
            ->and($third->durationMs)->toBe(400);
    });

    it('gets the request with the secret headers masked', function (): void {
        $observer = new RecordingObserver();
        $transport = (new FakeTransport())->willReturn(Talk::answered());

        Talk::gateway($transport, observer: $observer)->complete(Talk::request());

        $shown = $observer->started[0]->request->toArray();
        expect($shown['headers']['Authorization'])->toBe('[secret]')
            ->and(print_r($shown, true))->not->toContain('key-of-the-test')
            ->and(print_r($observer->finished[0]->request->toArray(), true))->not->toContain('key-of-the-test');
    });

    it('is told that an attempt will not be retried when its failure is the result of the call', function (Closure $script, AttemptOutcome $outcome): void {
        $observer = new RecordingObserver();
        $transport = new FakeTransport();
        $script($transport);

        Talk::errorOf(fn() => Talk::gateway($transport, null, new RetryPolicy([0.0], 0.0), observer: $observer)->complete(Talk::request()));

        expect($observer->finished)->toHaveCount(1)
            ->and($observer->finished[0]->outcome)->toBe($outcome)
            ->and($observer->finished[0]->willRetry)->toBeFalse();
    })->with([
        'a refusal' => [fn(FakeTransport $transport) => $transport->willReturn(Talk::failed(400)), AttemptOutcome::HttpError],
        'an error inside a 200' => [fn(FakeTransport $transport) => $transport->willReturn(new HttpResponse(200, [], '{"fault":{"code":"busy"}}')), AttemptOutcome::HttpError],
        'an answer without content' => [fn(FakeTransport $transport) => $transport->willReturn(Talk::answered('')), AttemptOutcome::HttpError],
        'a redirect' => [fn(FakeTransport $transport) => $transport->willFail(TransportFailure::Redirected), AttemptOutcome::TransportError],
    ]);

    it('is told nothing when no request is sent', function (): void {
        $observer = new RecordingObserver();
        $gateway = Talk::gateway(new FakeTransport(), observer: $observer, options: ['tools' => 'none', 'lists_models' => false]);

        $gateway->capabilities('model-a');
        Talk::errorOf(fn() => $gateway->models(5.0));
        Talk::errorOf(fn() => $gateway->check('model-a', 5.0));

        expect($observer->started)->toBe([])
            ->and($observer->finished)->toBe([]);
    });

    it('is told the operation: the model list and a stream, also a stream of a format without streaming', function (): void {
        $observer = new RecordingObserver();
        $transport = (new FakeTransport())
            ->willReturn(new HttpResponse(200, [], '{"items":[]}'))
            ->willStream(200, [], Talk::event('say', ['piece' => 'Hi']), Talk::event('end', ['end' => 'done']))
            ->willReturn(Talk::answered());

        Talk::gateway($transport, observer: $observer)->models(5.0, 'trace-m');
        Talk::events(Talk::gateway($transport, observer: $observer)->stream(Talk::request()));
        Talk::events(Talk::gateway($transport, observer: $observer, options: ['streams' => false])->stream(Talk::request()));

        expect(array_map(fn($report): Operation => $report->operation, $observer->started))->toBe([Operation::Models, Operation::Stream, Operation::Stream])
            ->and(array_map(fn($report): Operation => $report->operation, $observer->finished))->toBe([Operation::Models, Operation::Stream, Operation::Stream])
            ->and($observer->started[0]->traceId)->toBe('trace-m');
    });

    it('is told when a stream ends, with the status, the headers and the bytes that were read as the body', function (): void {
        $observer = new RecordingObserver();
        $chunks = [Talk::event('say', ['piece' => 'Hi']), Talk::event('end', ['end' => 'done'])];
        $transport = (new FakeTransport())->willStream(200, ['Content-Type' => 'text/event-stream'], ...$chunks);

        $seenBeforeTheEnd = null;
        foreach (Talk::gateway($transport, observer: $observer)->stream(Talk::request()) as $event) {
            $seenBeforeTheEnd ??= count($observer->finished);
        }

        expect($seenBeforeTheEnd)->toBe(0)
            ->and($observer->started)->toHaveCount(1)
            ->and($observer->finished)->toHaveCount(1)
            ->and($observer->finished[0]->outcome)->toBe(AttemptOutcome::Succeeded)
            ->and($observer->finished[0]->response?->status)->toBe(200)
            ->and($observer->finished[0]->response?->header('content-type'))->toBe('text/event-stream')
            ->and($observer->finished[0]->response?->body)->toBe(implode('', $chunks));
    });

    it('is told when a stream breaks, with what was read until then, and when a stream is tried again', function (): void {
        $observer = new RecordingObserver();
        $piece = Talk::event('say', ['piece' => 'Hi']);
        $transport = (new FakeTransport())
            ->willStream(503, [], '{"fault":', '{"code":"busy"}}')
            ->willStream(200, [], $piece, Talk::event('say', ['piece' => 'never']))->willBreakStreamAfter(1, TransportFailure::ConnectionFailed);

        Talk::events(Talk::gateway($transport, null, new RetryPolicy([0.0, 0.0], 0.0), observer: $observer)->stream(Talk::request()));

        expect($observer->started)->toHaveCount(2)
            ->and($observer->finished)->toHaveCount(2)
            ->and($observer->finished[0]->outcome)->toBe(AttemptOutcome::HttpError)
            ->and($observer->finished[0]->response?->body)->toBe('{"fault":{"code":"busy"}}')
            ->and($observer->finished[0]->willRetry)->toBeTrue()
            ->and($observer->finished[1]->attempt)->toBe(2)
            ->and($observer->finished[1]->outcome)->toBe(AttemptOutcome::TransportError)
            ->and($observer->finished[1]->transportFailure)->toBe(TransportFailure::ConnectionFailed)
            ->and($observer->finished[1]->response?->status)->toBe(200)
            ->and($observer->finished[1]->response?->body)->toBe($piece)
            ->and($observer->finished[1]->willRetry)->toBeFalse();
    });

    it('is told when the caller abandons a stream, once, with what was read until then', function (): void {
        $clock = new FakeClock();
        $observer = new RecordingObserver();
        $chunks = [Talk::event('say', ['piece' => 'Hel']), Talk::event('say', ['piece' => 'lo.']), Talk::event('end', ['end' => 'done'])];
        $transport = new SlowStreamTransport($clock, $chunks, 0.5);

        foreach (Talk::gateway($transport, $clock, observer: $observer)->stream(Talk::request()) as $event) {
            break;
        }

        expect($observer->finished)->toHaveCount(1)
            ->and($observer->finished[0]->outcome)->toBe(AttemptOutcome::Succeeded)
            ->and($observer->finished[0]->willRetry)->toBeFalse()
            ->and($observer->finished[0]->response?->body)->toBe($chunks[0])
            ->and($observer->finished[0]->durationMs)->toBe(500);
    });

    it('names the preset in the reports only: not in an answer, an error or a stream event', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(Talk::answered())
            ->willReturn(Talk::failed(401, 'key_unknown'))
            ->willStream(200, [], Talk::event('say', ['piece' => 'Hi']), Talk::event('end', ['end' => 'done']));
        $gateway = Talk::gateway($transport);

        $answer = $gateway->complete(Talk::request());
        $error = Talk::errorOf(fn() => $gateway->complete(Talk::request()));
        $events = Talk::events($gateway->stream(Talk::request()));

        expect(print_r([$answer, $events], true) . $error . json_encode($error))->not->toContain('talk-test');
    });
});

describe('an observer that throws', function (): void {
    it('does not change the result of a call, before or after an attempt', function (bool $before, bool $after, bool $errorBefore = false): void {
        $run = function (RecordingObserver $observer): array {
            $clock = new FakeClock();
            $transport = (new FakeTransport($clock))
                ->willDelay(0.3)->willReturn(Talk::failed(429, 'slow_down', ['Retry-After' => '2']))
                ->willFail(TransportFailure::ConnectionFailed)
                ->willDelay(0.1)->willReturn(Talk::answered('Hello.'))
                ->willReturn(Talk::failed(400, 'bad_input'))
                ->willReturn(new HttpResponse(200, [], '{"items":[{"key":"model-a"}]}'));
            $gateway = Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.5], 10.0), observer: $observer);

            $answer = $gateway->complete(Talk::request());
            $error = Talk::errorOf(fn() => $gateway->complete(Talk::request()));
            $models = $gateway->models(5.0, 'trace-m');

            return [
                'answer' => [$answer->text, $answer->attempts, $answer->latencyMs, $answer->traceId],
                'error' => json_encode($error),
                'models' => count($models),
                'sleeps' => $clock->sleeps(),
                'requests' => count($transport->requests()),
                'reports' => [count($observer->started), count($observer->finished)],
            ];
        };

        $quiet = $run(new RecordingObserver());
        $throwing = $run(new RecordingObserver($before, $after, $errorBefore));

        expect($throwing)->toBe($quiet)
            ->and($quiet['answer'])->toBe(['Hello.', 3, 3900, 'trace-1'])
            ->and($quiet['sleeps'])->toBe([2.0, 1.5])
            ->and($quiet['reports'])->toBe([5, 5]);
    })->with([
        'before an attempt' => [true, false],
        'after an attempt' => [false, true],
        'before and after' => [true, true],
        'an error, not an exception, before an attempt' => [false, false, true],
        'an error before and after' => [false, true, true],
    ]);

    it('does not change the events of a stream, before or after an attempt', function (bool $before, bool $after, bool $errorBefore = false): void {
        $run = function (RecordingObserver $observer): array {
            $clock = new FakeClock();
            $transport = (new FakeTransport($clock))
                ->willStream(503, [], '')
                ->willDelay(0.2)->willStream(200, [], Talk::event('say', ['piece' => 'Hi']), Talk::event('end', ['end' => 'done']))
                ->willStream(200, [], Talk::event('say', ['piece' => 'Hi']), Talk::event('say', ['piece' => 'never']))->willBreakStreamAfter(1, TransportFailure::ConnectionFailed)
                ->willReturn(Talk::answered('Whole.'));
            $gateway = Talk::gateway($transport, $clock, new RetryPolicy([1.0], 0.0), observer: $observer);
            $whole = Talk::gateway($transport, $clock, new RetryPolicy([1.0], 0.0), observer: $observer, options: ['streams' => false]);

            return [
                'finished' => streamSeen($gateway->stream(Talk::request())),
                'failed' => streamSeen($gateway->stream(Talk::request())),
                'whole' => streamSeen($whole->stream(Talk::request())),
                'sleeps' => $clock->sleeps(),
                'reports' => [count($observer->started), count($observer->finished)],
            ];
        };

        $quiet = $run(new RecordingObserver());
        $throwing = $run(new RecordingObserver($before, $after, $errorBefore));

        expect($throwing)->toBe($quiet)
            ->and($quiet['finished'])->toHaveCount(2)
            ->and($quiet['failed'])->toHaveCount(2)
            ->and($quiet['sleeps'])->toBe([1.0])
            ->and($quiet['reports'])->toBe([4, 4]);
    })->with([
        'before an attempt' => [true, false],
        'after an attempt' => [false, true],
        'before and after' => [true, true],
        'an error, not an exception, before an attempt' => [false, false, true],
    ]);

    it('does not change a stream the caller abandons', function (): void {
        $clock = new FakeClock();
        $observer = new RecordingObserver(true, true);
        $transport = new SlowStreamTransport($clock, [Talk::event('say', ['piece' => 'Hi']), Talk::event('end', ['end' => 'done'])], 0.1);

        $seen = 0;
        foreach (Talk::gateway($transport, $clock, observer: $observer)->stream(Talk::request()) as $event) {
            $seen++;

            break;
        }

        expect($seen)->toBe(1)
            ->and($observer->finished)->toHaveCount(1)
            ->and($transport->released)->toBeTrue();
    });
});
