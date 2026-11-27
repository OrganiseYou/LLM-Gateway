<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContent;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// What the generate-content adapter sends (docs/design/adapters.md, section 4.2): the address with the model in its
// path, the sign-in in a header, the closed list of fields, and the transcript in the shape of the format.

/**
 * A request for a model, with one user message.
 */
function generateRequestFor(string $model): Request
{
    return new Request($model, [Message::user('Say hello.')], 30.0);
}

describe('the address of a request of the generate-content adapter', function (): void {
    it('posts a completion to the method of the model under the base URL, and a stream to its streaming method as server-sent events', function (): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered())->willStream(200, [], ...GenerateContent::saying());
        $gateway = GenerateContent::gateway($transport);

        $gateway->complete(GenerateContent::request());
        Talk::finishedIn(Talk::events($gateway->stream(GenerateContent::request())));

        expect($transport->requests()[0]->method)->toBe('POST')
            ->and($transport->requests()[0]->url)->toBe('https://llm.example.test/v1beta/models/example-generate-1:generateContent')
            ->and($transport->requests()[1]->method)->toBe('POST')
            ->and($transport->requests()[1]->url)->toBe('https://llm.example.test/v1beta/models/example-generate-1:streamGenerateContent?alt=sse');
    });

    it('sends the same body for a stream as for a whole answer: the format has no field that asks for a stream', function (): void {
        $request = GenerateContent::request()->withTools([GenerateContent::lookup()]);

        expect(GenerateContent::bodyFor($request, stream: true))->toBe(GenerateContent::bodyFor($request));
    });

    it('refuses a model name that cannot stand in the path as one segment, before anything is sent', function (string $model): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered())->willStream(200, [], ...GenerateContent::saying());
        $gateway = GenerateContent::gateway($transport);

        $error = Talk::errorOf(fn() => $gateway->complete(generateRequestFor($model)));
        $events = Talk::events($gateway->stream(generateRequestFor($model)));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->attempts)->toBe(0)
            ->and($error->httpStatus)->toBeNull()
            ->and($error->detail)->toBe('the model name cannot stand in the address of this format: a letter or a digit, then letters, digits, underscores, hyphens and single dots, at most 128')
            ->and($events)->toHaveCount(1)
            ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::Rejected)
            ->and($transport->requests())->toBe([]);
    })->with([
        'a slash' => ['tuned/example-1'],
        'a resource name' => ['models/example-generate-1'],
        'two dots' => ['..'],
        'one dot' => ['.'],
        'two dots inside a name' => ['example..generate'],
        'a way up' => ['../../v1/other'],
        'a question mark' => ['example-1?key=1'],
        'a fragment' => ['example-1#x'],
        'an encoded slash' => ['example%2f..%2fother'],
        'an encoded dot' => ['%2e%2e'],
        'a colon' => ['example-1:streamGenerateContent'],
        'an at sign' => ['user@other.example.test'],
        'a whole address' => ['https://other.example.test/v1/models/x'],
        'an address without a scheme' => ['//other.example.test/x'],
        'a backslash' => ['example\\other'],
        'a space' => ['example generate'],
        'a leading hyphen' => ['-example'],
        'a leading underscore' => ['_example'],
        'one character more than 128' => [str_repeat('m', 129)],
        'a leading dot' => ['.example'],
        'a letter outside ASCII' => ['exämple'],
        'a semicolon' => ['example;v=1'],
        'an ampersand' => ['example&alt=json'],
    ]);

    it('never gets a model name with a line end: the request itself refuses one', function (string $model): void {
        expect(fn() => generateRequestFor($model))->toThrow(InvalidValue::class);
    })->with([["example\r\nHost: other.example.test"], ["example\nx"], ["example\rx"], ["example\0"]]);

    it('writes a model name of letters, digits, dots, underscores and hyphens into the path as it is, on the host of the gateway', function (string $model): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered())->willStream(200, [], ...GenerateContent::saying());
        $gateway = GenerateContent::gateway($transport);

        $gateway->complete(generateRequestFor($model));
        Talk::finishedIn(Talk::events($gateway->stream(generateRequestFor($model))));

        expect($transport->requests()[0]->url)->toBe(GenerateContent::BASE_URL . '/models/' . $model . ':generateContent')
            ->and($transport->requests()[1]->url)->toBe(GenerateContent::BASE_URL . '/models/' . $model . ':streamGenerateContent?alt=sse');
        foreach ($transport->requests() as $sent) {
            expect(parse_url($sent->url, PHP_URL_HOST))->toBe('llm.example.test');
        }
    })->with([['example-generate-1'], ['example-2.5-flash'], ['Example_3'], ['3-example.preview-06'], [str_repeat('m', 128)]]);
});

describe('the sign-in of a request of the generate-content adapter', function (): void {
    it('sends a key in the header of the preset and never in the address or the body', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(GenerateContent::answered())
            ->willStream(200, [], ...GenerateContent::saying())
            ->willReturn(new OrganiseYou\LlmGateway\Transport\HttpResponse(200, [], '{"models":[]}'));
        $gateway = GenerateContent::gateway($transport, credentials: new HeaderKey('key-of-the-test'));

        $gateway->complete(GenerateContent::request());
        Talk::finishedIn(Talk::events($gateway->stream(GenerateContent::request())));
        $gateway->models(10.0);

        expect($transport->requests())->toHaveCount(3);
        foreach ($transport->requests() as $sent) {
            expect($sent->secretHeaders['x-goog-api-key']->reveal())->toBe('key-of-the-test')
                ->and(array_keys($sent->secretHeaders))->toBe(['x-goog-api-key'])
                ->and($sent->url)->not->toContain('key')
                ->and($sent->body)->not->toContain('key-of-the-test')
                ->and(implode("\n", $sent->headers))->not->toContain('key-of-the-test');
        }
    });

    it('sends a bearer key and a token from a supplier in the authorization header, and never in the address', function (): void {
        foreach ([new BearerKey('key-of-the-test'), new BearerToken(Talk::tokens())] as $credentials) {
            $transport = (new FakeTransport())->willReturn(GenerateContent::answered());
            GenerateContent::gateway($transport, credentials: $credentials)->complete(GenerateContent::request());
            $sent = $transport->requests()[0];

            expect(array_keys($sent->secretHeaders))->toBe(['Authorization'])
                ->and($sent->secretHeaders['Authorization']->reveal())->toStartWith('Bearer ')
                ->and($sent->url)->toBe(GenerateContent::BASE_URL . '/models/example-generate-1:generateContent');
        }
    });

    it('asks the token supplier once for every attempt, and signs each attempt with the token it then gave', function (): void {
        $tokens = Talk::tokens();
        $transport = (new FakeTransport())
            ->willReturn(GenerateContent::failed(503, 'UNAVAILABLE'))
            ->willReturn(GenerateContent::failed(429, 'RESOURCE_EXHAUSTED'))
            ->willReturn(GenerateContent::answered());
        $gateway = GenerateContent::gateway($transport, retry: new RetryPolicy([1.0, 1.0], 60.0), credentials: new BearerToken($tokens));

        expect($tokens->asked)->toBe(0);
        $answer = $gateway->complete(GenerateContent::request());

        expect($answer->attempts)->toBe(3)
            ->and($tokens->asked)->toBe(3);
        foreach ($transport->requests() as $at => $sent) {
            expect($sent->secretHeaders['Authorization']->reveal())->toBe('Bearer token-' . ($at + 1));
        }
    });

    it('asks the token supplier for a stream and for the model list too, and not for capabilities', function (): void {
        $tokens = Talk::tokens();
        $transport = (new FakeTransport())->willStream(200, [], ...GenerateContent::saying())->willReturn(new OrganiseYou\LlmGateway\Transport\HttpResponse(200, [], '{"models":[]}'));
        $gateway = GenerateContent::gateway($transport, credentials: new BearerToken($tokens));

        $gateway->capabilities(GenerateContent::MODEL);
        expect($tokens->asked)->toBe(0);

        Talk::finishedIn(Talk::events($gateway->stream(GenerateContent::request())));
        $gateway->models(10.0);

        expect($tokens->asked)->toBe(2)
            ->and($transport->requests()[1]->secretHeaders['Authorization']->reveal())->toBe('Bearer token-2');
    });

    it('carries a setting of the preset as a header of its own beside the header of the adapter, and the two do not clash', function (): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered())->willReturn(new OrganiseYou\LlmGateway\Transport\HttpResponse(200, [], '{"models":[]}'));
        $gateway = GenerateContent::gateway($transport, settings: ['quota_project' => 'example-project']);

        $gateway->complete(GenerateContent::request());
        $gateway->models(10.0);

        expect($transport->requests()[0]->headers)->toBe(['content-type' => 'application/json', 'x-goog-user-project' => 'example-project'])
            ->and($transport->requests()[1]->headers)->toBe(['x-goog-user-project' => 'example-project'])
            ->and(array_keys($transport->requests()[0]->secretHeaders))->toBe(['x-goog-api-key']);
    });

    it('sends only the content type of its own when the setting is not filled in, and no header with an underscore', function (): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered());
        GenerateContent::gateway($transport)->complete(GenerateContent::request());

        expect($transport->requests()[0]->headers)->toBe(['content-type' => 'application/json']);
    });
});

describe('the fields of a request of the generate-content adapter', function (): void {
    it('sends nothing but the contents for a plain request', function (): void {
        expect(GenerateContent::bodyFor(GenerateContent::request()))->toBe([
            'contents' => [['role' => 'user', 'parts' => [['text' => 'Say hello.']]]],
        ]);
    });

    it('never sends a field outside its closed list, also for a request that uses everything', function (array $options, bool $stream): void {
        $request = new Request(
            GenerateContent::MODEL,
            [
                Message::system('You are a travel desk.'),
                Message::user('Where to?'),
                Message::assistant('', ToolCall::decoded('fc-1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')),
                Message::toolResult('fc-1', 'lookup', '{"degrees":18}'),
            ],
            30.0,
            [GenerateContent::lookup()],
            ToolChoice::required(),
            GenerateContent::trip(),
            500,
            0.5,
            'trace-1',
        );
        $body = GenerateContent::bodyFor($request, $options, $stream);
        $written = GenerateContent::json($body);

        expect(array_keys($body))->toBe(['systemInstruction', 'contents', 'tools', 'toolConfig', 'generationConfig', 'store'])
            ->and(array_diff(array_keys($body), GenerateContent::FIELDS))->toBe([])
            ->and(array_diff(array_keys($body['generationConfig']), GenerateContent::GENERATION_FIELDS))->toBe([])
            ->and($body['store'])->toBeFalse()
            ->and(array_keys($body['toolConfig']))->toBe(['functionCallingConfig'])
            ->and(array_keys($body['tools'][0]))->toBe(['functionDeclarations']);
        foreach (['safetySettings', 'cachedContent', 'labels', 'serviceTier', 'streamFunctionCallArguments', 'continuationToken', 'modelArmorConfig', 'responseSchema', 'candidateCount', 'thinkingConfig', 'trace-1', '"model":', '"stream":'] as $never) {
            expect($written)->not->toContain($never);
        }
    })->with([
        'the first schema field' => [['store_field' => 'false', 'tools_with_structured_output' => 'native'], false],
        'the first schema field, as a stream' => [['store_field' => 'false', 'tools_with_structured_output' => 'native'], true],
        'the second schema field' => [['store_field' => 'false', 'tools_with_structured_output' => 'native', 'structured_output_field' => 'response_format'], false],
        'the second schema field, as a stream' => [['store_field' => 'false', 'tools_with_structured_output' => 'native', 'structured_output_field' => 'response_format'], true],
    ]);

    it('sends `store: false` only where the preset says so, and never `store: true`', function (): void {
        expect(GenerateContent::bodyFor(GenerateContent::request(), ['store_field' => 'false'])['store'])->toBeFalse()
            ->and(GenerateContent::bodyFor(GenerateContent::request(), ['store_field' => 'omit']))->not->toHaveKey('store')
            ->and(GenerateContent::bodyFor(GenerateContent::request()))->not->toHaveKey('store');
    });

    it('writes the maximum of output tokens and the temperature into the generation settings, and leaves them out when the request gives none', function (): void {
        $request = new Request(GenerateContent::MODEL, [Message::user('Hi.')], 30.0, maxOutputTokens: 200, temperature: 0.0);

        expect(GenerateContent::bodyFor($request)['generationConfig'])->toBe(['maxOutputTokens' => 200, 'temperature' => 0])
            ->and(GenerateContent::bodyFor(GenerateContent::request()))->not->toHaveKey('generationConfig');
    });

    it('joins the system messages with a blank line in the system instruction, which is no content', function (): void {
        $body = GenerateContent::bodyFor(GenerateContent::request(Message::system('Be brief.'), Message::system('Be kind.'), Message::user('Hi.')));

        expect($body['systemInstruction'])->toBe(['parts' => [['text' => "Be brief.\n\nBe kind."]]])
            ->and($body['contents'])->toBe([['role' => 'user', 'parts' => [['text' => 'Hi.']]]]);
    });

    it('refuses a transcript of system messages alone before anything is sent', function (): void {
        $transport = new FakeTransport();
        $error = Talk::errorOf(fn() => GenerateContent::gateway($transport)->complete(GenerateContent::request(Message::system('Be brief.'))));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->detail)->toBe('the transcript holds only system messages, and this format needs a message beside its system text')
            ->and($transport->requests())->toBe([]);
    });
});

describe('the tools of a request of the generate-content adapter', function (): void {
    it('declares every tool with its name, its description and its schema as plain JSON Schema', function (): void {
        $empty = new ToolDefinition('ping', '', JsonSchema::fromJson('{"type":"object","properties":{}}'));
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered());
        GenerateContent::gateway($transport)->complete(GenerateContent::request()->withTools([GenerateContent::lookup(), $empty]));

        expect(GenerateContent::sent($transport)['tools'])->toBe([['functionDeclarations' => [
            ['name' => 'lookup', 'description' => 'Looks up a city.', 'parametersJsonSchema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']]],
            ['name' => 'ping', 'description' => '', 'parametersJsonSchema' => ['type' => 'object', 'properties' => []]],
        ]]])
            // An empty object of a schema stays an object.
            ->and($transport->requests()[0]->body)->toContain('"properties":{}}');
    });

    it('leaves the tools and the tool settings out of a request without tools', function (): void {
        $body = GenerateContent::bodyFor(GenerateContent::request());

        expect($body)->not->toHaveKey('tools')
            ->and($body)->not->toHaveKey('toolConfig');
    });

    it('writes the tool choice as a mode of the format', function (ToolChoice $choice, array $expected): void {
        $body = GenerateContent::bodyFor(GenerateContent::request()->withTools([GenerateContent::lookup(), GenerateContent::lookup('other')])->withToolChoice($choice));

        expect($body['toolConfig'])->toBe(['functionCallingConfig' => $expected]);
    })->with([
        'auto' => [ToolChoice::auto(), ['mode' => 'AUTO']],
        'none' => [ToolChoice::none(), ['mode' => 'NONE']],
        'required' => [ToolChoice::required(), ['mode' => 'ANY']],
        'one tool' => [ToolChoice::tool('other'), ['mode' => 'ANY', 'allowedFunctionNames' => ['other']]],
    ]);

    it('forces a named tool the same way whatever the preset says of a forced call or of parallel calls: nothing more is sent', function (): void {
        $request = GenerateContent::request()->withTools([GenerateContent::lookup()])->withToolChoice(ToolChoice::tool('lookup'));

        expect(GenerateContent::bodyFor($request, ['forced_tool_call' => 'emulated', 'parallel_tool_calls' => 'none']))->toBe(GenerateContent::bodyFor($request));
    });

    it('refuses a tool name outside the rule that holds for both ways in, before anything is sent', function (string $name): void {
        $transport = new FakeTransport();
        $error = Talk::errorOf(fn() => GenerateContent::gateway($transport)->complete(GenerateContent::request()->withTools([GenerateContent::lookup($name)])));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->attempts)->toBe(0)
            ->and($error->detail)->toBe('a tool name is not one this format carries: a letter or an underscore, then letters, digits and underscores, at most 64')
            ->and($transport->requests())->toBe([]);
    })->with([
        'a hyphen' => ['look-up'],
        'a dot' => ['look.up'],
        'a colon' => ['look:up'],
        'a digit first' => ['1lookup'],
        'sixty-five characters' => [str_repeat('n', 65)],
        'a letter outside ASCII' => ['löökup'],
    ]);

    it('carries a tool name inside the rule', function (string $name): void {
        expect(GenerateContent::bodyFor(GenerateContent::request()->withTools([GenerateContent::lookup($name)]))['tools'][0]['functionDeclarations'][0]['name'])->toBe($name);
    })->with([['lookup'], ['_lookup'], ['Look_Up_2'], [str_repeat('n', 64)]]);
});

describe('the transcript of a request of the generate-content adapter', function (): void {
    it('writes a user message as a `user` content and an assistant message as a `model` content, each message a content of its own', function (): void {
        $body = GenerateContent::bodyFor(GenerateContent::request(
            Message::user('One.'),
            Message::user(''),
            Message::assistant('Two.'),
            Message::assistant(''),
            Message::user('Three.'),
        ));

        expect($body['contents'])->toBe([
            ['role' => 'user', 'parts' => [['text' => 'One.']]],
            ['role' => 'user', 'parts' => [['text' => '']]],
            ['role' => 'model', 'parts' => [['text' => 'Two.']]],
            ['role' => 'model', 'parts' => [['text' => '']]],
            ['role' => 'user', 'parts' => [['text' => 'Three.']]],
        ]);
    });

    it('sends a transcript that ends with an assistant message as it is', function (): void {
        $body = GenerateContent::bodyFor(GenerateContent::request(Message::user('Hi.'), Message::assistant('Hello')));

        expect($body['contents'][1])->toBe(['role' => 'model', 'parts' => [['text' => 'Hello']]]);
    });

    it('sends the tool results of one round as one `user` content, in the order of the calls, each with its name, its id and its text as the output', function (): void {
        $body = GenerateContent::bodyFor(GenerateContent::request(
            Message::user('Where to?'),
            Message::assistant(
                'Let me look.',
                ToolCall::decoded('fc-1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'),
                ToolCall::decoded('fc-2', 'weather', ['city' => 'Rome'], '{"city":"Rome"}'),
            ),
            // Given the other way round, and with a result for a call the assistant message does not hold.
            Message::toolResult('fc-9', 'other', 'unknown'),
            Message::toolResult('fc-2', 'weather', 'sunny'),
            Message::toolResult('fc-1', 'lookup', '{"degrees":18}'),
            Message::user('And now?'),
        ));

        expect($body['contents'])->toHaveCount(4)
            ->and($body['contents'][2])->toBe(['role' => 'user', 'parts' => [
                ['functionResponse' => ['id' => 'fc-1', 'name' => 'lookup', 'response' => ['output' => '{"degrees":18}']]],
                ['functionResponse' => ['id' => 'fc-2', 'name' => 'weather', 'response' => ['output' => 'sunny']]],
                ['functionResponse' => ['id' => 'fc-9', 'name' => 'other', 'response' => ['output' => 'unknown']]],
            ]])
            ->and($body['contents'][3])->toBe(['role' => 'user', 'parts' => [['text' => 'And now?']]]);
    });

    it('keeps the results of two rounds apart, each after its own model turn', function (): void {
        $body = GenerateContent::bodyFor(GenerateContent::request(
            Message::user('Where to?'),
            Message::assistant('', ToolCall::decoded('fc-1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')),
            Message::toolResult('fc-1', 'lookup', 'one'),
            Message::assistant('', ToolCall::decoded('fc-2', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}')),
            Message::toolResult('fc-2', 'lookup', 'two'),
        ));

        expect(array_column($body['contents'], 'role'))->toBe(['user', 'model', 'user', 'model', 'user'])
            ->and($body['contents'][2]['parts'][0]['functionResponse']['response'])->toBe(['output' => 'one'])
            ->and($body['contents'][4]['parts'][0]['functionResponse']['response'])->toBe(['output' => 'two']);
    });

    it('sends a hand-built assistant message with tool calls with the documented skip value on its first call, and on no other part', function (): void {
        $body = GenerateContent::bodyFor(GenerateContent::request(
            Message::user('Where to?'),
            Message::assistant(
                'Let me look.',
                ToolCall::decoded('fc-1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'),
                ToolCall::decoded('fc-2', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}'),
            ),
            Message::toolResult('fc-1', 'lookup', 'one'),
            Message::toolResult('fc-2', 'lookup', 'two'),
        ));

        expect($body['contents'][1])->toBe(['role' => 'model', 'parts' => [
            ['text' => 'Let me look.'],
            ['functionCall' => ['id' => 'fc-1', 'name' => 'lookup', 'args' => ['city' => 'Paris']], 'thoughtSignature' => 'skip_thought_signature_validator'],
            ['functionCall' => ['id' => 'fc-2', 'name' => 'lookup', 'args' => ['city' => 'Rome']]],
        ]])
            ->and(GenerateContent::NO_SIGNATURE)->toBe('skip_thought_signature_validator');
    });

    it('sends no skip value with an assistant message that holds no tool call', function (): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered());
        GenerateContent::gateway($transport)->complete(GenerateContent::request(Message::user('Hi.'), Message::assistant('Hello.'), Message::user('Again.')));

        expect($transport->requests()[0]->body)->not->toContain('thoughtSignature');
    });

    it('gives a call that came without an id an id of its own, and does not send that id back: not with the call and not with its result', function (bool $asStream): void {
        $parts = [GenerateContent::call(['city' => 'Paris'], null), GenerateContent::call(['city' => 'Rome'], null)];
        $transport = new FakeTransport();
        $gateway = GenerateContent::gateway($transport);
        $request = GenerateContent::request()->withTools([GenerateContent::lookup()]);

        if ($asStream) {
            $transport->willStream(200, [], GenerateContent::chunk($parts, 'STOP'));
            $answer = Talk::finishedIn(Talk::events($gateway->stream($request)))->answer;
        } else {
            $transport->willReturn(GenerateContent::answered($parts));
            $answer = $gateway->complete($request);
        }

        expect(array_map(fn(ToolCall $call): string => $call->id, $answer->toolCalls))->toBe(['call-0', 'call-1']);

        // Once with the message of the answer, once with a message a caller built by hand from the same calls.
        foreach ([$answer->toMessage(), Message::assistant('', ...$answer->toolCalls)] as $at => $message) {
            $transport->willReturn(GenerateContent::answered());
            $gateway->complete($request->withMessages([
                ...$request->messages,
                $message,
                Message::toolResult('call-1', 'lookup', 'two'),
                Message::toolResult('call-0', 'lookup', 'one'),
            ]));
            $sent = GenerateContent::sent($transport, $at + 1);
            // The message of the answer goes back as it came; only the one built by hand carries the skip value.
            $first = ['functionCall' => ['name' => 'lookup', 'args' => ['city' => 'Paris']]];

            expect($sent['contents'][1]['parts'])->toBe([
                $at === 0 ? $first : [...$first, 'thoughtSignature' => 'skip_thought_signature_validator'],
                ['functionCall' => ['name' => 'lookup', 'args' => ['city' => 'Rome']]],
            ])
                ->and($sent['contents'][2]['parts'])->toBe([
                    ['functionResponse' => ['name' => 'lookup', 'response' => ['output' => 'one']]],
                    ['functionResponse' => ['name' => 'lookup', 'response' => ['output' => 'two']]],
                ])
                ->and($transport->requests()[$at + 1]->body)->not->toContain('call-0')
                ->and($transport->requests()[$at + 1]->body)->not->toContain('call-1');
        }
    })->with(['a whole answer' => [false], 'a stream' => [true]]);

    it('sends the id of a call back when the server gave one, with the call and with its result', function (): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered([GenerateContent::call()]))->willReturn(GenerateContent::answered());
        $gateway = GenerateContent::gateway($transport);
        $request = GenerateContent::request()->withTools([GenerateContent::lookup()]);

        $answer = $gateway->complete($request);
        $gateway->complete($request->withMessages([...$request->messages, $answer->toMessage(), Message::toolResult('fc-example01', 'lookup', 'one')]));
        $sent = GenerateContent::sent($transport, 1);

        expect($answer->toolCalls[0]->id)->toBe('fc-example01')
            ->and($sent['contents'][1]['parts'][0]['functionCall']['id'])->toBe('fc-example01')
            ->and($sent['contents'][2]['parts'][0]['functionResponse'])->toBe(['id' => 'fc-example01', 'name' => 'lookup', 'response' => ['output' => 'one']]);
    });

    it('sends the arguments of a call back as they arrived when they are the text of an object, and as an empty object when they could not be read', function (): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered());
        GenerateContent::gateway($transport)->complete(GenerateContent::request(
            Message::user('Where to?'),
            Message::assistant(
                '',
                ToolCall::decoded('fc-1', 'lookup', ['n' => 1.0, 'empty' => []], '{"n":1.0,"empty":{}}'),
                ToolCall::unreadable('fc-2', 'lookup', 'the arguments are not a JSON object', '{"city": "Par'),
                ToolCall::decoded('fc-3', 'lookup', ['city' => 'Rome'], 'not the text of an object'),
                ToolCall::decoded('fc-4', 'lookup', [], ''),
            ),
            Message::toolResult('fc-1', 'lookup', 'one'),
        ));
        $body = $transport->requests()[0]->body;

        expect($body)->toContain('{"id":"fc-1","name":"lookup","args":{"n":1.0,"empty":{}}}')
            ->toContain('{"id":"fc-2","name":"lookup","args":{}}')
            ->toContain('{"id":"fc-3","name":"lookup","args":{"city":"Rome"}}')
            ->toContain('{"id":"fc-4","name":"lookup","args":{}}');
    });

    it('does not send an argument text with a member name twice as it is: the arguments go out as they were decoded', function (): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered());
        GenerateContent::gateway($transport)->complete(GenerateContent::request(
            Message::user('Where to?'),
            Message::assistant(
                '',
                ToolCall::decoded('fc-1', 'lookup', ['a' => 2], '{"a":1,"a":2}'),
                ToolCall::decoded('fc-2', 'lookup', ['deep' => ['x' => 'last']], '{"deep":{"x":"first","x":"last"}}'),
                ToolCall::unreadable('fc-3', 'lookup', 'the arguments are not a JSON object', '{"a":1,"a":2}'),
                ToolCall::decoded('fc-4', 'lookup', [], '{"a":1,"a":2}'),
            ),
            Message::toolResult('fc-1', 'lookup', 'one'),
        ));
        $body = $transport->requests()[0]->body;

        expect($body)->toContain('{"id":"fc-1","name":"lookup","args":{"a":2}}')
            ->toContain('{"id":"fc-2","name":"lookup","args":{"deep":{"x":"last"}}}')
            ->toContain('{"id":"fc-3","name":"lookup","args":{}}')
            ->toContain('{"id":"fc-4","name":"lookup","args":{}}')
            ->and($body)->not->toContain('"a":1')
            ->and($body)->not->toContain('first');
    });

    it('refuses a request with a text that cannot be written as JSON, as rejected', function (): void {
        $transport = new FakeTransport();
        $request = GenerateContent::request(
            Message::user('Hi.'),
            Message::assistant('', new ToolCall('fc-1', 'lookup', ['city' => "\xB1\x31"], null, 'no object')),
            Message::toolResult('fc-1', 'lookup', 'one'),
        );
        $error = Talk::errorOf(fn() => GenerateContent::gateway($transport)->complete($request));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->detail)->toBe('the request holds a value that cannot be written as JSON')
            ->and($transport->requests())->toBe([]);
    });
});
