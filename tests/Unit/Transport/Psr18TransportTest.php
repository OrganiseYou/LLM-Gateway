<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\Tests\Support\FlakyEndBody;
use OrganiseYou\LlmGateway\Tests\Support\HeaderSpyResponse;
use OrganiseYou\LlmGateway\Tests\Support\RecordingPsr18Client;
use OrganiseYou\LlmGateway\Tests\Support\ScriptedBody;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\Psr18Transport;
use OrganiseYou\LlmGateway\Transport\Transport;
use OrganiseYou\LlmGateway\Transport\TransportException;
use OrganiseYou\LlmGateway\Transport\TransportFailure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

const BRIDGE_URL = 'https://api.example.test/v1/chat';
const BRIDGE_ELSEWHERE = 'https://elsewhere.example.test/v1/chat';

function bridge(RecordingPsr18Client $client): Psr18Transport
{
    $factory = new Psr17Factory();

    return new Psr18Transport($client, $factory, $factory);
}

function bridgeRequest(string $method = 'POST', string $body = '{"model":"model-a"}'): HttpRequest
{
    return new HttpRequest(
        $method,
        BRIDGE_URL,
        ['Content-Type' => 'application/json'],
        ['Authorization' => new Secret('Bearer ' . LEAK_MARKER)],
        30.0,
        $body,
    );
}

/**
 * @param callable(): mixed $call
 */
function transportFailureOf(callable $call): TransportException
{
    try {
        $call();
    } catch (TransportException $exception) {
        return $exception;
    }

    throw new LogicException('The call did not fail.');
}

/**
 * @param callable(): mixed $call
 */
function thrownBy(callable $call): Throwable
{
    try {
        $call();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    throw new LogicException('The call did not fail.');
}

function networkException(string $message): NetworkExceptionInterface
{
    return new class ($message) extends RuntimeException implements NetworkExceptionInterface {
        public function getRequest(): RequestInterface
        {
            return (new Psr17Factory())->createRequest('POST', BRIDGE_URL)->withHeader('Authorization', 'Bearer ' . LEAK_MARKER);
        }
    };
}

function clientException(string $message): ClientExceptionInterface
{
    return new class ($message) extends RuntimeException implements ClientExceptionInterface {};
}

describe('Psr18Transport', function (): void {
    it('is a final transport', function (): void {
        $reflection = new ReflectionClass(Psr18Transport::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->implementsInterface(Transport::class))->toBeTrue();
    });

    it('sends the method, the URL, every header with the secret ones revealed, and the body', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], '{}')]);

        bridge($client)->send(bridgeRequest());

        expect($client->requests)->toHaveCount(1);
        $sent = $client->requests[0];
        expect($sent->getMethod())->toBe('POST')
            ->and((string) $sent->getUri())->toBe(BRIDGE_URL)
            ->and($sent->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and($sent->getHeaderLine('Authorization'))->toBe('Bearer ' . LEAK_MARKER)
            ->and((string) $sent->getBody())->toBe('{"model":"model-a"}');
    });

    it('sends a GET without a body', function (): void {
        $client = new RecordingPsr18Client(['GET ' . BRIDGE_URL => new Response(200, [], '{}')]);

        bridge($client)->send(bridgeRequest('GET', ''));

        expect($client->requests[0]->getMethod())->toBe('GET')
            ->and((string) $client->requests[0]->getBody())->toBe('');
    });

    it('returns the status, the headers and the body', function (): void {
        $client = new RecordingPsr18Client([
            'POST ' . BRIDGE_URL => new Response(200, ['Content-Type' => 'application/json', 'X-Request-Id' => 'request-1'], '{"id":"answer-1"}'),
        ]);

        $response = bridge($client)->send(bridgeRequest());

        expect($response->status)->toBe(200)
            ->and($response->header('content-type'))->toBe('application/json')
            ->and($response->header('X-Request-Id'))->toBe('request-1')
            ->and($response->body)->toBe('{"id":"answer-1"}');
    });

    it('joins several values of one response header', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, ['Vary' => ['Accept', 'Origin']], '')]);

        expect(bridge($client)->send(bridgeRequest())->headers)->toBe(['vary' => 'Accept, Origin']);
    });

    it('returns a 4xx and a 5xx as a response with its error body', function (int $status): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response($status, ['Retry-After' => '30'], '{"error":"no"}')]);

        $response = bridge($client)->send(bridgeRequest());

        expect($response->status)->toBe($status)
            ->and($response->header('retry-after'))->toBe('30')
            ->and($response->body)->toBe('{"error":"no"}');
    })->with([400, 401, 404, 408, 429, 500, 503, 599]);

    it('reads a body the client left at its end from the start', function (): void {
        $response = new Response(200, [], '{"id":"answer-1"}');
        $response->getBody()->getContents();
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => $response]);

        expect(bridge($client)->send(bridgeRequest())->body)->toBe('{"id":"answer-1"}');
    });

    it('reads a body larger than one piece in full', function (): void {
        $body = str_repeat('0123456789', 5000);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        expect(bridge($client)->send(bridgeRequest())->body)->toBe($body);
    });

    it('refuses a redirect as the failure redirected and never requests the Location', function (int $status): void {
        $client = new RecordingPsr18Client([
            'POST ' . BRIDGE_URL => new Response($status, ['Location' => BRIDGE_ELSEWHERE], 'moved'),
            'POST ' . BRIDGE_ELSEWHERE => new Response(200, [], '{"id":"from-elsewhere"}'),
            'GET ' . BRIDGE_ELSEWHERE => new Response(200, [], '{"id":"from-elsewhere"}'),
        ]);

        $exception = transportFailureOf(fn() => bridge($client)->send(bridgeRequest()));

        expect($exception->failure)->toBe(TransportFailure::Redirected)
            ->and($client->asked())->toBe(['POST ' . BRIDGE_URL]);
    })->with([300, 301, 302, 303, 304, 307, 308, 399]);

    it('refuses a redirect of a stream the same way, before any chunk', function (int $status): void {
        $client = new RecordingPsr18Client([
            'POST ' . BRIDGE_URL => new Response($status, ['Location' => BRIDGE_ELSEWHERE], 'moved'),
            'POST ' . BRIDGE_ELSEWHERE => new Response(200, [], 'data: from elsewhere'),
            'GET ' . BRIDGE_ELSEWHERE => new Response(200, [], 'data: from elsewhere'),
        ]);

        $exception = transportFailureOf(fn() => bridge($client)->stream(bridgeRequest()));

        expect($exception->failure)->toBe(TransportFailure::Redirected)
            ->and($client->asked())->toBe(['POST ' . BRIDGE_URL]);
    })->with([301, 302, 307, 308]);

    it('does not read the Location of a redirect', function (): void {
        $response = new HeaderSpyResponse(new Response(302, ['Location' => BRIDGE_ELSEWHERE], 'moved'));
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => $response]);

        transportFailureOf(fn() => bridge($client)->send(bridgeRequest()));

        expect($response->headersRead)->toBe([]);
    });

    it('reads the headers of a response that is no redirect', function (): void {
        $response = new HeaderSpyResponse(new Response(200, ['Location' => BRIDGE_ELSEWHERE], '{}'));
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => $response]);

        bridge($client)->send(bridgeRequest());

        expect($response->headersRead)->toBe(['*']);
    });

    it('does not treat a 299 or a 400 as a redirect', function (int $status): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response($status, ['Location' => BRIDGE_ELSEWHERE], '')]);

        expect(bridge($client)->send(bridgeRequest())->status)->toBe($status)
            ->and($client->asked())->toBe(['POST ' . BRIDGE_URL]);
    })->with([201, 299, 400]);

    it('never changes the host of the request', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], '{}')]);

        bridge($client)->send(bridgeRequest());

        expect($client->requests[0]->getUri()->getHost())->toBe('api.example.test')
            ->and($client->requests[0]->getHeaderLine('Host'))->toBe('api.example.test');
    });

    it('turns a network exception of the client into connection_failed', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => networkException('could not connect, sent ' . LEAK_MARKER)]);

        $exception = transportFailureOf(fn() => bridge($client)->send(bridgeRequest()));

        expect($exception->failure)->toBe(TransportFailure::ConnectionFailed);
    });

    it('turns any other client exception into connection_failed', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => clientException('bad request ' . LEAK_MARKER)]);

        expect(transportFailureOf(fn() => bridge($client)->send(bridgeRequest()))->failure)->toBe(TransportFailure::ConnectionFailed)
            ->and(transportFailureOf(fn() => bridge($client)->stream(bridgeRequest()))->failure)->toBe(TransportFailure::ConnectionFailed);
    });

    it('turns an exception of the client that PSR-18 does not name into connection_failed', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new RuntimeException('failed with ' . LEAK_MARKER)]);

        expect(transportFailureOf(fn() => bridge($client)->send(bridgeRequest()))->failure)->toBe(TransportFailure::ConnectionFailed);
    });

    it('does not copy the message or the exception of the client', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => networkException('could not connect, sent ' . LEAK_MARKER)]);

        $exception = transportFailureOf(fn() => bridge($client)->send(bridgeRequest()));

        expect($exception->getMessage())->toBe('')
            ->and($exception->getPrevious())->toBeNull();
    });

    it('turns a request the message factory refuses into blocked, and sends nothing', function (): void {
        $client = new RecordingPsr18Client([]);
        $refusing = new class implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                throw new InvalidArgumentException('"' . LEAK_MARKER . '" is not valid header value');
            }
        };
        $transport = new Psr18Transport($client, $refusing, new Psr17Factory());

        $exception = transportFailureOf(fn() => $transport->send(bridgeRequest()));

        expect($exception->failure)->toBe(TransportFailure::Blocked)
            ->and($exception->getMessage())->toBe('')
            ->and($exception->getPrevious())->toBeNull()
            ->and($client->requests)->toBe([]);
    });

    it('turns a secret header the message refuses into blocked without showing it', function (): void {
        $client = new RecordingPsr18Client([]);
        $request = new HttpRequest('POST', BRIDGE_URL, [], ['Authorization' => new Secret(LEAK_MARKER . "\x00")], 30.0);

        $exception = transportFailureOf(fn() => bridge($client)->send($request));

        expect($exception->failure)->toBe(TransportFailure::Blocked)
            ->and($client->requests)->toBe([]);
        expectNoMarker(exceptionForms($exception));
    });

    it('does not show a secret header in any failure it throws, whatever PHP keeps of arguments', function (string $ignoreArgs): void {
        withExceptionIgnoreArgs($ignoreArgs, function (): void {
            // The marker is in the secret header of the request only, and the client keeps no request: whatever
            // the printed trace then shows of the marker, the bridge put there.
            $client = new RecordingPsr18Client([
                'POST ' . BRIDGE_URL => networkException('could not connect'),
                'GET ' . BRIDGE_URL => new Response(302, ['Location' => BRIDGE_ELSEWHERE]),
            ], record: false);
            $broken = new RecordingPsr18Client(['POST ' . BRIDGE_URL => fn(): Response => new Response(200, [], new ScriptedBody(['data: a', null]))], record: false);
            $refusing = new RecordingPsr18Client([], record: false);
            $refused = new HttpRequest('POST', BRIDGE_URL, [], ['Authorization' => new Secret(LEAK_MARKER . "\x00")], 30.0);

            $failures = [
                transportFailureOf(fn() => bridge($client)->send(bridgeRequest())),
                transportFailureOf(fn() => bridge($client)->stream(bridgeRequest())),
                transportFailureOf(fn() => bridge($client)->send(bridgeRequest('GET', ''))),
                transportFailureOf(fn() => bridge($client)->stream(bridgeRequest('GET', ''))),
                transportFailureOf(fn() => bridge($broken)->send(bridgeRequest())),
                transportFailureOf(fn() => iterator_to_array(bridge($broken)->stream(bridgeRequest())->chunks, false)),
                transportFailureOf(fn() => bridge($refusing)->send($refused)),
            ];

            expect(array_map(fn(TransportException $exception): string => $exception->failure->value, $failures))->toBe([
                'connection_failed', 'connection_failed', 'redirected', 'redirected', 'connection_failed', 'connection_failed', 'blocked',
            ]);
            foreach ($failures as $exception) {
                expectNoMarker(exceptionForms($exception));
            }
        });
    })->with(['arguments kept' => ['0'], 'arguments left out' => ['1']]);

    it('replaces an error of the client by a new error that holds nothing of it, in its whole trace', function (string $ignoreArgs): void {
        withExceptionIgnoreArgs($ignoreArgs, function (): void {
            // The client fails inside its own call, so its frame holds the request with the revealed header.
            $client = new RecordingPsr18Client([
                'POST ' . BRIDGE_URL => fn(RequestInterface $request): never => throw new TypeError('a fault, with ' . $request->getHeaderLine('Authorization')),
            ], record: false);

            foreach ([fn() => bridge($client)->send(bridgeRequest()), fn() => bridge($client)->stream(bridgeRequest())] as $call) {
                $left = thrownBy($call);

                expect($left::class)->toBe(Error::class)
                    ->and($left->getMessage())->toBe('The HTTP client or its message classes failed with an error of class TypeError.')
                    ->and($left->getPrevious())->toBeNull();
                expectNoMarker(exceptionForms($left));

                $classes = array_map(fn(array $frame): string => $frame['class'] ?? '', $left->getTrace());
                expect($classes)->not->toContain(RecordingPsr18Client::class);
            }
        });
    })->with(['arguments kept' => ['0'], 'arguments left out' => ['1']]);

    it('proves the test above: the error of the client itself holds the secret header in its trace', function (): void {
        withExceptionIgnoreArgs('0', function (): void {
            $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => fn(RequestInterface $request): never => throw new TypeError('a fault')], record: false);
            $request = (new Psr17Factory())->createRequest('POST', BRIDGE_URL)->withHeader('Authorization', 'Bearer ' . LEAK_MARKER);

            $error = thrownBy(fn() => $client->sendRequest($request));

            expect(str_contains(print_r($error->getTrace(), true), 'MARKER'))->toBeTrue();
        });
    });

    it('does not retry a fault as a lost connection: an error of the client is no transport exception', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => fn(RequestInterface $request): never => throw new ArgumentCountError('a fault')]);

        $left = thrownBy(fn() => bridge($client)->send(bridgeRequest()));

        expect($left)->not->toBeInstanceOf(TransportException::class)
            ->and($left)->toBeInstanceOf(Error::class)
            ->and($left->getMessage())->toContain('ArgumentCountError');
    });

    it('names an anonymous error class without the path of its file', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => fn(RequestInterface $request): never => throw new class ('a fault') extends Error {}]);

        $left = thrownBy(fn() => bridge($client)->send(bridgeRequest()));

        expect($left->getMessage())->toBe('The HTTP client or its message classes failed with an error of class Error@anonymous.');
    });

    it('turns an error that implements the client exception of PSR-18 into connection_failed', function (): void {
        $fault = new class ('could not connect') extends Error implements ClientExceptionInterface {};
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => $fault]);

        expect(transportFailureOf(fn() => bridge($client)->send(bridgeRequest()))->failure)->toBe(TransportFailure::ConnectionFailed);
    });

    it('replaces an error of the message classes by a new error that holds nothing of it', function (string $ignoreArgs): void {
        withExceptionIgnoreArgs($ignoreArgs, function (): void {
            $client = new RecordingPsr18Client([], record: false);
            $faulty = new class implements RequestFactoryInterface {
                public function createRequest(string $method, $uri): RequestInterface
                {
                    return $this->fail('Bearer ' . LEAK_MARKER);
                }

                private function fail(string $header): never
                {
                    throw new TypeError('"' . $header . '" is no header');
                }
            };
            $transport = new Psr18Transport($client, $faulty, new Psr17Factory());

            $left = thrownBy(fn() => $transport->send(bridgeRequest()));

            expect($left::class)->toBe(Error::class)
                ->and($left->getMessage())->toBe('The HTTP client or its message classes failed with an error of class TypeError.')
                ->and($left->getPrevious())->toBeNull();
            expectNoMarker(exceptionForms($left));
        });
    })->with(['arguments kept' => ['0'], 'arguments left out' => ['1']]);

    it('turns any exception of the body into connection_failed, whatever its class, and closes the body', function (string $method): void {
        $body = new ScriptedBody(['data: one'], faults: [$method => new LogicException('broken: ' . LEAK_MARKER)], position: $method === 'read' ? null : 5);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        $exception = transportFailureOf(fn() => bridge($client)->send(bridgeRequest()));

        expect($exception->failure)->toBe(TransportFailure::ConnectionFailed)
            ->and($exception->getMessage())->toBe('')
            ->and($exception->getPrevious())->toBeNull()
            ->and($body->closed)->toBeTrue();
    })->with(['read', 'eof', 'tell', 'rewind']);

    it('replaces an error of the body by a new error that holds nothing of it, and closes the body', function (string $method): void {
        $body = new ScriptedBody(['data: one'], faults: [$method => new TypeError('broken: ' . LEAK_MARKER)], position: $method === 'read' ? null : 5);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        $left = thrownBy(fn() => iterator_to_array(bridge($client)->stream(bridgeRequest())->chunks, false));

        expect($left::class)->toBe(Error::class)
            ->and($left->getMessage())->toBe('The HTTP client or its message classes failed with an error of class TypeError.')
            ->and($left->getPrevious())->toBeNull()
            ->and($body->closed)->toBeTrue();
    })->with(['read', 'eof', 'tell', 'rewind']);

    it('fails with connection_failed when a body that is not at its start cannot be rewound', function (): void {
        $body = new ScriptedBody(['never read'], faults: ['rewind' => new RuntimeException('cannot rewind')], position: 12);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        $exception = transportFailureOf(fn() => bridge($client)->send(bridgeRequest()));

        expect($exception->failure)->toBe(TransportFailure::ConnectionFailed)
            ->and($body->reads)->toBe(0)
            ->and($body->closed)->toBeTrue();
    });

    it('rewinds a body that can be rewound and is not at its start, and leaves one at its start alone', function (): void {
        $moved = new ScriptedBody(['a'], position: 12);
        $atStart = new ScriptedBody(['a'], position: 0);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $moved), 'GET ' . BRIDGE_URL => new Response(200, [], $atStart)]);

        bridge($client)->send(bridgeRequest());
        bridge($client)->send(bridgeRequest('GET', ''));

        expect($moved->calls)->toContain('rewind')
            ->and($atStart->calls)->not->toContain('rewind');
    });

    it('reports a body whose end cannot be asked as broken, not as complete', function (): void {
        // The checks of the end before the two reads pass; the check after the empty read fails.
        // Once only: a bridge that went on reading would get the rest.
        $content = new ScriptedBody(['data: one', '', 'data: never handed out']);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], new FlakyEndBody($content, failAtEofCall: 3))]);

        $seen = [];
        $exception = transportFailureOf(function () use ($client, &$seen): void {
            foreach (bridge($client)->stream(bridgeRequest())->chunks as $chunk) {
                $seen[] = $chunk;
            }
        });

        expect($seen)->toBe(['data: one'])
            ->and($exception->failure)->toBe(TransportFailure::ConnectionFailed)
            ->and($content->closed)->toBeTrue();
    });

    it('does not read a body that says it is at its end', function (): void {
        $body = new ScriptedBody([]);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        expect(bridge($client)->send(bridgeRequest())->body)->toBe('')
            ->and($body->reads)->toBe(0)
            ->and($body->calls)->toBe(['eof', 'eof', 'close']);
    });

    it('closes the body of a redirect', function (): void {
        $whole = new ScriptedBody(['moved']);
        $streamed = new ScriptedBody(['moved']);
        $client = new RecordingPsr18Client([
            'POST ' . BRIDGE_URL => new Response(302, ['Location' => BRIDGE_ELSEWHERE], $whole),
            'GET ' . BRIDGE_URL => new Response(307, ['Location' => BRIDGE_ELSEWHERE], $streamed),
        ]);

        transportFailureOf(fn() => bridge($client)->send(bridgeRequest()));
        transportFailureOf(fn() => bridge($client)->stream(bridgeRequest('GET', '')));

        expect($whole->closed)->toBeTrue()
            ->and($whole->reads)->toBe(0)
            ->and($streamed->closed)->toBeTrue()
            ->and($streamed->reads)->toBe(0);
    });

    it('turns a response that is not HTTP as the library knows it into connection_failed and closes its body', function (?int $status, ?array $headers): void {
        foreach (['send', 'stream'] as $method) {
            $body = new ScriptedBody(['{}']);
            $response = new HeaderSpyResponse(new Response(200, ['X-Request-Id' => 'request-1'], $body), $status, $headers);
            $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => $response]);

            $left = thrownBy(fn() => bridge($client)->{$method}(bridgeRequest()));

            expect($left)->toBeInstanceOf(TransportException::class);
            expect($left instanceof TransportException ? $left->failure : null)->toBe(TransportFailure::ConnectionFailed, $method)
                ->and($left->getMessage())->toBe('')
                ->and($left->getPrevious())->toBeNull()
                ->and($body->closed)->toBeTrue($method . ' must close the body')
                ->and($body->reads)->toBe(0);
        }
    })->with([
        'status 0' => [0, null],
        'status 99' => [99, null],
        'status 600' => [600, null],
        'status 999' => [999, null],
        'a header value that is no text' => [null, ['X-Count' => [1]]],
        'a header value that is null' => [null, ['X-Count' => null]],
        'a nested header value' => [null, ['X-Count' => [['a']]]],
        'an empty header name' => [null, ['' => ['value']]],
    ]);

    it('turns a response whose status, headers or body cannot be asked into connection_failed', function (string $method): void {
        $body = new ScriptedBody(['{}']);
        $response = new HeaderSpyResponse(new Response(200, [], $body), faults: [$method => new RuntimeException('broken: ' . LEAK_MARKER)]);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => $response]);

        $exception = transportFailureOf(fn() => bridge($client)->stream(bridgeRequest()));

        expect($exception->failure)->toBe(TransportFailure::ConnectionFailed)
            ->and($exception->getMessage())->toBe('')
            ->and($body->closed)->toBe($method !== 'getBody');
    })->with(['getStatusCode', 'getHeaders', 'getBody']);

    it('replaces an error of the response by a new error that holds nothing of it', function (string $method): void {
        $response = new HeaderSpyResponse(new Response(200, [], '{}'), faults: [$method => new TypeError('broken: ' . LEAK_MARKER)]);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => $response]);

        $left = thrownBy(fn() => bridge($client)->send(bridgeRequest()));

        expect($left::class)->toBe(Error::class)
            ->and($left->getMessage())->toBe('The HTTP client or its message classes failed with an error of class TypeError.')
            ->and($left->getPrevious())->toBeNull();
    })->with(['getStatusCode', 'getHeaders', 'getBody']);

    it('streams the status and the headers at once and the body in chunks', function (): void {
        $body = new ScriptedBody(['data: one', "\n\ndata: ", "two\n\n"]);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, ['Content-Type' => 'text/event-stream'], $body)]);

        $response = bridge($client)->stream(bridgeRequest());

        expect($response->status)->toBe(200)
            ->and($response->header('content-type'))->toBe('text/event-stream')
            ->and($body->reads)->toBe(0, 'nothing is read before the caller asks for a chunk')
            ->and(iterator_to_array($response->chunks, false))->toBe(['data: one', "\n\ndata: ", "two\n\n"])
            ->and($client->asked())->toBe(['POST ' . BRIDGE_URL]);
    });

    it('hands out each chunk before it reads the next one', function (): void {
        $body = new ScriptedBody(['a', 'b', 'c']);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        $readsAtChunk = [];
        foreach (bridge($client)->stream(bridgeRequest())->chunks as $chunk) {
            $readsAtChunk[$chunk] = $body->reads;
        }

        expect($readsAtChunk)->toBe(['a' => 1, 'b' => 2, 'c' => 3]);
    });

    it('cuts a body that is already there into chunks of at most 8192 bytes', function (): void {
        $body = str_repeat('x', 20000);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        $chunks = iterator_to_array(bridge($client)->stream(bridgeRequest())->chunks, false);

        expect(array_map(strlen(...), $chunks))->toBe([8192, 8192, 3616])
            ->and(implode('', $chunks))->toBe($body);
    });

    it('streams an empty body as no chunk', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], '')]);

        expect(iterator_to_array(bridge($client)->stream(bridgeRequest())->chunks, false))->toBe([]);
    });

    it('streams the error body of a 4xx or 5xx as chunks', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(429, ['Retry-After' => '30'], '{"error":"slow down"}')]);

        $response = bridge($client)->stream(bridgeRequest());

        expect($response->status)->toBe(429)
            ->and($response->header('retry-after'))->toBe('30')
            ->and(implode('', iterator_to_array($response->chunks, false)))->toBe('{"error":"slow down"}');
    });

    it('fails with connection_failed while the chunks are read when the body breaks', function (): void {
        $body = new ScriptedBody(['data: one', null, 'never read']);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        $response = bridge($client)->stream(bridgeRequest());
        $seen = [];
        $exception = transportFailureOf(function () use ($response, &$seen): void {
            foreach ($response->chunks as $chunk) {
                $seen[] = $chunk;
            }
        });

        expect($seen)->toBe(['data: one'])
            ->and($exception->failure)->toBe(TransportFailure::ConnectionFailed)
            ->and($exception->getMessage())->toBe('')
            ->and($exception->getPrevious())->toBeNull()
            ->and($body->closed)->toBeTrue();
    });

    it('fails with connection_failed when the body of a whole response breaks', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], new ScriptedBody(['{"id":', null]))]);

        expect(transportFailureOf(fn() => bridge($client)->send(bridgeRequest()))->failure)->toBe(TransportFailure::ConnectionFailed);
    });

    it('gives up as connection_failed on a body that gives nothing and never ends', function (): void {
        $body = new ScriptedBody(['data: one'], endless: true);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        $response = bridge($client)->stream(bridgeRequest());
        $seen = [];
        $exception = transportFailureOf(function () use ($response, &$seen): void {
            foreach ($response->chunks as $chunk) {
                $seen[] = $chunk;
            }
        });

        expect($seen)->toBe(['data: one'])
            ->and($exception->failure)->toBe(TransportFailure::ConnectionFailed)
            ->and($body->reads)->toBe(4);
    });

    it('goes on after an empty read when the body then gives more', function (): void {
        $body = new ScriptedBody(['a', '', '', 'b', '', 'c']);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        expect(iterator_to_array(bridge($client)->stream(bridgeRequest())->chunks, false))->toBe(['a', 'b', 'c']);
    });

    it('closes the body when the stream was read to its end', function (): void {
        $body = new ScriptedBody(['a', 'b']);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        iterator_to_array(bridge($client)->stream(bridgeRequest())->chunks, false);

        expect($body->closed)->toBeTrue();
    });

    it('closes the body when the caller gives the stream up', function (): void {
        $body = new ScriptedBody(['a', 'b', 'c']);
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => new Response(200, [], $body)]);

        $response = bridge($client)->stream(bridgeRequest());
        foreach ($response->chunks as $chunk) {
            break;
        }
        unset($response, $chunk);
        gc_collect_cycles();

        expect($body->closed)->toBeTrue()
            ->and($body->reads)->toBe(1);
    });

    it('keeps nothing between two calls', function (): void {
        $client = new RecordingPsr18Client(['POST ' . BRIDGE_URL => fn(): Response => new Response(200, [], '{}')]);
        $transport = bridge($client);

        $transport->send(bridgeRequest());
        $transport->send(bridgeRequest());

        $reflection = new ReflectionClass(Psr18Transport::class);
        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->getStaticProperties())->toBe([])
            ->and($client->requests)->toHaveCount(2);
    });
});
