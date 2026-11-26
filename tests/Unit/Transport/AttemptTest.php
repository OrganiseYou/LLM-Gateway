<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Operation;
use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\Transport\AttemptFinished;
use OrganiseYou\LlmGateway\Transport\AttemptObserver;
use OrganiseYou\LlmGateway\Transport\AttemptOutcome;
use OrganiseYou\LlmGateway\Transport\AttemptStarted;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\NullAttemptObserver;
use OrganiseYou\LlmGateway\Transport\TransportException;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

function attemptRequest(): HttpRequest
{
    return new HttpRequest(
        'POST',
        'https://api.example.test/v1/chat',
        ['Content-Type' => 'application/json'],
        ['Authorization' => new Secret('Bearer ' . LEAK_MARKER)],
        30.0,
        '{"model":"model-a"}',
    );
}

function attemptFinished(
    int $attempt = 1,
    ?HttpResponse $response = null,
    AttemptOutcome $outcome = AttemptOutcome::Succeeded,
    ?TransportFailure $transportFailure = null,
    bool $willRetry = false,
    int $durationMs = 120,
): AttemptFinished {
    return new AttemptFinished('preset-a', Operation::Complete, 'trace-1', $attempt, attemptRequest(), $response, $outcome, $transportFailure, $willRetry, $durationMs);
}

describe('AttemptOutcome', function (): void {
    it('has the three outcomes and no other', function (): void {
        expect(array_map(fn(AttemptOutcome $outcome): string => $outcome->value, AttemptOutcome::cases()))
            ->toBe(['succeeded', 'http_error', 'transport_error']);
    });

    it('names its cases after their values', function (): void {
        expect(AttemptOutcome::Succeeded->value)->toBe('succeeded')
            ->and(AttemptOutcome::HttpError->value)->toBe('http_error')
            ->and(AttemptOutcome::TransportError->value)->toBe('transport_error');
    });
});

describe('TransportFailure', function (): void {
    it('has the four failures and no other', function (): void {
        expect(array_map(fn(TransportFailure $failure): string => $failure->value, TransportFailure::cases()))
            ->toBe(['connection_failed', 'timed_out', 'redirected', 'blocked']);
    });

    it('names its cases after their values', function (): void {
        expect(TransportFailure::ConnectionFailed->value)->toBe('connection_failed')
            ->and(TransportFailure::TimedOut->value)->toBe('timed_out')
            ->and(TransportFailure::Redirected->value)->toBe('redirected')
            ->and(TransportFailure::Blocked->value)->toBe('blocked');
    });
});

describe('TransportException', function (): void {
    it('is a final runtime exception with a public readonly failure', function (): void {
        $reflection = new ReflectionClass(TransportException::class);
        $failure = $reflection->getProperty('failure');

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isSubclassOf(RuntimeException::class))->toBeTrue()
            ->and($failure->isPublic())->toBeTrue()
            ->and($failure->isReadOnly())->toBeTrue();
    });

    it('keeps the failure, and defaults to no message and no previous exception', function (): void {
        $exception = new TransportException(TransportFailure::TimedOut);

        expect($exception->failure)->toBe(TransportFailure::TimedOut)
            ->and($exception->getMessage())->toBe('')
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->getCode())->toBe(0);
    });

    it('keeps the message and the previous exception a host gives it', function (): void {
        $previous = new RuntimeException('the rule of the host');
        $exception = new TransportException(TransportFailure::Blocked, 'address not allowed', $previous);

        expect($exception->failure)->toBe(TransportFailure::Blocked)
            ->and($exception->getMessage())->toBe('address not allowed')
            ->and($exception->getPrevious())->toBe($previous);
    });

    it('does not show a secret header of a request that is on the stack when it is made', function (string $ignoreArgs): void {
        withExceptionIgnoreArgs($ignoreArgs, function (): void {
            $send = fn(HttpRequest $request): TransportException => new TransportException(TransportFailure::ConnectionFailed);

            $exception = $send(attemptRequest());

            expect($exception->getTrace())->not->toBe([]);
            expectNoMarker(exceptionForms($exception));
        });
    })->with(['arguments kept' => ['0'], 'arguments left out' => ['1']]);
});

describe('AttemptStarted', function (): void {
    it('keeps every field', function (): void {
        $request = attemptRequest();
        $attempt = new AttemptStarted('preset-a', Operation::Stream, 'trace-1', 2, $request);

        expect($attempt->presetId)->toBe('preset-a')
            ->and($attempt->operation)->toBe(Operation::Stream)
            ->and($attempt->traceId)->toBe('trace-1')
            ->and($attempt->attempt)->toBe(2)
            ->and($attempt->request)->toBe($request);
    });

    it('accepts attempt 1', function (): void {
        expect((new AttemptStarted('preset-a', Operation::Models, 'trace-1', 1, attemptRequest()))->attempt)->toBe(1);
    });

    it('throws when the attempt is below 1', function (int $attempt): void {
        new AttemptStarted('preset-a', Operation::Complete, 'trace-1', $attempt, attemptRequest());
    })->with([0, -1])->throws(InvalidArgumentException::class, 'The attempt must be 1 or more.');

    it('does not show a secret header of its request in any output form', function (): void {
        $attempt = new AttemptStarted('preset-a', Operation::Complete, 'trace-1', 1, attemptRequest());

        expectNoMarker(valueForms($attempt) + ['request as array' => print_r($attempt->request->toArray(), true)]);
    });
});

describe('AttemptFinished', function (): void {
    it('keeps every field', function (): void {
        $request = attemptRequest();
        $response = new HttpResponse(429, ['Retry-After' => '30'], '{"error":"slow down"}');
        $attempt = new AttemptFinished('preset-a', Operation::Check, 'trace-1', 3, $request, $response, AttemptOutcome::HttpError, null, true, 250);

        expect($attempt->presetId)->toBe('preset-a')
            ->and($attempt->operation)->toBe(Operation::Check)
            ->and($attempt->traceId)->toBe('trace-1')
            ->and($attempt->attempt)->toBe(3)
            ->and($attempt->request)->toBe($request)
            ->and($attempt->response)->toBe($response)
            ->and($attempt->outcome)->toBe(AttemptOutcome::HttpError)
            ->and($attempt->transportFailure)->toBeNull()
            ->and($attempt->willRetry)->toBeTrue()
            ->and($attempt->durationMs)->toBe(250);
    });

    it('accepts no response with a transport error', function (): void {
        $attempt = attemptFinished(outcome: AttemptOutcome::TransportError, transportFailure: TransportFailure::ConnectionFailed);

        expect($attempt->response)->toBeNull()
            ->and($attempt->transportFailure)->toBe(TransportFailure::ConnectionFailed);
    });

    it('accepts the bytes read so far of a stream that broke, with a transport error', function (): void {
        $attempt = attemptFinished(
            response: new HttpResponse(200, [], 'data: one'),
            outcome: AttemptOutcome::TransportError,
            transportFailure: TransportFailure::ConnectionFailed,
        );

        expect($attempt->response?->body)->toBe('data: one');
    });

    it('throws when the attempt is below 1', function (int $attempt): void {
        attemptFinished(attempt: $attempt);
    })->with([0, -1])->throws(InvalidArgumentException::class, 'The attempt must be 1 or more.');

    it('throws when the outcome is a transport error without a transport failure', function (): void {
        attemptFinished(outcome: AttemptOutcome::TransportError, transportFailure: null);
    })->throws(InvalidArgumentException::class, 'A transport failure goes with the outcome transport_error, and only with it.');

    it('throws when a transport failure comes with another outcome', function (AttemptOutcome $outcome): void {
        attemptFinished(outcome: $outcome, transportFailure: TransportFailure::TimedOut);
    })->with([
        'succeeded' => [AttemptOutcome::Succeeded],
        'http error' => [AttemptOutcome::HttpError],
    ])->throws(InvalidArgumentException::class, 'A transport failure goes with the outcome transport_error, and only with it.');

    it('accepts a duration of 0', function (): void {
        expect(attemptFinished(durationMs: 0)->durationMs)->toBe(0);
    });

    it('throws when the duration is below 0', function (): void {
        attemptFinished(durationMs: -1);
    })->throws(InvalidArgumentException::class, 'The duration must be 0 or more.');

    it('does not show a secret header of its request in any output form', function (): void {
        $attempt = attemptFinished(response: new HttpResponse(200, ['Content-Type' => 'application/json'], '{"id":"answer-1"}'));

        expectNoMarker(valueForms($attempt) + ['request as array' => print_r($attempt->request->toArray(), true)]);
    });
});

describe('NullAttemptObserver', function (): void {
    it('is a final observer', function (): void {
        $reflection = new ReflectionClass(NullAttemptObserver::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->implementsInterface(AttemptObserver::class))->toBeTrue();
    });

    it('does nothing with a report and keeps nothing', function (): void {
        $observer = new NullAttemptObserver();

        $observer->attemptStarted(new AttemptStarted('preset-a', Operation::Complete, 'trace-1', 1, attemptRequest()));
        $observer->attemptFinished(attemptFinished());

        expect((new ReflectionClass($observer))->getProperties())->toBe([]);
    });
});

describe('AttemptObserver', function (): void {
    it('has the two calls, each with its report and without a result', function (): void {
        $reflection = new ReflectionClass(AttemptObserver::class);
        $methods = [];
        foreach ($reflection->getMethods() as $method) {
            $methods[$method->getName()] = [(string) $method->getParameters()[0]->getType(), (string) $method->getReturnType()];
        }

        expect($reflection->isInterface())->toBeTrue()
            ->and($methods)->toBe([
                'attemptStarted' => [AttemptStarted::class, 'void'],
                'attemptFinished' => [AttemptFinished::class, 'void'],
            ]);
    });
});
