<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Adapter\Shared\SseEvent;
use OrganiseYou\LlmGateway\Adapter\Shared\SseReader;
use OrganiseYou\LlmGateway\Contract\CheckResult;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Tests\Support\TalkAdapter;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

/**
 * Runs one call in which the marker is the key, stands in the body of every response and in a header of every
 * response, and gives every output form of what left the call, with one word that says what left it.
 *
 * The scenario is named, not handed in: a closure or a response among the arguments of this function would put the
 * marker in the trace of whatever is thrown below it, and that would be a leak of the test, not of the library.
 *
 * @return array{string, array<string, string>}
 */
function leakOf(string $scenario): array
{
    $headers = ['Set-Cookie' => 'session=' . LEAK_MARKER, 'X-Echo' => LEAK_MARKER, 'Retry-After' => '120', 'Location' => 'https://other.example.test/?key=' . LEAK_MARKER];
    $fault = Talk::json(['fault' => ['code' => LEAK_MARKER], 'echo' => 'Bearer ' . LEAK_MARKER]);
    $good = Talk::json(['say' => 'Hello.', 'end' => 'done', 'by' => 'model-a', 'echo' => LEAK_MARKER]);
    $stream = [
        ': ' . LEAK_MARKER . "\n",
        Talk::event('say', ['piece' => 'Hel', 'echo' => LEAK_MARKER]),
        Talk::event('unknown', ['echo' => LEAK_MARKER]),
        Talk::event('end', ['end' => 'done', 'echo' => LEAK_MARKER]),
    ];

    $clock = new FakeClock();
    $transport = new FakeTransport($clock);
    $tokens = Talk::tokens();
    $tokens->next = LEAK_MARKER;
    $credentials = match ($scenario) {
        'a header key that comes back as the code', 'a code that starts with the key' => new HeaderKey(LEAK_MARKER),
        'a token that comes back as the code', 'a token supplier that fails', 'a code that ends with the key, in a stream' => new BearerToken($tokens),
        default => new BearerKey(LEAK_MARKER),
    };
    $options = match ($scenario) {
        'a stream of a format without streaming' => ['streams' => false],
        'an address outside the base URL' => ['foreign_url' => 'https://other.example.test/talk'],
        default => [],
    };
    $gateway = Talk::gateway($transport, $clock, new RetryPolicy([1.0], 60.0), $credentials, null, $options);
    $request = Talk::request();

    $left = null;
    try {
        switch ($scenario) {
            case 'an answer':
                $transport->willReturn(new HttpResponse(200, $headers, $good));
                $left = $gateway->complete($request);
                break;
            case 'an answer after a retry':
                $transport->willReturn(new HttpResponse(503, ['X-Echo' => LEAK_MARKER], $fault))->willReturn(new HttpResponse(200, $headers, $good));
                $left = $gateway->complete($request);
                break;
            case 'a stream':
                $transport->willStream(200, $headers, ...$stream);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream of a format without streaming':
                $transport->willReturn(new HttpResponse(200, $headers, $good));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that breaks':
                $transport->willStream(200, $headers, ...$stream)->willBreakStreamAfter(2, TransportFailure::ConnectionFailed);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that is refused':
                $transport->willStream(401, $headers, $fault);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream with an error inside':
                $transport->willStream(200, $headers, $stream[1], Talk::event('fault', ['fault' => ['code' => LEAK_MARKER]]));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream in which the adapter throws':
                $transport->willStream(200, $headers, Talk::event('say', ['explode' => 'exception', 'echo' => LEAK_MARKER]));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream in which the code of the adapter fails':
                $transport->willStream(200, $headers, Talk::event('say', ['explode' => 'error', 'echo' => LEAK_MARKER]));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'the model list':
                $transport->willReturn(new HttpResponse(200, $headers, Talk::json(['items' => [['key' => 'model-a', 'owner' => LEAK_MARKER]]])));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is refused':
                $transport->willReturn(new HttpResponse(403, $headers, $fault));
                $left = $gateway->models(10.0);
                break;
            case 'a wrong timeout':
                $left = $gateway->models(NAN);
                break;
            case 'a sign-in that is refused':
            case 'a header key that comes back as the code':
            case 'a token that comes back as the code':
                $transport->willReturn(new HttpResponse(401, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a code with the key inside it':
                $transport->willReturn(new HttpResponse(401, $headers, Talk::json(['fault' => ['code' => 'invalid_' . strtoupper(LEAK_MARKER) . '.x']])));
                $left = $gateway->complete($request);
                break;
            case 'a code that starts with the key':
                $transport->willReturn(new HttpResponse(200, $headers, Talk::json(['fault' => ['code' => LEAK_MARKER . '-x']])));
                $left = $gateway->complete($request);
                break;
            case 'a code that ends with the key, in a stream':
                $transport->willStream(200, $headers, $stream[1], Talk::event('fault', ['fault' => ['code' => 'bearer.' . LEAK_MARKER]]));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a detail of the adapter with the key inside it':
                $transport->willReturn(new HttpResponse(200, $headers, Talk::json(['fault' => ['words' => 'the key ' . LEAK_MARKER . ' was refused']])));
                $left = $gateway->complete($request);
                break;
            case 'a detail of the adapter with the key inside it, in a stream':
                $transport->willStream(200, $headers, Talk::event('fault', ['fault' => ['words' => 'no: ' . strtolower(LEAK_MARKER)]]));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a value of the adapter that is no stream event':
                $transport->willStream(200, $headers, Talk::event('junk', ['piece' => LEAK_MARKER]));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a rate limit with a wait that is too long':
                $transport->willReturn(new HttpResponse(429, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a server error after the retries':
                $transport->willReturn(new HttpResponse(500, ['X-Echo' => LEAK_MARKER], $fault))->willReturn(new HttpResponse(502, ['X-Echo' => LEAK_MARKER], LEAK_MARKER));
                $left = $gateway->complete($request);
                break;
            case 'a request that is refused':
                $transport->willReturn(new HttpResponse(422, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a redirect':
                $transport->willReturn(new HttpResponse(307, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a lost connection':
                $transport->willFail(TransportFailure::ConnectionFailed)->willFail(TransportFailure::ConnectionFailed);
                $left = $gateway->complete($request);
                break;
            case 'an error inside a 200':
                $transport->willReturn(new HttpResponse(200, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a body that cannot be decoded':
                $transport->willReturn(new HttpResponse(200, $headers, 'not JSON: ' . LEAK_MARKER));
                $left = $gateway->complete($request);
                break;
            case 'an exception of the adapter':
                $transport->willReturn(new HttpResponse(200, $headers, Talk::json(['explode' => 'exception', 'echo' => LEAK_MARKER])));
                $left = $gateway->complete($request);
                break;
            case 'a fault in the code of the adapter':
                $transport->willReturn(new HttpResponse(200, $headers, Talk::json(['explode' => 'error', 'echo' => LEAK_MARKER])));
                $left = $gateway->complete($request);
                break;
            case 'a fault in the code of the adapter while it reads a failure':
                $transport->willReturn(new HttpResponse(400, $headers, Talk::json(['fault' => ['code' => 'adapter_breaks'], 'echo' => LEAK_MARKER])));
                $left = $gateway->complete($request);
                break;
            case 'a token supplier that fails':
                $tokens->fails = 'exception';
                $left = $gateway->complete($request);
                break;
            case 'an address outside the base URL':
                $left = $gateway->complete($request);
                break;
            case 'a transport without an answer':
                $left = $gateway->complete($request);
                break;
            default:
                throw new LogicException('No such scenario.');
        }
    } catch (GatewayException $error) {
        return [$error->reason->value . ($error->providerCode === null ? '' : ' with a code'), exceptionForms($error)];
    } catch (Throwable $other) {
        return [$other::class, exceptionForms($other)];
    }

    if (is_array($left)) {
        $last = $left[count($left) - 1] ?? null;
        $kind = match (true) {
            $last instanceof StreamFailed => 'failed: ' . $last->error->reason->value . ($last->error->providerCode === null ? '' : ' with a code'),
            $last instanceof StreamFinished => 'finished',
            default => 'models',
        };
        $forms = valueForms($left);
        foreach ($left as $index => $item) {
            foreach (valueForms($item) as $form => $output) {
                $forms[$index . ' ' . $form] = $output;
            }
            if ($item instanceof StreamFailed) {
                foreach (exceptionForms($item->error) as $form => $output) {
                    $forms['error ' . $form] = $output;
                }
            }
        }

        return [$kind, $forms];
    }

    return ['answer', valueForms($left)];
}

describe('what leaves complete(), stream() and models()', function (): void {
    it('holds no key, no response body and no response header, in any output form', function (string $scenario, string $whatLeft): void {
        foreach ([false, true] as $ignoreArgs) {
            withExceptionIgnoreArgs($ignoreArgs, function () use ($scenario, $whatLeft): void {
                [$left, $forms] = leakOf($scenario);

                expect($left)->toBe($whatLeft);
                expectNoMarker($forms);
            });
        }
    })->with([
        ['an answer', 'answer'],
        ['an answer after a retry', 'answer'],
        ['a stream', 'finished'],
        ['a stream of a format without streaming', 'finished'],
        ['a stream that breaks', 'failed: unavailable'],
        ['a stream that is refused', 'failed: credentials_rejected'],
        ['a stream with an error inside', 'failed: provider_error'],
        ['a stream in which the adapter throws', 'failed: provider_error'],
        ['a stream in which the code of the adapter fails', Error::class],
        ['the model list', 'models'],
        ['a model list that is refused', 'credentials_rejected'],
        ['a wrong timeout', InvalidArgumentException::class],
        ['a sign-in that is refused', 'credentials_rejected'],
        ['a header key that comes back as the code', 'credentials_rejected'],
        ['a token that comes back as the code', 'credentials_rejected'],
        ['a code with the key inside it', 'credentials_rejected'],
        ['a code that starts with the key', 'provider_error'],
        ['a code that ends with the key, in a stream', 'failed: provider_error'],
        ['a detail of the adapter with the key inside it', 'provider_error'],
        ['a detail of the adapter with the key inside it, in a stream', 'failed: provider_error'],
        ['a value of the adapter that is no stream event', Error::class],
        ['a rate limit with a wait that is too long', 'rate_limited'],
        ['a server error after the retries', 'unavailable'],
        ['a request that is refused', 'rejected'],
        ['a redirect', 'unavailable'],
        ['a lost connection', 'unavailable'],
        ['an error inside a 200', 'provider_error'],
        ['a body that cannot be decoded', 'provider_error'],
        ['an exception of the adapter', 'provider_error'],
        ['a fault in the code of the adapter', Error::class],
        ['a fault in the code of the adapter while it reads a failure', Error::class],
        ['a token supplier that fails', 'credentials_rejected'],
        ['an address outside the base URL', LogicException::class],
        ['a transport without an answer', LogicException::class],
    ]);

    it('would show the marker if the code of the provider were not checked against the credentials: the scenarios do carry it', function (): void {
        // The same failure with another key: now the code is no credential, and it is kept.
        $transport = (new FakeTransport())->willReturn(new HttpResponse(401, [], Talk::json(['fault' => ['code' => LEAK_MARKER]])));
        $error = Talk::errorOf(fn() => Talk::gateway($transport, credentials: new BearerKey('another-key'))->complete(Talk::request()));

        expect($error->providerCode)->toBe(strtolower(LEAK_MARKER));
    });

    it('shows no key in a dump of the gateway itself, apart from what the transport of the host holds', function (): void {
        $gateway = new TalkAdapter(Talk::preset(), new GatewayConfig(RetryPolicy::none(), clock: new FakeClock()), new BearerKey(LEAK_MARKER), new FakeTransport());

        expectNoMarker(valueForms($gateway));
    });

    it('keeps the credentials out of the refusal of a gateway that cannot be built', function (): void {
        withExceptionIgnoreArgs(false, function (): void {
            try {
                GatewayFactory::create(Talk::preset(), new BearerKey(LEAK_MARKER), new FakeTransport(), new GatewayConfig(RetryPolicy::none(), null, ['unknown' => 'x']));
                $refusal = null;
            } catch (GatewayException $thrown) {
                $refusal = $thrown;
            }

            expect($refusal)->not->toBeNull();
            expectNoMarker(exceptionForms($refusal ?? new LogicException('not refused')));
        });
    });
});

describe('the parameters that carry a response, a request or a secret', function (): void {
    it('are marked as sensitive', function (string $class, string $method, array $expected): void {
        $sensitive = [];
        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                $sensitive[] = $parameter->getName();
            }
        }

        expect($sensitive)->toBe($expected);
    })->with([
        'the constructor' => [AbstractAdapter::class, '__construct', ['credentials']],
        'providerCode' => [AbstractAdapter::class, 'providerCode', ['response']],
        'refineReason' => [AbstractAdapter::class, 'refineReason', ['response']],
        'retryAfterSeconds' => [AbstractAdapter::class, 'retryAfterSeconds', ['response']],
        'answer' => [AbstractAdapter::class, 'answer', ['text', 'toolCalls', 'model', 'structured']],
        'failure' => [AbstractAdapter::class, 'failure', ['detail', 'providerCode']],
        'exchange' => [AbstractAdapter::class, 'exchange', ['template']],
        'read' => [AbstractAdapter::class, 'read', ['response']],
        'finished' => [AbstractAdapter::class, 'finished', ['answer']],
        'guarded' => [AbstractAdapter::class, 'guarded', ['chunks', 'read']],
        'whatTheAdapterReads' => [AbstractAdapter::class, 'whatTheAdapterReads', ['chunks']],
        'unreadable' => [AbstractAdapter::class, 'unreadable', ['broken']],
        'answerAsEvents' => [AbstractAdapter::class, 'answerAsEvents', ['answer']],
        'owned' => [AbstractAdapter::class, 'owned', ['template']],
        'signed' => [AbstractAdapter::class, 'signed', ['template']],
        'faultOfResponse' => [AbstractAdapter::class, 'faultOfResponse', ['response']],
        'adapterError' => [AbstractAdapter::class, 'adapterError', ['thrown']],
        'waitBeforeRetry' => [AbstractAdapter::class, 'waitBeforeRetry', ['fault']],
        'errorOfFault' => [AbstractAdapter::class, 'errorOfFault', ['fault', 'inUse']],
        'error' => [AbstractAdapter::class, 'error', ['code', 'detail', 'inUse']],
        'reportStarted' => [AbstractAdapter::class, 'reportStarted', ['request']],
        'reportFinished' => [AbstractAdapter::class, 'reportFinished', ['request', 'response', 'fault']],
        'SseReader::read' => [SseReader::class, 'read', ['chunks']],
        'SseReader::events' => [SseReader::class, 'events', ['chunks']],
        'Rules::isModelName' => [Rules::class, 'isModelName', ['name']],
        'Rules::plainProviderCode' => [Rules::class, 'plainProviderCode', ['providerCode']],
        'Rules::isHttpToken' => [Rules::class, 'isHttpToken', ['name']],
        'SseReader::line' => [SseReader::class, 'line', ['line', 'data', 'type', 'lastId']],
        'SseReader::utf8' => [SseReader::class, 'utf8', ['text']],
        'SseEvent' => [SseEvent::class, '__construct', ['data']],
        'CheckResult' => [CheckResult::class, '__construct', ['providerCode']],
        'GatewayConfig' => [GatewayConfig::class, '__construct', ['baseUrl']],
        'GatewayConfig, the URL check' => [GatewayConfig::class, 'assertBaseUrl', ['url']],
        'GatewayFactory::create' => [GatewayFactory::class, 'create', ['credentials']],
        'GatewayFactory, the address check' => [GatewayFactory::class, 'isAddress', ['url']],
        'Preset, the URL check' => [Preset::class, 'assertDefaultBaseUrl', ['url']],
    ]);

    it('covers every method of the adapter base that takes a response, a request of the transport, an answer or a failure', function (): void {
        $carriers = ['HttpRequest', 'HttpResponse', 'Answer', 'Error', 'Credentials'];
        $unmarked = [];
        foreach ((new ReflectionClass(AbstractAdapter::class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $name = $type instanceof ReflectionNamedType ? (string) strrchr('\\' . $type->getName(), '\\') : '';
                if (in_array(ltrim($name, '\\'), $carriers, true) && $parameter->getAttributes(SensitiveParameter::class) === [] && ! $method->isAbstract()) {
                    $unmarked[] = $method->getName() . '($' . $parameter->getName() . ')';
                }
            }
        }

        expect($unmarked)->toBe([]);
    });
});
