<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\Credentials\TokenSupplierFailed;
use OrganiseYou\LlmGateway\Contract\ForeignFault;
use OrganiseYou\LlmGateway\Contract\Internal\Traces;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\Contract\WrongUse;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\RecordingPsr18Client;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\Psr18Transport;
use OrganiseYou\LlmGateway\Transport\TransportException;
use OrganiseYou\LlmGateway\Transport\TransportFailure;
use Psr\Http\Message\RequestInterface;

// Decision G63: whatever the library builds to throw while a request, a response, a header value or a secret is on
// the stack keeps no argument of any call in its trace. A parameter marked as sensitive is not enough: PHP hides it
// from the printed forms of a trace and leaves a SensitiveParameterValue there, whose getValue() gives it back.

const TRACE_URL = 'https://api.example.test/v1/chat';

/**
 * What a call throws.
 *
 * @param callable(): mixed $call
 */
function whatLeaves(callable $call): Throwable
{
    try {
        $call();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    throw new LogicException('The call did not fail.');
}

/**
 * A request with the marker in its sign-in header and in its body, on a bridge to a client that keeps nothing.
 *
 * @param array<string, mixed> $answers
 */
function bridgeWithMarker(array $answers): Closure
{
    $factory = new Psr17Factory();
    $transport = new Psr18Transport(new RecordingPsr18Client($answers, record: false), $factory, $factory);

    return fn(bool $asStream = false, string $method = 'POST'): mixed => $asStream
        ? iterator_to_array($transport->stream(traceRequest($method))->chunks, false)
        : $transport->send(traceRequest($method));
}

function traceRequest(string $method = 'POST'): HttpRequest
{
    return new HttpRequest($method, TRACE_URL, [], ['Authorization' => new Secret('Bearer ' . LEAK_MARKER)], 30.0, $method === 'POST' ? '{"echo":"' . LEAK_MARKER . '"}' : '');
}

/**
 * The same error built the plain way, in a function whose argument is marked as sensitive: what the library threw
 * before.
 */
function plainErrorBesideSensitiveArgument(#[SensitiveParameter] string $secret): Error
{
    return new Error('a fixed text');
}

describe('Traces', function (): void {
    it('is one final internal class without state', function (): void {
        $reflection = new ReflectionClass(Traces::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->getProperties())->toBe([])
            ->and((string) $reflection->getDocComment())->toContain('@internal');
    });

    it('takes the arguments out of every frame and leaves the rest of the trace as it was', function (Closure $build): void {
        withExceptionIgnoreArgs(false, function () use ($build): void {
            $thrown = $build(LEAK_MARKER);
            assert($thrown instanceof Throwable);
            $before = $thrown->getTrace();
            $text = $thrown->getMessage();
            $line = $thrown->getLine();

            expect($before[0]['args'] ?? null)->not->toBeNull('the test must start with a trace that holds arguments');

            $same = Traces::withoutArguments($thrown);

            $withoutArguments = array_map(function (array $frame): array {
                unset($frame['args']);

                return $frame;
            }, $before);

            expect($same)->toBe($thrown)
                ->and($thrown->getTrace())->toBe($withoutArguments)
                ->and($thrown->getMessage())->toBe($text)
                ->and($thrown->getLine())->toBe($line)
                ->and($thrown->getFile())->toBe(__FILE__);
            expectNoArgumentsInTrace($thrown);
        });
    })->with([
        'an exception' => [fn(string $secret): Throwable => new RuntimeException('made up')],
        'a class that extends an exception' => [fn(string $secret): Throwable => new InvalidArgumentException('made up')],
        'an error' => [fn(string $secret): Throwable => new Error('made up')],
        'a class that extends an error' => [fn(string $secret): Throwable => new TypeError('made up')],
    ]);

    it('leaves a trace as it is where PHP already left the arguments out, but for the path of an included file', function (): void {
        withExceptionIgnoreArgs(true, function (): void {
            $thrown = new RuntimeException('made up');
            // PHP keeps the one argument of an include or a require in a trace whatever the setting says.
            $before = array_map(function (array $frame): array {
                unset($frame['args']);

                return $frame;
            }, $thrown->getTrace());

            expect(Traces::withoutArguments($thrown)->getTrace())->toBe($before);
        });
    });
});

describe('the search that finds a sensitive argument', function (): void {
    it('reaches the value behind a sensitive parameter of a plain error, which no printed form shows', function (): void {
        withExceptionIgnoreArgs(false, function (): void {
            $error = plainErrorBesideSensitiveArgument(LEAK_MARKER);
            $forms = exceptionForms($error);
            $ownFrame = [$error->getTrace()[0]];

            // The frame of the function itself: the frames above it belong to the test runner.
            expect($ownFrame[0]['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class)
                ->and(holdsMarker(print_r($ownFrame, true), 'MARKER'))->toBeFalse()
                ->and(holdsMarker(var_export($ownFrame, true), 'MARKER'))->toBeFalse()
                ->and(holdsMarker($forms['getTrace, sensitive arguments unwrapped']))->toBeTrue()
                ->and(holdsMarker(reflectionRead($ownFrame)))->toBeTrue();
        });
    });

    it('never writes the secret into the message of its own failure, wherever the secret stands', function (string $output): void {
        // The runner keeps the message of a failed test: with the secret in it, every later search of a trace with
        // the frames of the runner would find it there and fail too.
        $message = null;
        try {
            expectNoMarker(['a form' => $output]);
        } catch (PHPUnit\Framework\ExpectationFailedException $failed) {
            $message = $failed->getMessage();
        }

        expect($message)->not->toBeNull()
            ->and(holdsMarker((string) $message, 'MARKER'))->toBeFalse()
            ->and((string) $message)->toContain('a form shows the secret');
    })->with([
        'at the start' => [LEAK_MARKER . ' and more'],
        'after a few bytes' => ['key: ' . LEAK_MARKER],
        'after more than the message quotes' => [str_repeat('x', 400) . LEAK_MARKER . str_repeat('y', 400)],
        'twice, close together' => [LEAK_MARKER . ' ' . LEAK_MARKER],
    ]);

    it('reaches it through the trace of an exception that is the property of a value', function (): void {
        withExceptionIgnoreArgs(false, function (): void {
            $holder = new class (plainErrorBesideSensitiveArgument(LEAK_MARKER)) {
                public function __construct(public readonly Error $error) {}
            };

            expect(holdsMarker(valueForms($holder)['reflection']))->toBeTrue();
        });
    });
});

describe('what the library throws while a request, a response or a secret is on the stack', function (): void {
    it('keeps no argument of any call in its trace', function (Closure $call, string $class, string $message): void {
        withExceptionIgnoreArgs(false, function () use ($call, $class, $message): void {
            $thrown = whatLeaves($call);

            expect($thrown::class)->toBe($class)
                ->and(str_starts_with($thrown->getMessage(), $message))->toBeTrue('the message is: ' . $thrown->getMessage())
                ->and($thrown->getPrevious())->toBeNull();
            expectNoArgumentsInTrace($thrown);
        });
    })->with([
        'the bridge, for an error of the client' => [
            fn() => bridgeWithMarker(['POST ' . TRACE_URL => fn(RequestInterface $request): never => throw new TypeError('a fault, with ' . $request->getHeaderLine('Authorization'))])(),
            ForeignFault::class,
            'The HTTP client or its message classes failed with an error of class TypeError.',
        ],
        'the bridge, for an error of the client in a stream' => [
            fn() => bridgeWithMarker(['POST ' . TRACE_URL => fn(RequestInterface $request): never => throw new TypeError('a fault')])(true),
            ForeignFault::class,
            'The HTTP client or its message classes failed with an error of class TypeError.',
        ],
        'the bridge, for a lost connection' => [
            fn() => bridgeWithMarker(['POST ' . TRACE_URL => new RuntimeException('could not connect with ' . LEAK_MARKER)])(),
            TransportException::class,
            '',
        ],
        'the bridge, for a redirect' => [
            fn() => bridgeWithMarker(['GET ' . TRACE_URL => new Response(302, ['Location' => 'https://elsewhere.example.test/?key=' . LEAK_MARKER])])(false, 'GET'),
            TransportException::class,
            '',
        ],
        'the bridge, for a request its message classes refuse' => [
            function (): void {
                $factory = new Psr17Factory();
                $refused = new HttpRequest('POST', TRACE_URL, [], ['Authorization' => new Secret(LEAK_MARKER . "\x00")], 30.0);
                (new Psr18Transport(new RecordingPsr18Client([], record: false), $factory, $factory))->send($refused);
            },
            TransportException::class,
            '',
        ],
        'a transport exception a host or the fake transport builds' => [
            fn() => (fn(#[SensitiveParameter] HttpRequest $request): never => throw new TransportException(TransportFailure::ConnectionFailed, 'made up'))(traceRequest()),
            TransportException::class,
            'made up',
        ],
        'the fake transport, for a scripted failure' => [
            fn() => (new FakeTransport())->willFail(TransportFailure::Blocked)->send(traceRequest()),
            TransportException::class,
            '',
        ],
        'the token credential, for an error of the supplier' => [
            function (): void {
                $tokens = Talk::tokens();
                $tokens->next = LEAK_MARKER;
                $tokens->fails = 'error';
                (new BearerToken($tokens))->token();
            },
            ForeignFault::class,
            'The token supplier failed with an error of class TypeError.',
        ],
        'the token credential, for an exception of the supplier' => [
            function (): void {
                $tokens = Talk::tokens();
                $tokens->next = LEAK_MARKER;
                $tokens->fails = 'exception';
                (new BearerToken($tokens))->token();
            },
            TokenSupplierFailed::class,
            'The token supplier failed with an exception of class RuntimeException.',
        ],
        'the token credential, for a token that cannot be sent' => [
            function (): void {
                $tokens = Talk::tokens();
                $tokens->next = LEAK_MARKER . "\n";
                (new BearerToken($tokens))->token();
            },
            TokenSupplierFailed::class,
            'The token supplier gave a token with a control character',
        ],
        'the adapter base, for a fault in the code of an adapter' => [
            fn() => Talk::gateway(
                (new FakeTransport())->willReturn(new HttpResponse(200, ['X-Echo' => LEAK_MARKER], Talk::json(['explode' => 'error', 'echo' => LEAK_MARKER]))),
                credentials: new BearerKey(LEAK_MARKER),
            )->complete(Talk::request()),
            ForeignFault::class,
            'The adapter failed with an error of class DivisionByZeroError, thrown in TalkAdapter.php on line ',
        ],
        'the adapter base, for a fault in the code of an adapter while it reads a failure' => [
            fn() => Talk::gateway(
                (new FakeTransport())->willReturn(new HttpResponse(400, ['X-Echo' => LEAK_MARKER], Talk::json(['fault' => ['code' => 'adapter_breaks'], 'echo' => LEAK_MARKER]))),
                credentials: new BearerKey(LEAK_MARKER),
            )->complete(Talk::request()),
            ForeignFault::class,
            'The adapter failed with an error of class ',
        ],
        'the adapter base, for a fault in the code of an adapter in a stream' => [
            fn() => Talk::events(Talk::gateway(
                (new FakeTransport())->willStream(200, ['X-Echo' => LEAK_MARKER], Talk::event('say', ['explode' => 'error', 'echo' => LEAK_MARKER])),
                credentials: new BearerKey(LEAK_MARKER),
            )->stream(Talk::request())),
            ForeignFault::class,
            'The adapter failed with an error of class DivisionByZeroError, thrown in TalkAdapter.php on line ',
        ],
        'the adapter base, for a value of an adapter that is no stream event' => [
            fn() => Talk::events(Talk::gateway(
                (new FakeTransport())->willStream(200, ['X-Echo' => LEAK_MARKER], Talk::event('junk', ['piece' => LEAK_MARKER])),
                credentials: new BearerKey(LEAK_MARKER),
            )->stream(Talk::request())),
            ForeignFault::class,
            'The adapter gave a value that is not a stream event.',
        ],
        'the adapter base, for a request that leaves the base URL' => [
            fn() => Talk::gateway(
                new FakeTransport(),
                credentials: new BearerKey(LEAK_MARKER),
                options: ['foreign_url' => 'https://other.example.test/talk'],
            )->complete(Talk::request()),
            WrongUse::class,
            'An adapter must build every address with url()',
        ],
        'the adapter base, for the token credential with an error of the supplier' => [
            function (): void {
                $tokens = Talk::tokens();
                $tokens->next = LEAK_MARKER;
                $tokens->fails = 'error';
                Talk::gateway(new FakeTransport(), credentials: new BearerToken($tokens))->complete(Talk::request());
            },
            ForeignFault::class,
            'The token supplier failed with an error of class TypeError.',
        ],
        'a refused key' => [
            fn() => new BearerKey(LEAK_MARKER . "\n"),
            InvalidValue::class,
            'The key must not hold a control character',
        ],
        'a refused request' => [
            fn() => new HttpRequest('POST', 'https://user:' . LEAK_MARKER . '@api.example.test/', [], ['Authorization' => new Secret(LEAK_MARKER)], 30.0, LEAK_MARKER),
            InvalidValue::class,
            '',
        ],
        'a refused response' => [
            fn() => new HttpResponse(700, ['Set-Cookie' => LEAK_MARKER], LEAK_MARKER),
            InvalidValue::class,
            '',
        ],
    ]);

    it('retries nothing more and maps nothing more for it: only the trace changed', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], Talk::json(['explode' => 'error'])));
        $gateway = Talk::gateway($transport, null, new RetryPolicy([0.0, 0.0], 0.0));

        expect(whatLeaves(fn() => $gateway->complete(Talk::request()))::class)->toBe(ForeignFault::class)
            ->and($transport->requests())->toHaveCount(1);
    });
});
