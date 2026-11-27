<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatV2;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// What the Chat v2 adapter sends (docs/design/adapters.md, section 4.3): one address for the whole answer and the
// stream, the sign-in as a bearer, the closed list of fields, and the transcript in the shape of the format.

describe('the address and the sign-in of a request of the Chat v2 adapter', function (): void {
    it('posts a completion and a stream to the same address under the base URL, and says in the body which of the two it asks for', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered())->willStream(200, [], ...ChatV2::saying());
        $gateway = ChatV2::gateway($transport);

        $gateway->complete(ChatV2::request());
        Talk::finishedIn(Talk::events($gateway->stream(ChatV2::request())));

        expect($transport->requests()[0]->method)->toBe('POST')
            ->and($transport->requests()[0]->url)->toBe('https://llm.example.test/v2/chat')
            ->and($transport->requests()[1]->url)->toBe('https://llm.example.test/v2/chat')
            ->and(ChatV2::sent($transport, 0)['stream'])->toBeFalse()
            ->and(ChatV2::sent($transport, 1)['stream'])->toBeTrue();
    });

    it('always writes `stream`, as false or as true, and never leaves it out', function (): void {
        expect(ChatV2::bodyFor(ChatV2::request()))->toBe(['model' => ChatV2::MODEL, 'messages' => [['role' => 'user', 'content' => 'Say hello.']], 'stream' => false])
            ->and(ChatV2::bodyFor(ChatV2::request(), stream: true))->toBe(['model' => ChatV2::MODEL, 'messages' => [['role' => 'user', 'content' => 'Say hello.']], 'stream' => true]);
    });

    it('sends the key as a bearer, and never in the address or the body', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(ChatV2::answered())
            ->willStream(200, [], ...ChatV2::saying())
            ->willReturn(new HttpResponse(200, [], '{"models":[]}'));
        $gateway = ChatV2::gateway($transport, credentials: new BearerKey('key-of-the-test'));

        $gateway->complete(ChatV2::request());
        Talk::finishedIn(Talk::events($gateway->stream(ChatV2::request())));
        $gateway->models(10.0);

        expect($transport->requests())->toHaveCount(3);
        foreach ($transport->requests() as $sent) {
            expect($sent->secretHeaders['Authorization']->reveal())->toBe('Bearer key-of-the-test')
                ->and(array_keys($sent->secretHeaders))->toBe(['Authorization'])
                ->and($sent->url)->not->toContain('key')
                ->and($sent->body)->not->toContain('key-of-the-test')
                ->and(implode("\n", $sent->headers))->not->toContain('key-of-the-test');
        }
    });

    it('signs every attempt with the token its supplier then gave', function (): void {
        $tokens = Talk::tokens();
        $transport = (new FakeTransport())->willReturn(ChatV2::failed(503))->willReturn(ChatV2::answered());
        $gateway = ChatV2::gateway($transport, retry: new OrganiseYou\LlmGateway\Contract\RetryPolicy([1.0], 60.0), credentials: new BearerToken($tokens));

        $gateway->complete(ChatV2::request());

        expect($transport->requests()[0]->secretHeaders['Authorization']->reveal())->toBe('Bearer token-1')
            ->and($transport->requests()[1]->secretHeaders['Authorization']->reveal())->toBe('Bearer token-2');
    });

    it('carries a setting of the preset as a header of its own beside the header of the adapter, and the two do not clash', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered())->willStream(200, [], ...ChatV2::saying())->willReturn(new HttpResponse(200, [], '{"models":[]}'));
        $gateway = ChatV2::gateway($transport, settings: ['workspace' => 'example-host']);

        $gateway->complete(ChatV2::request());
        Talk::finishedIn(Talk::events($gateway->stream(ChatV2::request())));
        $gateway->models(10.0);

        expect($transport->requests()[0]->headers)->toBe(['content-type' => 'application/json', 'X-Example-Workspace' => 'example-host'])
            ->and($transport->requests()[1]->headers)->toBe(['content-type' => 'application/json', 'X-Example-Workspace' => 'example-host'])
            ->and($transport->requests()[2]->headers)->toBe(['X-Example-Workspace' => 'example-host'])
            ->and(array_keys($transport->requests()[0]->secretHeaders))->toBe(['Authorization']);
    });

    it('sends only the content type of its own when the setting is not filled in, and no header that names the caller', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered());
        ChatV2::gateway($transport)->complete(ChatV2::request());

        expect($transport->requests()[0]->headers)->toBe(['content-type' => 'application/json'])
            ->and(array_map('strtolower', array_keys($transport->requests()[0]->headers)))->not->toContain('x-client-name');
    });

    it('sends a model name as it is in the body, also one with a slash or a colon: the address does not hold it', function (string $model): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered());
        ChatV2::gateway($transport)->complete(new Request($model, [Message::user('Hi.')], 30.0));

        expect(ChatV2::sent($transport)['model'])->toBe($model)
            ->and($transport->requests()[0]->url)->toBe('https://llm.example.test/v2/chat');
    })->with(['example-chat-a-03-2025', 'vault/example:latest', '../v1/models?x=1#y']);
});

describe('the fields of a request of the Chat v2 adapter', function (): void {
    it('never sends a field outside its closed list, also for a request that uses everything', function (array $options, bool $stream, bool $withFormat): void {
        $request = new Request(
            ChatV2::MODEL,
            [
                Message::system('You are a travel desk.'),
                Message::user('Where to?'),
                Message::assistant('', ToolCall::decoded('lookup_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')),
                Message::toolResult('lookup_1', 'lookup', '{"degrees":18}'),
            ],
            30.0,
            [ChatV2::lookup()],
            ToolChoice::required(),
            $withFormat ? ChatV2::trip() : null,
            500,
            0.5,
            'trace-1',
        );
        $body = ChatV2::bodyFor($request, $options, $stream);
        $written = ChatV2::json($body);

        expect(array_diff(array_keys($body), ChatV2::FIELDS))->toBe([])
            ->and(array_keys($body))->toBe($withFormat && $options !== []
                ? ['model', 'messages', 'tools', 'tool_choice', 'response_format', 'max_tokens', 'temperature', 'stream']
                : ['model', 'messages', 'tools', 'tool_choice', 'max_tokens', 'temperature', 'stream'])
            ->and(array_keys($body['tools'][0]))->toBe(['type', 'function'])
            ->and(array_keys($body['tools'][0]['function']))->toBe(['name', 'description', 'parameters']);
        // Every message and every call with exactly its members: the system text of the emulated route is a second
        // system message.
        $emulated = $withFormat && $options === [];
        expect(array_map(fn(array $message): array => array_keys($message), $body['messages']))->toBe([
            ['role', 'content'],
            ...($emulated ? [['role', 'content']] : []),
            ['role', 'content'],
            ['role', 'tool_calls'],
            ['role', 'tool_call_id', 'content'],
        ])
            ->and(array_keys($body['messages'][$emulated ? 3 : 2]['tool_calls'][0]))->toBe(['id', 'type', 'function'])
            ->and(array_keys($body['messages'][$emulated ? 3 : 2]['tool_calls'][0]['function']))->toBe(['name', 'arguments']);
        if ($withFormat && $options !== []) {
            expect(array_keys($body['response_format']))->toBe(['type', $options['schema_key'] ?? 'json_schema']);
        }
        foreach (['safety_mode', 'documents', 'citation_options', 'thinking', 'priority', 'strict_tools', 'stop_sequences', 'seed', 'logprobs', 'frequency_penalty', 'presence_penalty', '"k"', '"p"', 'preamble', 'trace-1', 'store', 'strict'] as $never) {
            expect($written)->not->toContain($never);
        }
    })->with([
        'the defaults' => [[], false, true],
        'the defaults, as a stream' => [[], true, true],
        'the defaults, without a response format' => [[], false, false],
        'a server that takes tools beside a response format' => [['tools_with_structured_output' => 'native'], false, true],
        'the same, as a stream and with the other schema key' => [['tools_with_structured_output' => 'native', 'schema_key' => 'schema'], true, true],
    ]);

    it('writes the maximum of output tokens and the temperature under the names of the format, and leaves them out when the request gives none', function (): void {
        $request = new Request(ChatV2::MODEL, [Message::user('Hi.')], 30.0, maxOutputTokens: 200, temperature: 0.0);
        $body = ChatV2::bodyFor($request);

        expect($body['max_tokens'])->toBe(200)
            ->and($body['temperature'])->toBe(0)
            ->and(ChatV2::bodyFor(ChatV2::request()))->not->toHaveKey('max_tokens')
            ->and(ChatV2::bodyFor(ChatV2::request()))->not->toHaveKey('temperature');
    });

    it('sends every system message as a message of its role, first and each on its own', function (): void {
        $body = ChatV2::bodyFor(ChatV2::request(Message::system('Be brief.'), Message::system('Be kind.'), Message::user('Hi.')));

        expect($body['messages'])->toBe([
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'system', 'content' => 'Be kind.'],
            ['role' => 'user', 'content' => 'Hi.'],
        ]);
    });

    it('sends a transcript of system messages alone, and one that ends with an assistant message, as they are', function (): void {
        expect(ChatV2::bodyFor(ChatV2::request(Message::system('Hi.')))['messages'])->toBe([['role' => 'system', 'content' => 'Hi.']])
            ->and(ChatV2::bodyFor(ChatV2::request(Message::user('Hi.'), Message::assistant('Hel')))['messages'])->toBe([
                ['role' => 'user', 'content' => 'Hi.'],
                ['role' => 'assistant', 'content' => 'Hel'],
            ]);
    });
});

describe('the tools of a request of the Chat v2 adapter', function (): void {
    it('declares every tool as a function with its name, its description and its schema', function (): void {
        $request = new Request(ChatV2::MODEL, [Message::user('Hi.')], 30.0, [ChatV2::lookup(), new ToolDefinition('no-rule.for:names', '', OrganiseYou\LlmGateway\Contract\JsonSchema::fromJson('{"type":"object","properties":{}}'))]);
        $transport = (new FakeTransport())->willReturn(ChatV2::answered());
        ChatV2::gateway($transport)->complete($request);
        $body = ChatV2::sent($transport);

        expect($body['tools'][0])->toBe(['type' => 'function', 'function' => ['name' => 'lookup', 'description' => 'Looks up a city.', 'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']]]])
            ->and($body['tools'][1]['function']['name'])->toBe('no-rule.for:names')
            // An empty object stays an object.
            ->and($transport->requests()[0]->body)->toContain('"parameters":{"type":"object","properties":{}}')
            ->and($body)->not->toHaveKey('tool_choice');
    });

    it('leaves the tools and the tool choice out of a request without tools, and never sends an empty list of tools', function (): void {
        $body = ChatV2::bodyFor(ChatV2::request());

        expect($body)->not->toHaveKey('tools')
            ->and($body)->not->toHaveKey('tool_choice');
    });

    it('writes the tool choice as one of the two words of the format, and nothing for "auto"', function (ToolChoice $choice, ?string $word): void {
        $body = ChatV2::bodyFor(new Request(ChatV2::MODEL, [Message::user('Hi.')], 30.0, [ChatV2::lookup(), ChatV2::lookup('book')], $choice));

        expect($body['tool_choice'] ?? null)->toBe($word)
            ->and(array_column(array_column($body['tools'], 'function'), 'name'))->toBe(['lookup', 'book']);
    })->with([
        'auto' => [ToolChoice::auto(), null],
        'none' => [ToolChoice::none(), 'NONE'],
        'required' => [ToolChoice::required(), 'REQUIRED'],
    ]);

    it('forces one named tool by sending only that tool, with the word that says a tool must be called', function (bool $stream): void {
        $request = new Request(ChatV2::MODEL, [Message::user('Hi.')], 30.0, [ChatV2::lookup(), ChatV2::lookup('book'), ChatV2::lookup('cancel')], ToolChoice::tool('book'));
        $body = ChatV2::bodyFor($request, stream: $stream);

        expect($body['tool_choice'])->toBe('REQUIRED')
            ->and($body['tools'])->toHaveCount(1)
            ->and($body['tools'][0]['function']['name'])->toBe('book')
            // The format has no place for a name: it stands nowhere but in the one tool.
            ->and(substr_count(ChatV2::json($body), '"book"'))->toBe(1);
    })->with([[false], [true]]);

    it('forces a named tool the same way whatever the preset says of a forced call or of parallel calls: nothing more is sent', function (): void {
        $request = new Request(ChatV2::MODEL, [Message::user('Hi.')], 30.0, [ChatV2::lookup(), ChatV2::lookup('book')], ToolChoice::tool('lookup'));

        expect(ChatV2::bodyFor($request, ['forced_tool_call' => 'native', 'parallel_tool_calls' => 'none']))->toBe(ChatV2::bodyFor($request));
    });

    it('refuses one named tool before anything is sent only where the preset says the model cannot be forced', function (): void {
        $transport = new FakeTransport();
        $request = new Request(ChatV2::MODEL, [Message::user('Hi.')], 30.0, [ChatV2::lookup()], ToolChoice::tool('lookup'));
        $error = Talk::errorOf(fn() => ChatV2::gateway($transport, ['forced_tool_call' => 'none'])->complete($request));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($transport->requests())->toBe([]);
    });
});

describe('the transcript of a request of the Chat v2 adapter', function (): void {
    it('sends the assistant message of a tool round with its calls, and one tool message per result, bound by the id of the call', function (): void {
        $body = ChatV2::bodyFor(ChatV2::request(
            Message::user('Where to?'),
            Message::assistant(
                '',
                ToolCall::decoded('lookup_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'),
                ToolCall::decoded('lookup_2', 'lookup', ['city' => 'Rome'], '{"city": "Rome"}'),
            ),
            Message::toolResult('lookup_1', 'lookup', '{"degrees":18}'),
            Message::toolResult('lookup_2', 'lookup', 'It rains.'),
            Message::assistant('Paris, then.'),
        ));

        expect($body['messages'])->toBe([
            ['role' => 'user', 'content' => 'Where to?'],
            ['role' => 'assistant', 'tool_calls' => [
                ['id' => 'lookup_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']],
                ['id' => 'lookup_2', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city": "Rome"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'lookup_1', 'content' => '{"degrees":18}'],
            ['role' => 'tool', 'tool_call_id' => 'lookup_2', 'content' => 'It rains.'],
            ['role' => 'assistant', 'content' => 'Paris, then.'],
        ]);
    });

    it('sends the text of an assistant message beside its calls, an empty text for a message that holds nothing, and no plan with a message built by hand', function (): void {
        $body = ChatV2::bodyFor(ChatV2::request(
            Message::user('Hi.'),
            Message::assistant('Let me look.', ToolCall::decoded('lookup_1', 'lookup', [], '{}')),
            Message::toolResult('lookup_1', 'lookup', 'one'),
            Message::user('And?'),
            Message::assistant(''),
        ));

        expect($body['messages'][1])->toBe(['role' => 'assistant', 'content' => 'Let me look.', 'tool_calls' => [['id' => 'lookup_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{}']]]])
            ->and($body['messages'][4])->toBe(['role' => 'assistant', 'content' => ''])
            ->and(ChatV2::json($body))->not->toContain('tool_plan');
    });

    it('sends the arguments of a call back as the text that arrived, also when it could not be read, and the decoded arguments for a call without a text', function (): void {
        $body = ChatV2::bodyFor(ChatV2::request(
            Message::user('Where to?'),
            Message::assistant(
                '',
                ToolCall::unreadable('lookup_1', 'lookup', 'the arguments are not a JSON object', '{"city": "Par'),
                ToolCall::decoded('lookup_2', 'lookup', ['n' => 1], '{ "n" : 1.0 }'),
                ToolCall::decoded('lookup_3', 'lookup', ['city' => 'Rome'], ''),
                ToolCall::decoded('lookup_4', 'lookup', [], ''),
                ToolCall::unreadable('lookup_5', 'lookup', 'the arguments are not a JSON object', ''),
            ),
        ));

        expect(array_column(array_column($body['messages'][1]['tool_calls'], 'function'), 'arguments'))->toBe([
            '{"city": "Par', '{ "n" : 1.0 }', '{"city":"Rome"}', '{}', '{}',
        ]);
    });

    it('does not send an argument text with a member name twice as it is: the arguments go out as they were decoded', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered());
        ChatV2::gateway($transport)->complete(ChatV2::request(
            Message::user('Where to?'),
            Message::assistant(
                '',
                ToolCall::decoded('lookup_1', 'lookup', ['a' => 2], '{"a":1,"a":2}'),
                ToolCall::decoded('lookup_2', 'lookup', ['deep' => ['x' => 'last']], '{"deep":{"x":"first","x":"last"}}'),
                ToolCall::unreadable('lookup_3', 'lookup', 'the arguments are not a JSON object', '[{"a":1,"a":2}]'),
                ToolCall::decoded('lookup_4', 'lookup', [], '{"a":1,"a":2}'),
            ),
            Message::toolResult('lookup_1', 'lookup', 'one'),
        ));
        $calls = ChatV2::sent($transport)['messages'][1]['tool_calls'];

        expect(array_column(array_column($calls, 'function'), 'arguments'))->toBe(['{"a":2}', '{"deep":{"x":"last"}}', '{}', '{}'])
            ->and($transport->requests()[0]->body)->not->toContain('first');
    });

    it('refuses a request with a text that cannot be written as JSON, as rejected', function (): void {
        $transport = new FakeTransport();
        $request = ChatV2::request(
            Message::user('Hi.'),
            Message::assistant('', new ToolCall('lookup_1', 'lookup', ['city' => "\xB1\x31"], null, '')),
            Message::toolResult('lookup_1', 'lookup', 'one'),
        );
        $error = Talk::errorOf(fn() => ChatV2::gateway($transport)->complete($request));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->detail)->toBe('the request holds a value that cannot be written as JSON')
            ->and($transport->requests())->toBe([]);
    });
});
