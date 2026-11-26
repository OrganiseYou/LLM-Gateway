<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\SystemClock;
use OrganiseYou\LlmGateway\Transport\Transport;
use OrganiseYou\LlmGateway\Transport\TransportException;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

function fakeRequest(string $url = 'https://api.example.test/v1/chat'): HttpRequest
{
    return new HttpRequest('POST', $url, ['Content-Type' => 'application/json'], ['Authorization' => new Secret('Bearer ' . LEAK_MARKER)], 30.0, '{"model":"model-a"}');
}

/**
 * @param callable(): mixed $call
 */
function fakeFailureOf(callable $call): TransportException
{
    try {
        $call();
    } catch (TransportException $exception) {
        return $exception;
    }

    throw new LogicException('The call did not fail.');
}

describe('FakeTransport', function (): void {
    it('is a transport', function (): void {
        expect(new FakeTransport())->toBeInstanceOf(Transport::class);
    });

    it('returns the scripted responses in order', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(new HttpResponse(500, [], 'first'))
            ->willReturn(new HttpResponse(200, ['Content-Type' => 'application/json'], 'second'));

        $first = $transport->send(fakeRequest());
        $second = $transport->send(fakeRequest());

        expect($first->status)->toBe(500)
            ->and($first->body)->toBe('first')
            ->and($second->status)->toBe(200)
            ->and($second->header('content-type'))->toBe('application/json')
            ->and($second->body)->toBe('second')
            ->and($transport->remaining())->toBe(0);
    });

    it('records every request in order, also one that failed', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(new HttpResponse(200))
            ->willFail(TransportFailure::ConnectionFailed)
            ->willStream(200, [], 'a');
        $first = fakeRequest('https://api.example.test/v1/one');
        $second = fakeRequest('https://api.example.test/v1/two');
        $third = fakeRequest('https://api.example.test/v1/three');

        $transport->send($first);
        fakeFailureOf(fn() => $transport->send($second));
        $transport->stream($third);

        expect($transport->requests())->toBe([$first, $second, $third]);
    });

    it('starts with no request recorded', function (): void {
        expect((new FakeTransport())->requests())->toBe([]);
    });

    it('streams the scripted status, headers and chunks', function (): void {
        $transport = (new FakeTransport())->willStream(200, ['Content-Type' => 'text/event-stream'], 'data: one', "\n\n", 'data: two');

        $response = $transport->stream(fakeRequest());

        expect($response->status)->toBe(200)
            ->and($response->header('Content-Type'))->toBe('text/event-stream')
            ->and(iterator_to_array($response->chunks, false))->toBe(['data: one', "\n\n", 'data: two']);
    });

    it('streams no chunk when none is scripted', function (): void {
        $response = (new FakeTransport())->willStream(200, [])->stream(fakeRequest());

        expect(iterator_to_array($response->chunks, false))->toBe([]);
    });

    it('throws at once when a stream is scripted with a wrong status or header', function (): void {
        expect(fn() => (new FakeTransport())->willStream(99, []))->toThrow(InvalidArgumentException::class)
            ->and(fn() => (new FakeTransport())->willStream(200, untyped(['X-Header' => 1])))->toThrow(InvalidArgumentException::class);
    });

    it('breaks a stream with the failure after the scripted number of chunks', function (): void {
        $transport = (new FakeTransport())
            ->willStream(200, [], 'a', 'b', 'c')
            ->willBreakStreamAfter(2, TransportFailure::ConnectionFailed);

        $response = $transport->stream(fakeRequest());
        $seen = [];
        $exception = fakeFailureOf(function () use ($response, &$seen): void {
            foreach ($response->chunks as $chunk) {
                $seen[] = $chunk;
            }
        });

        expect($response->status)->toBe(200)
            ->and($seen)->toBe(['a', 'b'])
            ->and($exception->failure)->toBe(TransportFailure::ConnectionFailed);
    });

    it('breaks a stream before its first chunk and after its last', function (int $after, array $expected): void {
        $transport = (new FakeTransport())->willStream(200, [], 'a', 'b')->willBreakStreamAfter($after, TransportFailure::TimedOut);

        $response = $transport->stream(fakeRequest());
        $seen = [];
        $exception = fakeFailureOf(function () use ($response, &$seen): void {
            foreach ($response->chunks as $chunk) {
                $seen[] = $chunk;
            }
        });

        expect($seen)->toBe($expected)
            ->and($exception->failure)->toBe(TransportFailure::TimedOut);
    })->with([
        'before the first' => [0, []],
        'after the last' => [2, ['a', 'b']],
    ]);

    it('throws when a break is scripted without a stream before it', function (): void {
        expect(fn() => (new FakeTransport())->willBreakStreamAfter(1, TransportFailure::ConnectionFailed))->toThrow(LogicException::class)
            ->and(fn() => (new FakeTransport())->willFail(TransportFailure::TimedOut)->willBreakStreamAfter(0, TransportFailure::ConnectionFailed))->toThrow(LogicException::class)
            ->and(fn() => (new FakeTransport())->willStream(200, [], 'a')->willBreakStreamAfter(1, TransportFailure::TimedOut)->willBreakStreamAfter(0, TransportFailure::TimedOut))->toThrow(LogicException::class);
    });

    it('throws when a stream is to break after more chunks than it has, or fewer than 0', function (int $after): void {
        (new FakeTransport())->willStream(200, [], 'a', 'b')->willBreakStreamAfter($after, TransportFailure::ConnectionFailed);
    })->with([3, -1])->throws(InvalidArgumentException::class);

    it('fails with the scripted failure, for a whole response and for a stream', function (TransportFailure $failure): void {
        $transport = (new FakeTransport())->willFail($failure)->willFail($failure);

        expect(fakeFailureOf(fn() => $transport->send(fakeRequest()))->failure)->toBe($failure)
            ->and(fakeFailureOf(fn() => $transport->stream(fakeRequest()))->failure)->toBe($failure);
    })->with([
        'connection failed' => [TransportFailure::ConnectionFailed],
        'timed out' => [TransportFailure::TimedOut],
        'redirected' => [TransportFailure::Redirected],
        'blocked' => [TransportFailure::Blocked],
    ]);

    it('goes on with the next scripted answer after a failure', function (): void {
        $transport = (new FakeTransport())->willFail(TransportFailure::ConnectionFailed)->willReturn(new HttpResponse(200, [], 'ok'));

        fakeFailureOf(fn() => $transport->send(fakeRequest()));

        expect($transport->send(fakeRequest())->body)->toBe('ok');
    });

    it('gives a scripted response through stream as one chunk, and a scripted stream through send as one body', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(new HttpResponse(429, ['Retry-After' => '30'], '{"error":"slow down"}'))
            ->willStream(200, ['X-Request-Id' => 'request-1'], '{"id":', '"answer-1"}')
            ->willReturn(new HttpResponse(204));

        $streamed = $transport->stream(fakeRequest());
        $whole = $transport->send(fakeRequest());
        $empty = $transport->stream(fakeRequest());

        expect($streamed->status)->toBe(429)
            ->and($streamed->header('retry-after'))->toBe('30')
            ->and(iterator_to_array($streamed->chunks, false))->toBe(['{"error":"slow down"}'])
            ->and($whole->body)->toBe('{"id":"answer-1"}')
            ->and($whole->header('x-request-id'))->toBe('request-1')
            ->and(iterator_to_array($empty->chunks, false))->toBe([]);
    });

    it('fails a whole response with the failure of a stream that is scripted to break', function (): void {
        $transport = (new FakeTransport())->willStream(200, [], 'a')->willBreakStreamAfter(1, TransportFailure::ConnectionFailed);

        expect(fakeFailureOf(fn() => $transport->send(fakeRequest()))->failure)->toBe(TransportFailure::ConnectionFailed);
    });

    it('throws a logic exception for a call without a scripted answer, and still records the request', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200));
        $transport->send(fakeRequest());

        expect(fn() => $transport->send(fakeRequest()))->toThrow(LogicException::class, 'request 2')
            ->and(fn() => $transport->stream(fakeRequest()))->toThrow(LogicException::class, 'request 3')
            ->and($transport->requests())->toHaveCount(3);
    });

    it('moves the fake clock forward by a scripted delay when the answer is given, without waiting', function (): void {
        $clock = new FakeClock(100.0);
        $transport = (new FakeTransport($clock))
            ->willDelay(2.5)
            ->willReturn(new HttpResponse(200, [], 'slow'))
            ->willReturn(new HttpResponse(200, [], 'fast'))
            ->willDelay(600.0)
            ->willFail(TransportFailure::TimedOut)
            ->willDelay(1.0)
            ->willStream(200, [], 'a');
        $real = new SystemClock();
        $before = $real->monotonic();

        expect($clock->monotonic())->toBe(100.0, 'scripting a delay moves nothing');

        $transport->send(fakeRequest());
        expect($clock->monotonic())->toBe(102.5);

        $transport->send(fakeRequest());
        expect($clock->monotonic())->toBe(102.5);

        fakeFailureOf(fn() => $transport->send(fakeRequest()));
        expect($clock->monotonic())->toBe(702.5);

        $transport->stream(fakeRequest());
        expect($clock->monotonic())->toBe(703.5)
            ->and($clock->sleeps())->toBe([], 'a delay is time that passed, not a sleep of the library')
            ->and($real->monotonic() - $before)->toBeLessThan(1.0);
    });

    it('adds up two delays scripted before one answer', function (): void {
        $clock = new FakeClock(0.0);
        $transport = (new FakeTransport($clock))->willDelay(1.0)->willDelay(2.0)->willReturn(new HttpResponse(200));

        $transport->send(fakeRequest());

        expect($clock->monotonic())->toBe(3.0);
    });

    it('throws when a delay is scripted without a fake clock', function (): void {
        (new FakeTransport())->willDelay(1.0);
    })->throws(LogicException::class, 'Give the fake transport a fake clock to script a delay.');

    it('throws when a delay is below 0 or not finite', function (float $seconds): void {
        (new FakeTransport(new FakeClock()))->willDelay($seconds);
    })->with([
        'negative' => [-1.0],
        'infinite' => [INF],
        'not a number' => [NAN],
    ])->throws(InvalidArgumentException::class, 'The delay must be a finite number of seconds of 0 or more.');

    it('does not show a secret header of a recorded request when it is dumped', function (): void {
        $transport = (new FakeTransport(new FakeClock()))
            ->willReturn(new HttpResponse(200, [], '{}'))
            ->willStream(200, [], 'a');
        $transport->send(fakeRequest());
        $transport->stream(fakeRequest());

        $forms = valueForms($transport) + ['requests' => print_r($transport->requests(), true)];
        foreach (valueForms($transport->requests()) as $form => $output) {
            $forms['requests, ' . $form] = $output;
        }

        expectNoMarker($forms);
    });

    it('does not show a secret header in a failure it throws, whatever PHP keeps of arguments', function (bool $ignoreArgs): void {
        withExceptionIgnoreArgs($ignoreArgs, function (): void {
            $transport = (new FakeTransport())->willFail(TransportFailure::ConnectionFailed);
            $thrown = [fakeFailureOf(fn() => $transport->send(fakeRequest()))];

            try {
                $transport->send(fakeRequest());
            } catch (LogicException $unscripted) {
                $thrown[] = $unscripted;
            }

            expect($thrown)->toHaveCount(2);
            foreach ($thrown as $exception) {
                expectNoMarker(exceptionForms($exception));
            }
        });
    })->with(['arguments kept' => [false], 'arguments left out' => [true]]);
});
