<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\ForeignFault;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\RecordingObserver;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Tests\Support\TalkAdapter;

// What an adapter throws while it BUILDS a request never leaves a call as it is: its text and its trace can hold the
// request, which is the text of the caller. It is treated as what an adapter throws while it reads a response
// (G44): an exception becomes provider_error with a fixed detail, an error becomes a foreign fault with a fixed
// text. Nothing is sent, no attempt is counted or reported, nothing is chained.

/**
 * A request whose text is the marker: the made-up adapter puts that text in the message of what it throws.
 */
function requestWithMarker(): Request
{
    return new Request('model-a', [Message::user('A letter about ' . LEAK_MARKER)], 30.0, traceId: 'trace-1');
}

/**
 * @param callable(): mixed $call
 */
function leftBy(callable $call): Throwable
{
    try {
        $call();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    throw new LogicException('The call did not fail.');
}

describe('an adapter that throws while it builds a request', function (): void {
    it('ends complete() and models() in a provider_error with a fixed detail when it throws an exception', function (string $operation): void {
        withExceptionIgnoreArgs(false, function () use ($operation): void {
            $observer = new RecordingObserver();
            $transport = (new FakeTransport())->willReturn(Talk::answered());
            $gateway = Talk::gateway($transport, null, new RetryPolicy([0.0, 0.0], 0.0), new BearerKey(LEAK_MARKER), $observer, ['build_fails' => 'exception']);

            $error = leftBy(fn() => $operation === 'complete' ? $gateway->complete(requestWithMarker()) : $gateway->models(10.0, 'trace-1'));

            expect($error)->toBeInstanceOf(GatewayException::class);
            assert($error instanceof GatewayException);
            expect($error->reason)->toBe(ErrorReason::ProviderError)
                ->and($error->detail)->toBe('the request could not be built')
                ->and($error->traceId)->toBe('trace-1')
                ->and($error->attempts)->toBe(0)
                ->and($error->httpStatus)->toBeNull()
                ->and($error->providerCode)->toBeNull()
                ->and($error->getPrevious())->toBeNull()
                ->and($transport->requests())->toBe([])
                ->and($transport->remaining())->toBe(1)
                ->and($observer->started)->toBe([])
                ->and($observer->finished)->toBe([]);
            expectNoArgumentsInTrace($error);
        });
    })->with(['complete', 'models']);

    it('ends a stream in one failed event with the same error', function (): void {
        withExceptionIgnoreArgs(false, function (): void {
            $observer = new RecordingObserver();
            $transport = new FakeTransport();
            $gateway = Talk::gateway($transport, credentials: new BearerKey(LEAK_MARKER), observer: $observer, options: ['build_fails' => 'exception']);

            $events = Talk::events($gateway->stream(requestWithMarker()));

            expect($events)->toHaveCount(1)
                ->and($events[0])->toBeInstanceOf(StreamFailed::class);
            $error = Talk::failedIn($events)->error;
            expect($error->reason)->toBe(ErrorReason::ProviderError)
                ->and($error->detail)->toBe('the request could not be built')
                ->and($error->traceId)->toBe('trace-1')
                ->and($error->attempts)->toBe(0)
                ->and($transport->requests())->toBe([])
                ->and($observer->started)->toBe([]);
            expectNoArgumentsInTrace($error);
            expectNoMarker(valueForms($events[0]));
        });
    });

    it('ends in a foreign fault with a fixed text when its code fails with an error', function (string $operation): void {
        withExceptionIgnoreArgs(false, function () use ($operation): void {
            $observer = new RecordingObserver();
            $transport = new FakeTransport();
            $gateway = Talk::gateway($transport, null, new RetryPolicy([0.0], 0.0), new BearerKey(LEAK_MARKER), $observer, ['build_fails' => 'error']);

            $fault = leftBy(fn() => match ($operation) {
                'complete' => $gateway->complete(requestWithMarker()),
                'stream' => Talk::events($gateway->stream(requestWithMarker())),
                default => $gateway->models(10.0),
            });

            expect($fault::class)->toBe(ForeignFault::class)
                ->and($fault->getMessage())->toMatch('/^The adapter failed with an error of class DivisionByZeroError, thrown in TalkAdapter\.php on line [0-9]+\.$/D')
                ->and($fault->getPrevious())->toBeNull()
                ->and($transport->requests())->toBe([])
                ->and($observer->started)->toBe([])
                ->and($observer->finished)->toBe([]);
            expectNoArgumentsInTrace($fault);
        });
    })->with(['complete', 'stream', 'models']);

    it('proves the tests above: what the adapter throws holds the text of the caller', function (): void {
        $adapter = new TalkAdapter(Talk::preset(['build_fails' => 'exception']), new GatewayConfig(RetryPolicy::none()), new BearerKey('key-of-the-test'), new FakeTransport());

        $thrown = leftBy(fn() => (new ReflectionMethod($adapter, 'completionRequest'))->invoke($adapter, requestWithMarker(), false));

        expect($thrown::class)->toBe(RuntimeException::class)
            ->and(holdsMarker($thrown->getMessage()))->toBeTrue();
    });

    it('still passes a refusal the adapter built itself, with the trace id of the call', function (): void {
        $gateway = Talk::gateway(new FakeTransport(), options: ['max_tool_name' => 3]);
        $request = Talk::request()->withTools([new ToolDefinition('lookup', 'Looks up.', JsonSchema::fromJson('{"type":"object"}'))]);

        $error = Talk::errorOf(fn() => $gateway->complete($request));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->detail)->toBe('a tool name is longer than this format allows')
            ->and($error->traceId)->toBe('trace-1')
            ->and($error->attempts)->toBe(0);
    });
});
