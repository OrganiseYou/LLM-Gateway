<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\Credentials\NoCredentials;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\Contract\StructuredResult;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Tests\Support\TalkAdapter;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

function talkTool(string $name = 'lookup'): ToolDefinition
{
    return new ToolDefinition($name, 'Looks something up.', JsonSchema::fromArray(['type' => 'object']));
}

function talkFormat(): ResponseFormat
{
    return new ResponseFormat(JsonSchema::fromArray(['type' => 'object']), 'verdict');
}

describe('the adapter base', function (): void {
    it('is the gateway: an abstract readonly class whose five operations and constructor cannot be replaced', function (): void {
        $reflection = new ReflectionClass(AbstractAdapter::class);

        expect($reflection->isAbstract())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->implementsInterface(Gateway::class))->toBeTrue()
            ->and($reflection->getStaticProperties())->toBe([]);

        foreach (['__construct', 'complete', 'stream', 'models', 'capabilities', 'check'] as $method) {
            expect($reflection->getMethod($method)->isFinal())->toBeTrue($method . ' must be final')
                ->and($reflection->getMethod($method)->isPublic())->toBeTrue($method . ' must be public');
        }
    });

    it('offers these protected methods to an adapter, and no other', function (): void {
        $methods = [];
        foreach ((new ReflectionClass(AbstractAdapter::class))->getMethods(ReflectionMethod::IS_PROTECTED) as $method) {
            $methods[$method->getName()] = $method->isAbstract() ? 'abstract' : ($method->isFinal() ? 'final' : 'open');
        }
        ksort($methods);

        expect($methods)->toBe([
            'answer' => 'final',
            'completionRequest' => 'abstract',
            'failure' => 'final',
            'modelCapabilities' => 'abstract',
            'modelsRequest' => 'abstract',
            'providerCode' => 'open',
            'readCompletion' => 'abstract',
            'readModels' => 'abstract',
            'readStream' => 'abstract',
            'refineReason' => 'open',
            'retryAfterSeconds' => 'open',
            'setting' => 'final',
            'url' => 'final',
        ]);
    });
});

describe('complete()', function (): void {
    it('sends one request to the base URL plus the path of the adapter, and returns the answer', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('Hello.'));

        $answer = Talk::gateway($transport)->complete(Talk::request());

        $sent = $transport->requests();
        expect($sent)->toHaveCount(1)
            ->and($sent[0]->method)->toBe('POST')
            ->and($sent[0]->url)->toBe('https://talk.example.test/api/talk')
            ->and(json_decode($sent[0]->body, true))->toMatchArray(['model' => 'model-a', 'stream' => false])
            ->and($answer->text)->toBe('Hello.')
            ->and($answer->structured)->toBeNull()
            ->and($answer->toolCalls)->toBe([])
            ->and($answer->finishReason)->toBe(FinishReason::Stop)
            ->and($answer->model)->toBe('model-a-0001')
            ->and($answer->usage)->toEqual(new Usage(3, 2))
            ->and($answer->attempts)->toBe(1)
            ->and($answer->traceId)->toBe('trace-1');
    });

    it('gives the requested model when the provider does not say which model answered', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('Hello.', ['by' => null]));

        expect(Talk::gateway($transport)->complete(Talk::request())->model)->toBe('model-a');
    });

    it('returns the tool calls, with unreadable arguments as a value', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('', ['end' => 'calls', 'calls' => [
            ['ref' => 'c1', 'tool' => 'lookup', 'input' => '{"q":"x"}'],
            ['tool' => 'lookup', 'input' => '{"q":'],
        ]]));

        $answer = Talk::gateway($transport)->complete(Talk::request()->withTools([talkTool()]));

        expect($answer->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($answer->toolCalls)->toHaveCount(2)
            ->and($answer->toolCalls[0]->arguments)->toBe(['q' => 'x'])
            ->and($answer->toolCalls[1]->id)->toBe('call_1')
            ->and($answer->toolCalls[1]->isReadable())->toBeFalse();
    });

    it('makes a trace id of 32 hex characters when the request has none, a new one for every call', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered())->willReturn(Talk::answered());
        $gateway = Talk::gateway($transport);

        $first = $gateway->complete(Talk::request(traceId: null))->traceId;
        $second = $gateway->complete(Talk::request(traceId: null))->traceId;

        expect($first)->toMatch('/^[0-9a-f]{32}$/D')
            ->and($second)->toMatch('/^[0-9a-f]{32}$/D')
            ->and($second)->not->toBe($first);
    });

    it('does not send the trace id to the provider', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        Talk::gateway($transport)->complete(Talk::request(traceId: 'trace-of-the-host'));

        expect(print_r($transport->requests()[0]->toArray(), true))->not->toContain('trace-of-the-host');
    });

    it('measures the latency of the whole call on the clock it was given', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willDelay(1.25)->willReturn(Talk::answered());

        expect(Talk::gateway($transport, $clock)->complete(Talk::request())->latencyMs)->toBe(1250);
    });

    it('gives the transport the time that is left of the call as the timeout of the attempt', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willDelay(4.0)->willReturn(Talk::failed(503))->willReturn(Talk::answered());

        Talk::gateway($transport, $clock, new RetryPolicy([1.0], 0.0))->complete(Talk::request(30.0));

        expect($transport->requests()[0]->timeoutSeconds)->toBe(30.0)
            ->and($transport->requests()[1]->timeoutSeconds)->toBe(25.0);
    });

    it('uses a response that did arrive, also when it arrived after the deadline', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willDelay(45.0)->willReturn(Talk::answered('Late.'));

        expect(Talk::gateway($transport, $clock)->complete(Talk::request(30.0))->text)->toBe('Late.');
    });

    it('keeps nothing between two calls: the same gateway answers two requests on their own', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::failed(400, 'bad_input'))->willReturn(Talk::answered('Second.'));
        $gateway = Talk::gateway($transport);

        $error = Talk::errorOf(fn() => $gateway->complete(Talk::request(traceId: 'trace-a')));
        $answer = $gateway->complete(Talk::request(traceId: 'trace-b'));

        expect($error->traceId)->toBe('trace-a')
            ->and($answer->traceId)->toBe('trace-b')
            ->and($answer->attempts)->toBe(1);
    });
});

describe('the sign-in header', function (): void {
    it('sends a bearer key as a secret authorization header', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        Talk::gateway($transport, credentials: new BearerKey('key-1'))->complete(Talk::request());

        $sent = $transport->requests()[0];
        expect(array_keys($sent->secretHeaders))->toBe(['Authorization'])
            ->and($sent->secretHeaders['Authorization'])->toBeInstanceOf(Secret::class)
            ->and($sent->secretHeaders['Authorization']->reveal())->toBe('Bearer key-1')
            ->and($sent->headers)->toBe(['Content-Type' => 'application/json'])
            ->and($sent->toArray()['headers']['Authorization'])->toBe('[secret]')
            ->and($sent->url)->not->toContain('key-1')
            ->and($sent->body)->not->toContain('key-1');
    });

    it('sends a header key as a secret in the header the preset names', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        Talk::gateway($transport, credentials: new HeaderKey('key-2'))->complete(Talk::request());

        $sent = $transport->requests()[0];
        expect(array_keys($sent->secretHeaders))->toBe(['X-Talk-Key'])
            ->and($sent->secretHeaders['X-Talk-Key']->reveal())->toBe('key-2')
            ->and($sent->toArray()['headers']['X-Talk-Key'])->toBe('[secret]');
    });

    it('sends nothing without credentials', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        Talk::gateway($transport, credentials: new NoCredentials())->complete(Talk::request());

        expect($transport->requests()[0]->secretHeaders)->toBe([]);
    });

    it('asks the token supplier before every attempt and keeps no token', function (): void {
        $tokens = Talk::tokens();
        $transport = (new FakeTransport())->willReturn(Talk::failed(503))->willReturn(Talk::answered())->willReturn(Talk::answered());
        $gateway = Talk::gateway($transport, null, new RetryPolicy([0.0], 0.0), new BearerToken($tokens));

        expect($tokens->asked)->toBe(0);

        $gateway->complete(Talk::request());
        $gateway->complete(Talk::request());

        $sent = array_map(fn($request): string => $request->secretHeaders['Authorization']->reveal(), $transport->requests());
        expect($sent)->toBe(['Bearer token-1', 'Bearer token-2', 'Bearer token-3'])
            ->and($tokens->asked)->toBe(3);
    });

    it('maps a token supplier that fails to credentials_rejected, sends nothing, retries nothing and copies nothing of its text', function (): void {
        $tokens = Talk::tokens();
        $tokens->fails = 'exception';
        $clock = new FakeClock();
        $transport = new FakeTransport($clock);
        $gateway = Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.0], 10.0), new BearerToken($tokens));

        $error = Talk::errorOf(fn() => $gateway->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::CredentialsRejected)
            ->and($error->attempts)->toBe(0)
            ->and($error->httpStatus)->toBeNull()
            ->and($error->detail)->toBe('the token supplier gave no usable token')
            ->and($error->getMessage())->not->toContain('Vendor')
            ->and($error->getMessage())->not->toContain('RuntimeException')
            ->and($error->getPrevious())->toBeNull()
            ->and($tokens->asked)->toBe(1)
            ->and($transport->requests())->toBe([])
            ->and($clock->sleeps())->toBe([]);
    });

    it('counts the attempts made before the token supplier failed, and does not go on', function (): void {
        $tokens = Talk::tokens();
        $tokens->failsFromAsk = 2;
        $transport = (new FakeTransport())->willReturn(Talk::failed(503));
        $gateway = Talk::gateway($transport, null, new RetryPolicy([0.0, 0.0], 0.0), new BearerToken($tokens));

        $error = Talk::errorOf(fn() => $gateway->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::CredentialsRejected)
            ->and($error->attempts)->toBe(1)
            ->and($error->httpStatus)->toBeNull()
            ->and($tokens->asked)->toBe(2)
            ->and($transport->requests())->toHaveCount(1);
    });

    it('maps a token that cannot be sent to credentials_rejected', function (): void {
        $tokens = Talk::tokens();
        $tokens->next = "token with a line break\n";
        $transport = new FakeTransport();

        $error = Talk::errorOf(fn() => Talk::gateway($transport, credentials: new BearerToken($tokens))->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::CredentialsRejected)
            ->and($error->attempts)->toBe(0)
            ->and($transport->requests())->toBe([]);
    });

    it('lets an error of the token supplier pass: a fault in the code of the host is no failed sign-in', function (): void {
        $tokens = Talk::tokens();
        $tokens->fails = 'error';
        $transport = new FakeTransport();
        $gateway = Talk::gateway($transport, null, new RetryPolicy([1.0], 10.0), new BearerToken($tokens));

        expect(fn() => $gateway->complete(Talk::request()))->toThrow(Error::class, 'The token supplier failed with an error of class TypeError.')
            ->and($transport->requests())->toBe([])
            ->and($tokens->asked)->toBe(1);
    });
});

describe('every address', function (): void {
    it('starts with the resolved base URL: the host of a request never changes, whatever the operation', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(Talk::failed(503))
            ->willReturn(Talk::answered())
            ->willStream(200, [], Talk::event('say', ['piece' => 'Hi']), Talk::event('end', ['end' => 'done']))
            ->willReturn(new HttpResponse(200, [], '{"items":[]}'));
        $gateway = Talk::gateway($transport, null, new RetryPolicy([0.0], 0.0));

        $gateway->complete(Talk::request());
        Talk::events($gateway->stream(Talk::request()));
        $gateway->models(10.0);

        expect(array_map(fn($request): string => $request->url, $transport->requests()))->toBe([
            'https://talk.example.test/api/talk',
            'https://talk.example.test/api/talk',
            'https://talk.example.test/api/talk?flow=1',
            'https://talk.example.test/api/catalog',
        ]);
    });

    it('starts with the base URL the host gave, without a second slash', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        $gateway = new TalkAdapter(Talk::preset(), new GatewayConfig(RetryPolicy::none(), 'http://my_service:8080/own/', clock: new FakeClock()), new NoCredentials(), $transport);

        $gateway->complete(Talk::request());

        expect($transport->requests()[0]->url)->toBe('http://my_service:8080/own/talk');
    });

    it('refuses to send a request of an adapter that does not stay under the base URL', function (string $url): void {
        $transport = new FakeTransport();
        $gateway = Talk::gateway($transport, options: ['foreign_url' => $url]);

        expect(fn() => $gateway->complete(Talk::request()))->toThrow(LogicException::class, 'An adapter must build every address with url()')
            ->and(fn() => Talk::events($gateway->stream(Talk::request())))->toThrow(LogicException::class, 'An adapter must build every address with url()')
            ->and($transport->requests())->toBe([]);
    })->with([
        'another host' => ['https://other.example.test/api/talk'],
        'a host that starts the same' => ['https://talk.example.test.other.example.test/api/talk'],
        'a path that starts the same' => ['https://talk.example.test/api-of-another/talk'],
        'another scheme' => ['http://talk.example.test/api/talk'],
        'another port' => ['https://talk.example.test:8443/api/talk'],
        'a dot segment that climbs out' => ['https://talk.example.test/api/../other'],
        'an encoded dot segment' => ['https://talk.example.test/api/%2E%2e/other'],
    ]);

    it('builds an address from a path and a query', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        $url = new ReflectionMethod($gateway, 'url');

        expect($url->invoke($gateway, '/models/' . rawurlencode('org/model:1') . ':run'))->toBe('https://talk.example.test/api/models/org%2Fmodel%3A1:run')
            ->and($url->invoke($gateway, '/talk', ['version' => '2026-01-01', 'page size' => 5]))->toBe('https://talk.example.test/api/talk?version=2026-01-01&page%20size=5');
    });

    it('refuses a path that could leave the base URL or is no path', function (string $path, string $message): void {
        $gateway = Talk::gateway(new FakeTransport());
        $url = new ReflectionMethod($gateway, 'url');

        expect(fn() => $url->invoke($gateway, $path))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'no slash at the start' => ['talk', 'A path must start with a slash'],
        'empty' => ['', 'A path must start with a slash'],
        'a query in the path' => ['/talk?x=1', 'A path must start with a slash'],
        'a fragment' => ['/talk#x', 'A path must start with a slash'],
        'a backslash' => ['/talk\\x', 'A path must start with a slash'],
        'a space' => ['/talk x', 'A path must start with a slash'],
        'a line break at the end' => ["/talk\n", 'A path must start with a slash'],
        'a dot segment' => ['/../talk', 'A path must not hold a dot segment.'],
        'a dot segment at the end' => ['/talk/..', 'A path must not hold a dot segment.'],
        'a single dot segment' => ['/./talk', 'A path must not hold a dot segment.'],
        'an encoded dot segment' => ['/%2e%2E/talk', 'A path must not hold a dot segment.'],
    ]);
});

describe('capabilities()', function (): void {
    it('answers from the preset and the adapter, and sends nothing', function (): void {
        $transport = new FakeTransport();
        $capabilities = Talk::gateway($transport, options: ['tools' => 'none', 'streams' => false, 'lists_models' => false])->capabilities('any/model:that.is-new');

        expect($capabilities)->toBeInstanceOf(Capabilities::class)
            ->and($capabilities->toolCalling)->toBe(Support::None)
            ->and($capabilities->structuredOutput)->toBe(Support::Native)
            ->and($capabilities->streaming)->toBe(Support::Emulated)
            ->and($capabilities->modelListing)->toBe(Support::None)
            ->and($transport->requests())->toBe([]);
    });

    it('refuses a model name that is empty or holds a control character', function (string $model): void {
        expect(fn() => Talk::gateway(new FakeTransport())->capabilities($model))
            ->toThrow(InvalidArgumentException::class, 'The model must not be empty and must not hold a control character.');
    })->with([
        'empty' => [''],
        'a line break' => ["model-a\n"],
        'a null byte' => ["model\0a"],
    ]);
});

describe('what a model cannot do', function (): void {
    it('is refused as not_supported before any request, by complete() and as the one event of stream()', function (array $options, Closure $request, string $detail): void {
        $transport = new FakeTransport();
        $gateway = Talk::gateway($transport, options: $options);

        $error = Talk::errorOf(fn() => $gateway->complete($request()));
        $events = Talk::events($gateway->stream($request()));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($error->attempts)->toBe(0)
            ->and($error->httpStatus)->toBeNull()
            ->and($error->traceId)->toBe('trace-1')
            ->and($error->detail)->toBe($detail)
            ->and($events)->toHaveCount(1)
            ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::NotSupported)
            ->and(Talk::failedIn($events)->error->detail)->toBe($detail)
            ->and(Talk::failedIn($events)->error->attempts)->toBe(0)
            ->and($transport->requests())->toBe([]);
    })->with([
        'tools without tool calling' => [
            ['tools' => 'none'],
            fn() => Talk::request()->withTools([talkTool()]),
            'this model cannot call tools',
        ],
        'a forced tool call without that capability' => [
            ['forced' => 'none'],
            fn() => Talk::request()->withTools([talkTool()])->withToolChoice(ToolChoice::tool('lookup')),
            'this model cannot be made to call one named tool',
        ],
        'a response format without structured output' => [
            ['structured' => 'none'],
            fn() => Talk::request()->withResponseFormat(talkFormat()),
            'this model cannot give a structured answer',
        ],
        'tools and a response format that cannot go together' => [
            ['tools_with_structured' => 'none'],
            fn() => Talk::request()->withTools([talkTool()])->withResponseFormat(talkFormat()),
            'this model cannot take tools and a response format in one request',
        ],
    ]);

    it('is not refused when the capability is emulated, and parallel tool calls refuse nothing', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        $gateway = Talk::gateway($transport, options: ['tools' => 'emulated', 'structured' => 'emulated', 'tools_with_structured' => 'emulated']);

        $answer = $gateway->complete(Talk::request()->withTools([talkTool(), talkTool('other')])->withResponseFormat(talkFormat()));

        expect($answer->text)->toBe('Hello.')
            ->and($transport->requests())->toHaveCount(1);
    });

    it('is refused as rejected before any request when the adapter cannot carry a value', function (): void {
        $transport = new FakeTransport();
        $gateway = Talk::gateway($transport, options: ['max_tool_name' => 5]);
        $request = Talk::request()->withTools([talkTool('a_long_tool_name')]);

        $error = Talk::errorOf(fn() => $gateway->complete($request));
        $events = Talk::events($gateway->stream($request));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->httpStatus)->toBeNull()
            ->and($error->attempts)->toBe(0)
            ->and($error->traceId)->toBe('trace-1')
            ->and($error->detail)->toBe('a tool name is longer than this format allows')
            ->and($events)->toHaveCount(1)
            ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::Rejected)
            ->and(Talk::failedIn($events)->error->traceId)->toBe('trace-1')
            ->and($transport->requests())->toBe([]);
    });
});

describe('the place of the structured check', function (): void {
    it('keeps the structured result an adapter got its own way, for a completion and for a stream without streaming', function (): void {
        $shaped = ['shaped' => ['ok' => true]];
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"ok":true}', $shaped))->willReturn(Talk::answered('{"ok":true}', $shaped));

        $answer = Talk::gateway($transport)->complete(Talk::request()->withResponseFormat(talkFormat()));
        $events = Talk::events(Talk::gateway($transport, options: ['streams' => false])->stream(Talk::request()->withResponseFormat(talkFormat())));

        expect($answer->structured)->toBeInstanceOf(StructuredResult::class)
            ->and($answer->structured?->data)->toBe(['ok' => true])
            ->and(Talk::finishedIn($events)->answer->structured?->data)->toBe(['ok' => true]);
    });

    it('drops a structured result of the adapter when the request has no response format', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"ok":true}', ['shaped' => ['ok' => true]]));

        expect(Talk::gateway($transport)->complete(Talk::request())->structured)->toBeNull();
    });

    it('checks the text itself when the adapter gave no structured result', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('{"ok":true}'));

        $structured = Talk::gateway($transport)->complete(Talk::request()->withResponseFormat(talkFormat()))->structured;

        expect($structured?->isUsable())->toBeTrue()
            ->and($structured?->data)->toBe(['ok' => true]);
    });
});

describe('a response that looks successful', function (): void {
    it('is a provider_error, not retried, when it has no text and no tool call', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::answered(''));
        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, new RetryPolicy([1.0], 5.0))->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->httpStatus)->toBe(200)
            ->and($error->attempts)->toBe(1)
            ->and($error->detail)->toBe('the answer has no text and no tool call')
            ->and($clock->sleeps())->toBe([]);
    });

    it('is a provider_error with the code of the provider when its body holds an error', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"fault":{"code":"Model_Busy"}}'));
        $error = Talk::errorOf(fn() => Talk::gateway($transport, null, new RetryPolicy([1.0], 5.0))->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->httpStatus)->toBe(200)
            ->and($error->providerCode)->toBe('model_busy')
            ->and($error->attempts)->toBe(1)
            ->and($error->traceId)->toBe('trace-1');
    });

    it('is a provider_error when its body cannot be decoded', function (string $body): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], $body));
        $error = Talk::errorOf(fn() => Talk::gateway($transport)->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->httpStatus)->toBe(200);
    })->with([
        'empty' => [''],
        'a page of a proxy' => ['<html><body>Bad gateway</body></html>'],
        'JSON that is no object' => ['"text"'],
        'text that is not valid UTF-8' => ["{\"say\":\"\xFF\",\"end\":\"done\"}"],
    ]);

    it('is a provider_error with a fixed detail when the adapter throws an exception while it reads, and nothing of that exception is kept', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"explode":"exception","text":"words of the provider"}'));
        $error = Talk::errorOf(fn() => Talk::gateway($transport)->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->detail)->toBe('the response could not be read')
            ->and($error->getPrevious())->toBeNull()
            ->and((string) $error)->not->toContain('words of the provider');
    });

    it('ends in a new error with a fixed text when the code of the adapter fails, and is not mapped to a reason or retried', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(new HttpResponse(200, [], '{"explode":"error","text":"words of the provider"}'));
        $gateway = Talk::gateway($transport, $clock, new RetryPolicy([1.0], 5.0));

        $thrown = null;
        try {
            $gateway->complete(Talk::request());
        } catch (Error $error) {
            $thrown = $error;
        }

        expect($thrown)->toBeInstanceOf(Error::class)
            ->and($thrown?->getMessage())->toMatch('/^The adapter failed with an error of class DivisionByZeroError, thrown in TalkAdapter\.php on line [0-9]+\.$/D')
            ->and($thrown?->getPrevious())->toBeNull()
            ->and($transport->requests())->toHaveCount(1)
            ->and($clock->sleeps())->toBe([]);
    });
});

describe('models()', function (): void {
    it('returns the models of the list, and skips what it cannot read', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], Talk::json(['items' => [
            ['key' => 'model-a', 'title' => 'Model A', 'window' => 8000, 'colour' => 'blue'],
            ['key' => 'org/model-b:1'],
            ['title' => 'No key'],
            'not an object',
        ]])));

        $models = Talk::gateway($transport)->models(10.0, 'trace-m');

        expect($models)->toEqual([new ModelInfo('model-a', 'Model A', 8000), new ModelInfo('org/model-b:1')])
            ->and($transport->requests()[0]->method)->toBe('GET')
            ->and($transport->requests()[0]->url)->toBe('https://talk.example.test/api/catalog')
            ->and($transport->requests()[0]->secretHeaders['Authorization']->reveal())->toBe('Bearer key-of-the-test')
            ->and($transport->requests()[0]->timeoutSeconds)->toBe(10.0);
    });

    it('returns an empty list when the provider lists nothing', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"items":[]}'));

        expect(Talk::gateway($transport)->models(10.0))->toBe([]);
    });

    it('fails as not_supported, without a request, when the models cannot be listed', function (): void {
        $transport = new FakeTransport();
        $error = Talk::errorOf(fn() => Talk::gateway($transport, options: ['lists_models' => false])->models(10.0, 'trace-m'));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($error->attempts)->toBe(0)
            ->and($error->traceId)->toBe('trace-m')
            ->and($transport->requests())->toBe([]);
    });

    it('retries and maps a failure as a completion does, with its own trace id and deadline', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))
            ->willReturn(Talk::failed(503))
            ->willReturn(new HttpResponse(200, [], '{"items":[{"key":"model-a"}]}'))
            ->willReturn(Talk::failed(401, 'key_unknown'))
            ->willDelay(11.0)->willReturn(Talk::failed(503));
        $gateway = Talk::gateway($transport, $clock, new RetryPolicy([2.0], 0.0));

        expect($gateway->models(10.0))->toHaveCount(1)
            ->and($clock->sleeps())->toBe([2.0]);

        $rejected = Talk::errorOf(fn() => $gateway->models(10.0, 'trace-m'));
        expect($rejected->reason)->toBe(ErrorReason::CredentialsRejected)
            ->and($rejected->providerCode)->toBe('key_unknown')
            ->and($rejected->traceId)->toBe('trace-m')
            ->and($rejected->attempts)->toBe(1);

        $late = Talk::errorOf(fn() => $gateway->models(10.0));
        expect($late->reason)->toBe(ErrorReason::Unavailable)
            ->and($late->traceId)->toMatch('/^[0-9a-f]{32}$/D')
            ->and($late->attempts)->toBe(1)
            ->and($clock->sleeps())->toBe([2.0]);
    });

    it('refuses a timeout that is not finite and above 0', function (float $timeout): void {
        expect(fn() => Talk::gateway(new FakeTransport())->models($timeout))
            ->toThrow(InvalidArgumentException::class, 'The timeout must be a finite number of seconds above 0.');
    })->with([[0.0], [-1.0], [INF], [NAN]]);

    it('refuses a trace id that breaks the pattern', function (string $traceId): void {
        expect(fn() => Talk::gateway(new FakeTransport())->models(10.0, $traceId))
            ->toThrow(InvalidArgumentException::class, 'The trace id must match');
    })->with([
        'empty' => [''],
        'a space' => ['trace 1'],
        'longer than 128' => [str_repeat('a', 129)],
        'a line break at the end' => ["trace-1\n"],
    ]);
});

describe('check()', function (): void {
    it('is refused as not_supported, without a request, until the connection check is built', function (): void {
        $transport = new FakeTransport();
        $gateway = Talk::gateway($transport);

        $error = Talk::errorOf(fn() => $gateway->check('model-a', 10.0, 'trace-c'));
        $fresh = Talk::errorOf(fn() => $gateway->check('model-a', 10.0));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($error->traceId)->toBe('trace-c')
            ->and($error->attempts)->toBe(0)
            ->and($fresh->traceId)->toMatch('/^[0-9a-f]{32}$/D')
            ->and($transport->requests())->toBe([]);
    });

    it('refuses a model, a timeout and a trace id that break their rules', function (string $model, float $timeout, ?string $traceId, string $message): void {
        expect(fn() => Talk::gateway(new FakeTransport())->check($model, $timeout, $traceId))
            ->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'an empty model' => ['', 10.0, null, 'The model must not be empty'],
        'a timeout of 0' => ['model-a', 0.0, null, 'The timeout must be a finite number'],
        'a trace id with a space' => ['model-a', 10.0, 'trace 1', 'The trace id must match'],
    ]);
});

describe('a request with messages', function (): void {
    it('reaches the adapter as the caller gave it', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered());
        $request = new Request('org/model:1', [Message::system('Be brief.'), Message::user('Hi.')], 5.0);

        Talk::gateway($transport)->complete($request);

        expect(json_decode($transport->requests()[0]->body, true))->toMatchArray([
            'model' => 'org/model:1',
            'lines' => [['who' => 'system', 'say' => 'Be brief.'], ['who' => 'user', 'say' => 'Hi.']],
        ]);
    });
});

describe('what an adapter can reach', function (): void {
    it('reads a setting of the customer by its key, and null when the customer gave none', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered())->willReturn(Talk::answered());

        Talk::gateway($transport, settings: ['dialect' => 'plain'])->complete(Talk::request());
        Talk::gateway($transport)->complete(Talk::request());

        expect(json_decode($transport->requests()[0]->body, true))->toMatchArray(['dialect' => 'plain'])
            ->and(json_decode($transport->requests()[1]->body, true))->toMatchArray(['dialect' => null]);
    });

    it('reaches the preset, and not the configuration, the credentials or the transport', function (): void {
        $visible = [];
        foreach ((new ReflectionClass(AbstractAdapter::class))->getProperties() as $property) {
            $visible[$property->getName()] = $property->isPrivate() ? 'private' : 'protected or public';
        }
        ksort($visible);

        expect($visible)->toBe([
            'baseUrl' => 'private',
            'config' => 'private',
            'credentials' => 'private',
            'preset' => 'protected or public',
            'transport' => 'private',
        ]);
    });
});

describe('an answer without text and without a tool call', function (): void {
    it('is returned, with its usage, when it was cut off at the token limit', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('', ['end' => 'cut', 'used' => ['in' => 9, 'out' => 64]]));

        $answer = Talk::gateway($transport)->complete(Talk::request()->withResponseFormat(talkFormat()));

        expect($answer->text)->toBe('')
            ->and($answer->toolCalls)->toBe([])
            ->and($answer->finishReason)->toBe(FinishReason::Length)
            ->and($answer->usage)->toEqual(new Usage(9, 64))
            ->and($answer->attempts)->toBe(1)
            ->and(fn() => $answer->toMessage())->toThrow(LogicException::class);
    });

    it('is the end of a stream, and of a stream without streaming, when it was cut off at the token limit', function (): void {
        $transport = (new FakeTransport())
            ->willStream(200, [], Talk::event('used', ['in' => 9, 'out' => 64]), Talk::event('end', ['end' => 'cut']))
            ->willReturn(Talk::answered('', ['end' => 'cut']));

        $streamed = Talk::events(Talk::gateway($transport)->stream(Talk::request()));
        $whole = Talk::events(Talk::gateway($transport, options: ['streams' => false])->stream(Talk::request()));

        expect($streamed)->toHaveCount(2)
            ->and(Talk::finishedIn($streamed)->answer->text)->toBe('')
            ->and(Talk::finishedIn($streamed)->answer->finishReason)->toBe(FinishReason::Length)
            ->and(Talk::finishedIn($streamed)->answer->usage)->toEqual(new Usage(9, 64))
            ->and($whole)->toHaveCount(2)
            ->and(Talk::finishedIn($whole)->answer->finishReason)->toBe(FinishReason::Length);
    });

    it('stays a provider_error for every other finish reason', function (string $end): void {
        $transport = (new FakeTransport())->willReturn(Talk::answered('', ['end' => $end]))->willStream(200, [], Talk::event('end', ['end' => $end]));
        $gateway = Talk::gateway($transport);

        expect(Talk::errorOf(fn() => $gateway->complete(Talk::request()))->reason)->toBe(ErrorReason::ProviderError)
            ->and(Talk::failedIn(Talk::events($gateway->stream(Talk::request())))->error->reason)->toBe(ErrorReason::ProviderError);
    })->with([['done'], ['calls'], ['blocked'], ['something else']]);
});

describe('an error an adapter raises while it states the capabilities', function (): void {
    it('gets the trace id of the call, in complete() and in the failed event of stream()', function (): void {
        $transport = new FakeTransport();
        $gateway = Talk::gateway($transport, options: ['capabilities_fail' => true]);

        $error = Talk::errorOf(fn() => $gateway->complete(Talk::request(traceId: 'trace-of-the-call')));
        $failed = Talk::failedIn(Talk::events($gateway->stream(Talk::request(traceId: 'trace-of-the-stream'))));
        $outside = Talk::errorOf(fn() => $gateway->capabilities('model-a'));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->traceId)->toBe('trace-of-the-call')
            ->and($error->detail)->toBe('this server is not set up for the model')
            ->and($error->attempts)->toBe(0)
            ->and($failed->error->traceId)->toBe('trace-of-the-stream')
            ->and($failed->error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($outside->traceId)->toMatch('/^[0-9a-f]{32}$/D')
            ->and($transport->requests())->toBe([]);
    });
});
