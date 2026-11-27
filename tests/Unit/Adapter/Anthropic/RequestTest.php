<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\TextPart;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// What the Messages adapter sends (docs/design/adapters.md, section 4.1): one address, two headers of its own, and
// a body whose fields are a closed list.

/**
 * Requests of every kind the contract allows, by a few words that say what each holds.
 *
 * @return array<string, Request>
 */
function messagesRequestsOfEveryKind(): array
{
    $call = ToolCall::decoded('toolu_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
    $garbled = ToolCall::unreadable('toolu_2', 'lookup', 'the arguments are not a JSON object', '{"city": "Par');
    $tools = [Messages::lookup(), Messages::lookup('book')];
    $user = [Message::user('Where to?')];
    $round = [
        Message::system('You are a travel desk.'),
        Message::system('Answer in Dutch.'),
        Message::user('Where to?'),
        new Message(Role::Assistant, [new OpaquePart('{"f":"zz","m":"0","c":["of another gateway"]}'), new TextPart('One moment.')], [$call, $garbled]),
        Message::toolResult('toolu_1', 'lookup', '{"degrees":18}'),
        Message::toolResult('toolu_2', 'lookup', 'The arguments could not be read.'),
        Message::assistant('', $call),
        Message::toolResult('toolu_1', 'lookup', '{"degrees":19}'),
        Message::assistant('It is 19 degrees.'),
        Message::user('And tomorrow?'),
    ];

    return [
        'one user message' => new Request(Messages::MODEL, $user, 30.0),
        'a system message, a maximum and a temperature' => new Request(Messages::MODEL, [Message::system('Be brief.'), ...$user], 30.0, maxOutputTokens: 100, temperature: 0.0),
        'tools' => new Request(Messages::MODEL, $user, 30.0, $tools),
        'tools, none of them to be called' => new Request(Messages::MODEL, $user, 30.0, $tools, ToolChoice::none()),
        'tools, one of them to be called' => new Request(Messages::MODEL, $user, 30.0, $tools, ToolChoice::required()),
        'tools, a named one to be called' => new Request(Messages::MODEL, $user, 30.0, $tools, ToolChoice::tool('book')),
        'a response format' => new Request(Messages::MODEL, $user, 30.0, responseFormat: Messages::trip()),
        'a response format the native mode cannot take' => new Request(Messages::MODEL, $user, 30.0, responseFormat: Messages::trip('{"type":"object","properties":{"city":{"type":"string"}}}')),
        'tools and a response format' => new Request(Messages::MODEL, $user, 30.0, $tools, ToolChoice::required(), Messages::trip(), 100, 1.0),
        'no tools and a tool choice of none' => new Request(Messages::MODEL, $user, 30.0, [], ToolChoice::none()),
        'a conversation with tool calls and their results' => new Request(Messages::MODEL, $round, 30.0, $tools, maxOutputTokens: 5),
        'a conversation with tool calls, without tools now' => new Request(Messages::MODEL, $round, 30.0),
    ];
}

/**
 * Servers of every kind: the least a preset sets, the options of the shipped entry, and servers that can do less.
 *
 * @return array<string, array<string, bool|int|string>>
 */
function messagesServersOfEveryKind(): array
{
    return [
        'the least a preset sets' => Messages::EARLIER_VERSION,
        'the options of the shipped entry' => Messages::CURRENT_VERSION,
        'a server that emulates much' => [...Messages::EARLIER_VERSION, 'structured_output' => 'emulated', 'tools_with_structured_output' => 'emulated', 'streaming' => 'emulated', 'forced_tool_call' => 'none', 'parallel_tool_calls' => 'none'],
        'a server that takes tools and a schema, but not together' => [...Messages::CURRENT_VERSION, 'tools_with_structured_output' => 'emulated', 'context_length' => 200000],
        'a server without tools and without structured answers' => [...Messages::EARLIER_VERSION, 'tool_calling' => 'none', 'structured_output' => 'none', 'model_listing' => 'none'],
    ];
}

/**
 * The body of every request the adapter sends for every kind of request to every kind of server, through
 * complete() and as a stream. A request the server cannot take is refused before anything is sent, and gives no body.
 *
 * @return array<string, array{array<string, mixed>, string}> what was asked => [the body, the body as it was sent]
 */
function everyMessagesBodySent(): array
{
    $bodies = [];
    foreach (messagesServersOfEveryKind() as $server => $options) {
        foreach (messagesRequestsOfEveryKind() as $kind => $request) {
            foreach ([false, true] as $asStream) {
                $transport = new FakeTransport();
                if ($asStream && ($options['streaming'] ?? 'native') === 'native') {
                    $transport->willStream(200, [], ...Messages::saying());
                } else {
                    $transport->willReturn(Messages::answered());
                }
                $gateway = Messages::gateway($transport, $options);

                try {
                    $asStream ? Talk::events($gateway->stream($request)) : $gateway->complete($request);
                } catch (GatewayException $refusal) {
                    expect($refusal->reason)->toBe(ErrorReason::NotSupported);
                }
                foreach ($transport->requests() as $sent) {
                    /** @var array<string, mixed> $body */
                    $body = json_decode($sent->body, true, 512, JSON_THROW_ON_ERROR);
                    $bodies[$server . ' / ' . $kind . ($asStream ? ' / as a stream' : '')] = [$body, $sent->body];
                }
            }
        }
    }

    return $bodies;
}

describe('the closed list of fields of the Messages adapter', function (): void {
    it('never sends a field outside the list, whatever the request and the options', function (): void {
        $bodies = everyMessagesBodySent();
        expect(count($bodies))->toBeGreaterThan(60);

        $seen = [];
        foreach ($bodies as $what => [$body]) {
            expect(array_values(array_diff(array_keys($body), Messages::FIELDS)))->toBe([], $what);
            $seen += array_flip(array_keys($body));

            // The three fields the format requires are in every request.
            expect(array_slice(array_keys($body), 0, 2))->toBe(['model', 'max_tokens'], $what)
                ->and($body['messages'])->toBeArray($what)
                ->and($body['max_tokens'])->toBeInt($what);
        }

        // The matrix reached every field of the list: the rule above was tried on each of them.
        expect(array_values(array_diff(Messages::FIELDS, array_keys($seen))))->toBe([]);
    });

    it('holds exactly these fields for a plain request, and exactly these for a full one', function (): void {
        $plain = Messages::bodyFor(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0));
        $full = Messages::bodyFor(
            new Request(Messages::MODEL, [Message::system('Be brief.'), Message::user('Hi.')], 30.0, [Messages::lookup()], ToolChoice::required(), Messages::trip(), 100, 0.5),
            stream: true,
        );

        expect(array_keys($plain))->toBe(['model', 'max_tokens', 'messages'])
            ->and(array_keys($full))->toBe(['model', 'max_tokens', 'system', 'messages', 'tools', 'tool_choice', 'output_config', 'temperature', 'stream'])
            ->and(array_keys($full['output_config']))->toBe(['format'])
            ->and(array_keys($full['output_config']['format']))->toBe(['type', 'schema'])
            ->and($full['output_config']['format']['type'])->toBe('json_schema')
            ->and($full['stream'])->toBeTrue();
    });

    it('never sends an empty or null tools, and never a tool choice without tools', function (): void {
        foreach (everyMessagesBodySent() as $what => [$body, $raw]) {
            if (array_key_exists('tools', $body)) {
                expect($body['tools'] === [])->toBeFalse($what . ' holds an empty tools');
            } else {
                expect(array_key_exists('tool_choice', $body))->toBeFalse($what . ' holds a tool choice without tools');
            }
            expect(str_contains($raw, '"tools":[]') || str_contains($raw, '"tools":null'))->toBeFalse($what);
        }
    });

    it('never sends a field no neutral request asks for, at any depth', function (): void {
        $never = [
            'inference_geo', 'metadata', 'user_id', 'service_tier', '"thinking":', 'stop_sequences', 'disable_parallel_tool_use', '"strict"',
            'top_p', 'top_k', 'output_format', 'effort', 'is_error', 'cache_control', 'citations', '"role":"system"', 'mcp_servers', 'container',
        ];

        foreach (everyMessagesBodySent() as $what => [, $raw]) {
            foreach ($never as $field) {
                expect(str_contains($raw, $field))->toBeFalse($what . ' holds ' . $field);
            }
        }
    });

    it('sends only the documented members of a message, a block, a tool and a tool choice', function (): void {
        foreach (everyMessagesBodySent() as $what => [$body]) {
            foreach ($body['messages'] as $message) {
                expect(array_keys($message))->toBe(['role', 'content'], $what)
                    ->and(in_array($message['role'], ['user', 'assistant'], true))->toBeTrue($what)
                    ->and($message['content'])->toBeArray($what);

                foreach ($message['content'] as $block) {
                    $members = match ($block['type']) {
                        'text' => ['type', 'text'],
                        'tool_use' => ['type', 'id', 'name', 'input'],
                        'tool_result' => ['type', 'tool_use_id', 'content'],
                        default => [],
                    };
                    expect(array_keys($block))->toBe($members, $what)
                        ->and(in_array($block['type'], $message['role'] === 'user' ? ['text', 'tool_result'] : ['text', 'tool_use'], true))->toBeTrue($what);
                }
            }
            foreach ($body['tools'] ?? [] as $tool) {
                expect(array_keys($tool))->toBe(['name', 'description', 'input_schema'], $what);
            }
            if (isset($body['tool_choice'])) {
                expect(array_keys($body['tool_choice']))->toBe($body['tool_choice']['type'] === 'tool' ? ['type', 'name'] : ['type'], $what);
            }
            if (isset($body['output_config'])) {
                expect(array_keys($body['output_config']))->toBe(['format'], $what)
                    ->and(array_keys($body['output_config']['format']))->toBe(['type', 'schema'], $what);
            }
        }
    });

    it('never ends a transcript with an assistant message, and never sends two neighbours of one role', function (): void {
        foreach (everyMessagesBodySent() as $what => [$body]) {
            $roles = array_column($body['messages'], 'role');
            expect($roles[count($roles) - 1])->toBe('user', $what);
            foreach ($roles as $at => $role) {
                expect($at > 0 && $roles[$at - 1] === $role)->toBeFalse($what . ' holds two neighbouring messages of the role ' . $role);
            }
        }
    });
});

describe('the address and the headers of the Messages adapter', function (): void {
    it('posts to the messages path under the base URL, with the version and the content type, and the key as a bearer', function (): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered());
        Messages::gateway($transport, Messages::CURRENT_VERSION, credentials: new BearerKey('key-of-the-test'))->complete(Messages::request());

        $sent = $transport->requests()[0];

        expect($sent->method)->toBe('POST')
            ->and($sent->url)->toBe('https://llm.example.test/v1/messages')
            ->and($sent->headers)->toBe(['anthropic-version' => '2026-10-21', 'content-type' => 'application/json'])
            ->and(array_keys($sent->secretHeaders))->toBe(['Authorization'])
            ->and($sent->secretHeaders['Authorization']->reveal())->toBe('Bearer key-of-the-test');
    });

    it('sends a key of the other kind in the header of the preset', function (): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered());
        Messages::gateway($transport, credentials: new HeaderKey('key-of-the-test'))->complete(Messages::request());

        $sent = $transport->requests()[0];

        expect(array_keys($sent->secretHeaders))->toBe(['x-api-key'])
            ->and($sent->secretHeaders['x-api-key']->reveal())->toBe('key-of-the-test')
            ->and($sent->headers)->toBe(['anthropic-version' => '2023-06-01', 'content-type' => 'application/json']);
    });

    it('sends a stream to the same address, with the same headers', function (): void {
        $transport = (new FakeTransport())->willStream(200, [], ...Messages::saying());
        Talk::events(Messages::gateway($transport)->stream(Messages::request()));

        expect($transport->requests()[0]->url)->toBe('https://llm.example.test/v1/messages')
            ->and($transport->requests()[0]->headers)->toBe(['anthropic-version' => '2023-06-01', 'content-type' => 'application/json']);
    });

    it('sends the version of the model the request names, where a model override sets another', function (): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered())->willReturn(Messages::answered());
        $gateway = Messages::gateway($transport, Messages::CURRENT_VERSION, overrides: [new OrganiseYou\LlmGateway\Preset\ModelOverride('example-older-*', ['api_version' => '2023-06-01', 'temperature' => 'accepted'])]);

        $gateway->complete(Messages::request());
        $gateway->complete(new Request('example-older-1', [Message::user('Hi.')], 30.0, temperature: 0.5));

        expect($transport->requests()[0]->headers['anthropic-version'])->toBe('2026-10-21')
            ->and($transport->requests()[1]->headers['anthropic-version'])->toBe('2023-06-01')
            ->and(Messages::sent($transport, 1)['temperature'])->toBe(0.5);
    });

    it('sends a setting of the customer in the header its field names, beside its own headers and without a clash', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(Messages::answered())
            ->willStream(200, [], ...Messages::saying())
            ->willReturn(new OrganiseYou\LlmGateway\Transport\HttpResponse(200, [], '{"data":[]}'));
        $gateway = Messages::gateway($transport, settings: ['workspace_id' => 'wrkspc_example01']);

        $gateway->complete(Messages::request());
        Talk::events($gateway->stream(Messages::request()));
        $gateway->models(10.0);

        foreach ($transport->requests() as $at => $sent) {
            expect($sent->headers['anthropic-workspace-id'] ?? null)->toBe('wrkspc_example01', 'request ' . $at)
                ->and($sent->headers['anthropic-version'] ?? null)->toBe('2023-06-01', 'request ' . $at)
                ->and(count($sent->headers))->toBe($at === 2 ? 2 : 3, 'request ' . $at);
        }
    });

    it('sends no workspace header when the customer filled none in', function (): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered());
        Messages::gateway($transport)->complete(Messages::request());

        expect(array_keys($transport->requests()[0]->headers))->toBe(['anthropic-version', 'content-type']);
    });

    it('sends neither the trace id of the call nor the id of the preset', function (): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered());
        Messages::gateway($transport)->complete(Messages::request());

        $sent = $transport->requests()[0];
        $all = $sent->url . "\n" . $sent->body . "\n" . json_encode($sent->headers);

        expect(str_contains($all, 'trace-1'))->toBeFalse()
            ->and(str_contains($all, 'messages-test'))->toBeFalse();
    });
});

describe('the maximum of output tokens', function (): void {
    it('sends a request without a maximum with the default of the preset, never an error to the caller', function (): void {
        $body = Messages::bodyFor(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0));
        $streamed = Messages::bodyFor(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0), ['api_version' => '2026-10-21', 'default_max_output_tokens' => 4096], stream: true);

        expect($body['max_tokens'])->toBe(16000)
            ->and($streamed['max_tokens'])->toBe(4096);
    });

    it('sends the maximum of the request when it gives one, whatever the preset says', function (): void {
        $body = Messages::bodyFor(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0, maxOutputTokens: 7));
        $above = Messages::bodyFor(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0, maxOutputTokens: 200000), ['api_version' => '2026-10-21', 'default_max_output_tokens' => 4096], stream: true);

        expect($body['max_tokens'])->toBe(7)
            ->and($above['max_tokens'])->toBe(200000);
    });

    it('takes the default of a model override for the models it fits', function (): void {
        $overrides = [new OrganiseYou\LlmGateway\Preset\ModelOverride('example-small-*', ['default_max_output_tokens' => 2000])];

        expect(Messages::bodyFor(new Request('example-small-1', [Message::user('Hi.')], 30.0), overrides: $overrides)['max_tokens'])->toBe(2000)
            ->and(Messages::bodyFor(new Request('example-large-1', [Message::user('Hi.')], 30.0), overrides: $overrides)['max_tokens'])->toBe(16000);
    });

    it('is asked of the preset before any call: a preset without a default is not_configured already when its capabilities are asked', function (): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered())->willStream(200, [], ...Messages::saying());
        $gateway = Messages::gateway($transport, ['api_version' => '2026-10-21']);

        $asked = Talk::errorOf(fn() => $gateway->capabilities(Messages::MODEL));
        $error = Talk::errorOf(fn() => $gateway->complete(Messages::request()));
        $failed = Talk::failedIn(Talk::events($gateway->stream(Messages::request())));

        expect($asked->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the preset does not set the option default_max_output_tokens, which this adapter needs')
            ->and($error->attempts)->toBe(0)
            ->and($failed->error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($transport->requests())->toBe([]);
    });
});

describe('a temperature', function (): void {
    it('is refused as not_supported where the capability says the model takes none, and nothing is sent', function (float $temperature): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered())->willStream(200, [], ...Messages::saying());
        $gateway = Messages::gateway($transport, Messages::CURRENT_VERSION);
        $request = new Request(Messages::MODEL, [Message::user('Hi.')], 30.0, temperature: $temperature, traceId: 'trace-1');

        $error = Talk::errorOf(fn() => $gateway->complete($request));
        $events = Talk::events($gateway->stream($request));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($error->detail)->toBe('this model takes no temperature')
            ->and($error->traceId)->toBe('trace-1')
            ->and($error->attempts)->toBe(0)
            ->and($events)->toHaveCount(1)
            ->and($events[0])->toBeInstanceOf(StreamFailed::class)
            ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::NotSupported)
            ->and($transport->requests())->toBe([])
            ->and($gateway->capabilities(Messages::MODEL)->temperature)->toBe(OrganiseYou\LlmGateway\Contract\Support::None);
    })->with([
        'a temperature of 0, which is a value too' => [0.0],
        'a temperature of 1' => [1.0],
    ]);

    it('is never in a request to a server that takes none: the same request without it is sent without the field', function (): void {
        $body = Messages::bodyFor(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0), Messages::CURRENT_VERSION);

        expect(array_key_exists('temperature', $body))->toBeFalse();
    });

    it('is sent as the same number where the capability says the model takes one, and dropped nowhere', function (): void {
        $body = Messages::bodyFor(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0, temperature: 0.25));
        $zero = Messages::bodyFor(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0, temperature: 0.0), stream: true);

        expect($body['temperature'])->toBe(0.25)
            ->and(array_key_exists('temperature', $zero))->toBeTrue()
            ->and((float) $zero['temperature'])->toBe(0.0);
    });
});

describe('the transcript', function (): void {
    it('refuses a transcript that ends with an assistant message as rejected, before anything is sent', function (Message ...$messages): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered())->willStream(200, [], ...Messages::saying());
        $gateway = Messages::gateway($transport);
        $request = Messages::request(...$messages);

        $error = Talk::errorOf(fn() => $gateway->complete($request));
        $events = Talk::events($gateway->stream($request));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->detail)->toBe('the transcript ends with an assistant message, which this format reads as the start of the answer')
            ->and($error->attempts)->toBe(0)
            ->and($error->httpStatus)->toBeNull()
            ->and($events)->toHaveCount(1)
            ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::Rejected)
            ->and($transport->requests())->toBe([]);
    })->with([
        'a prefill after a question' => [Message::user('Name a city.'), Message::assistant('The city is')],
        'an assistant message that asks for a tool, without its result' => [Message::user('Where to?'), Message::assistant('', ToolCall::decoded('toolu_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'))],
        'an assistant message alone' => [Message::assistant('Hello.')],
    ]);

    it('refuses a transcript of system messages only as rejected, before anything is sent: the format requires a message', function (Message ...$messages): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered())->willStream(200, [], ...Messages::saying());
        $gateway = Messages::gateway($transport);
        $request = Messages::request(...$messages);

        $error = Talk::errorOf(fn() => $gateway->complete($request));
        $events = Talk::events($gateway->stream($request));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->detail)->toBe('the transcript holds only system messages, and this format needs a message beside its system text')
            ->and($error->attempts)->toBe(0)
            ->and($events)->toHaveCount(1)
            ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::Rejected)
            ->and($transport->requests())->toBe([]);
    })->with([
        'one system message' => [Message::system('Be brief.')],
        'two system messages' => [Message::system('Be brief.'), Message::system('Answer in Dutch.')],
    ]);

    it('sends the system messages as one top-level text, joined by a blank line, and never as a message', function (): void {
        $body = Messages::bodyFor(Messages::request(Message::system('You are a travel desk.'), Message::system('Answer in Dutch.'), Message::user('Hi.')));

        expect($body['system'])->toBe("You are a travel desk.\n\nAnswer in Dutch.")
            ->and($body['messages'])->toBe([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi.']]]]);
    });

    it('leaves the system field out when the request has no system message', function (): void {
        expect(array_key_exists('system', Messages::bodyFor(Messages::request())))->toBeFalse();
    });

    it('sends the tool results of one round as one user message, a block per result, in the order of the transcript', function (): void {
        $paris = ToolCall::decoded('toolu_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $rome = ToolCall::decoded('toolu_2', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}');

        $body = Messages::bodyFor(Messages::request(
            Message::user('Where is it warmer?'),
            Message::assistant('I will look both up.', $paris, $rome),
            Message::toolResult('toolu_1', 'lookup', '{"degrees":18}'),
            Message::toolResult('toolu_2', 'lookup', 'Unknown city'),
        ));

        expect($body['messages'])->toHaveCount(3)
            ->and($body['messages'][1])->toBe(['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'I will look both up.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup', 'input' => ['city' => 'Paris']],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'lookup', 'input' => ['city' => 'Rome']],
            ]])
            ->and($body['messages'][2])->toBe(['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"degrees":18}'],
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'content' => 'Unknown city'],
            ]]);
    });

    it('puts the text of a user message that follows the tool results into the same message, after the result blocks', function (): void {
        $call = ToolCall::decoded('toolu_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');

        $after = Messages::bodyFor(Messages::request(Message::user('Where to?'), Message::assistant('', $call), Message::toolResult('toolu_1', 'lookup', '18'), Message::user('And in Rome?')));
        // A transcript that is out of order still goes out with the result blocks first.
        $before = Messages::bodyFor(Messages::request(Message::user('Where to?'), Message::assistant('', $call), Message::user('And in Rome?'), Message::toolResult('toolu_1', 'lookup', '18')));

        $expected = ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '18'],
            ['type' => 'text', 'text' => 'And in Rome?'],
        ]];

        expect($after['messages'])->toHaveCount(3)
            ->and($after['messages'][2])->toBe($expected)
            ->and($before['messages'])->toHaveCount(3)
            ->and($before['messages'][2])->toBe($expected);
    });

    it('makes one message of neighbouring messages of the same role', function (): void {
        $body = Messages::bodyFor(Messages::request(
            Message::user('One.'),
            Message::user('Two.'),
            Message::assistant('Three.'),
            Message::assistant('Four.'),
            Message::user('Five.'),
        ));

        expect($body['messages'])->toBe([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'One.'], ['type' => 'text', 'text' => 'Two.']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Three.'], ['type' => 'text', 'text' => 'Four.']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Five.']]],
        ]);
    });

    it('sends an assistant message that holds only tool calls without a text block', function (): void {
        $call = ToolCall::decoded('toolu_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $body = Messages::bodyFor(Messages::request(Message::user('Where to?'), Message::assistant('', $call), Message::toolResult('toolu_1', 'lookup', '18')));

        expect($body['messages'][1]['content'])->toBe([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup', 'input' => ['city' => 'Paris']]]);
    });

    it('sends the input of a tool call always as an object', function (ToolCall $call, string $written): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered());
        Messages::gateway($transport)->complete(Messages::request(Message::user('Where to?'), Message::assistant('', $call), Message::toolResult($call->id, 'lookup', '18')));

        expect($transport->requests()[0]->body)->toContain('{"type":"tool_use","id":"toolu_1","name":"lookup","input":' . $written . '}');
    })->with([
        'the arguments as they arrived, when they are the text of an object' => [ToolCall::decoded('toolu_1', 'lookup', ['city' => 'Paris'], '{"city": "Paris", "n": 1.0}'), '{"city": "Paris", "n": 1.0}'],
        'an empty object, which decoded is an empty list' => [ToolCall::decoded('toolu_1', 'lookup', [], '{}'), '{}'],
        'the decoded arguments of a call the caller built without their text' => [ToolCall::decoded('toolu_1', 'lookup', ['city' => 'Paris', 'days' => [1, 2]], ''), '{"city":"Paris","days":[1,2]}'],
        'no arguments at all' => [ToolCall::decoded('toolu_1', 'lookup', [], ''), '{}'],
        'arguments that could not be read: an empty object, the format has no other way' => [ToolCall::unreadable('toolu_1', 'lookup', 'the arguments are not a JSON object', '{"city": "Par'), '{}'],
        'arguments that arrived as a list' => [ToolCall::unreadable('toolu_1', 'lookup', 'the arguments are not a JSON object', '["Paris"]'), '{}'],
        'an object whose names are numbers, which a tool call cannot hold decoded' => [ToolCall::unreadable('toolu_1', 'lookup', 'the names of the arguments are the numbers of a list', '{"0":"Paris"}'), '{"0":"Paris"}'],
    ]);
});

describe('the tools of a request', function (): void {
    it('sends a tool with its name, its description and its schema as a JSON object, and no flag', function (): void {
        $body = Messages::bodyFor(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0, [Messages::lookup()]));

        expect($body['tools'])->toBe([[
            'name' => 'lookup',
            'description' => 'Looks up a city.',
            'input_schema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        ]])->and(array_key_exists('tool_choice', $body))->toBeFalse();
    });

    it('writes the tool choice as the format has it, and leaves "auto" out', function (ToolChoice $choice, ?array $written): void {
        $body = Messages::bodyFor(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0, [Messages::lookup(), Messages::lookup('book')], $choice));

        expect($body['tool_choice'] ?? null)->toBe($written);
    })->with([
        'auto' => [ToolChoice::auto(), null],
        'none' => [ToolChoice::none(), ['type' => 'none']],
        'some tool' => [ToolChoice::required(), ['type' => 'any']],
        'one named tool' => [ToolChoice::tool('book'), ['type' => 'tool', 'name' => 'book']],
    ]);

    it('refuses a tool name outside the rule of the format as rejected, before anything is sent', function (string $name): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered());
        $error = Talk::errorOf(fn() => Messages::gateway($transport)->complete(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0, [Messages::lookup($name)])));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->detail)->toBe('a tool name is not one this format carries: letters, digits, underscores and hyphens, at most 128')
            ->and($error->attempts)->toBe(0)
            ->and($transport->requests())->toBe([]);
    })->with([
        'a dot' => ['look.up'],
        'a colon' => ['look:up'],
        'a letter that is not ASCII' => ['zoek-café'],
        '129 letters' => [str_repeat('n', 129)],
        'a slash' => ['look/up'],
    ]);

    it('takes a tool name of 128 letters, digits, underscores and hyphens', function (): void {
        $name = str_repeat('aZ09_-', 21) . 'ab';
        $body = Messages::bodyFor(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0, [Messages::lookup($name)]));

        expect(strlen($name))->toBe(128)
            ->and($body['tools'][0]['name'])->toBe($name);
    });
});
