<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\Credentials\NoCredentials;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\TextPart;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// What the chat-completions adapter sends (docs/design/adapters.md, section 2.1): one address, one header of its
// own, and a body whose fields are a closed list.

/**
 * Requests of every kind the contract allows, by a few words that say what each holds.
 *
 * @return array<string, Request>
 */
function requestsOfEveryKind(): array
{
    $call = ToolCall::decoded('call_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
    $garbled = ToolCall::unreadable('call_2', 'lookup', 'the arguments are not a JSON object', '{"city": "Par');
    $tools = [ChatCompletions::lookup(), ChatCompletions::lookup('book')];
    $user = [Message::user('Where to?')];
    $round = [
        Message::system('You are a travel desk.'),
        Message::system('Answer in Dutch.'),
        Message::user('Where to?'),
        new Message(Role::Assistant, [new OpaquePart('{"f":"zz","m":"0","c":["of another gateway"]}'), new TextPart('One moment.')], [$call, $garbled]),
        Message::toolResult('call_1', 'lookup', '{"degrees":18}'),
        Message::toolResult('call_2', 'lookup', 'The arguments could not be read.'),
        Message::assistant('', $call),
        Message::toolResult('call_1', 'lookup', '{"degrees":19}'),
        Message::assistant('It is 19 degrees.'),
        Message::user('And tomorrow?'),
    ];

    return [
        'one user message' => new Request(ChatCompletions::MODEL, $user, 30.0),
        'a system message, a maximum and a temperature' => new Request(ChatCompletions::MODEL, [Message::system('Be brief.'), ...$user], 30.0, maxOutputTokens: 100, temperature: 0.0),
        'tools' => new Request(ChatCompletions::MODEL, $user, 30.0, $tools),
        'tools, none of them to be called' => new Request(ChatCompletions::MODEL, $user, 30.0, $tools, ToolChoice::none()),
        'tools, one of them to be called' => new Request(ChatCompletions::MODEL, $user, 30.0, $tools, ToolChoice::required()),
        'tools, a named one to be called' => new Request(ChatCompletions::MODEL, $user, 30.0, $tools, ToolChoice::tool('book')),
        'a response format' => new Request(ChatCompletions::MODEL, $user, 30.0, responseFormat: ChatCompletions::trip()),
        'tools and a response format' => new Request(ChatCompletions::MODEL, $user, 30.0, $tools, ToolChoice::required(), ChatCompletions::trip(), 100, 1.0),
        'no tools and a tool choice of none' => new Request(ChatCompletions::MODEL, $user, 30.0, [], ToolChoice::none()),
        'a conversation with tool calls and their results' => new Request(ChatCompletions::MODEL, $round, 30.0, $tools, maxOutputTokens: 5),
        'a conversation with tool calls, without tools now' => new Request(ChatCompletions::MODEL, $round, 30.0),
    ];
}

/**
 * Servers of every kind: the defaults, and option sets in which every option has a value other than its default.
 *
 * @return array<string, array<string, bool|int|string>>
 */
function serversOfEveryKind(): array
{
    return [
        'the defaults' => [],
        'every option at its second value' => [
            'max_tokens_field' => 'max_tokens', 'system_role' => 'developer', 'system_messages' => 'joined',
            'assistant_content_without_text' => 'empty_string', 'required_word' => 'any', 'named_tool_choice' => 'required_single',
            'tool_strict' => 'false', 'tool_result_name' => true, 'json_schema_strict' => 'false', 'strict_subset' => 'narrow',
            'json_schema_key' => 'schema_definition', 'stream_usage' => 'unasked', 'store_field' => 'false',
            'reasoning_carrier' => 'message_field', 'inline_reasoning' => 'strip', 'finish_content_filter' => 'unknown',
            'models_path' => '/instance/models', 'model_id_field' => 'technical_name',
        ],
        'the strict flag when the schema fits, and everything stored nowhere' => ['json_schema_strict' => 'when_fits', 'store_field' => 'false', 'tools_with_structured_output' => 'native'],
        'a server that can do little' => ['named_tool_choice' => 'unsupported', 'parallel_tool_calls' => 'none', 'structured_output' => 'emulated', 'streaming' => 'emulated', 'stream_usage' => 'unasked'],
        'a server without tools' => ['tool_calling' => 'none', 'structured_output' => 'emulated', 'streaming' => 'emulated'],
        'a server without tools and without structured answers' => ['tool_calling' => 'none', 'structured_output' => 'none', 'temperature' => 'not_accepted'],
    ];
}

/**
 * The body of every request the adapter sends for every kind of request to every kind of server, through
 * complete() and as a stream. A request the server cannot take is refused before anything is sent, and gives no body.
 *
 * @return array<string, array{array<string, mixed>, string}> what was asked => [the body, the body as it was sent]
 */
function everyBodySent(): array
{
    $bodies = [];
    foreach (serversOfEveryKind() as $server => $options) {
        foreach (requestsOfEveryKind() as $kind => $request) {
            foreach ([false, true] as $asStream) {
                $transport = new FakeTransport();
                if ($asStream && ($options['streaming'] ?? 'native') === 'native') {
                    $transport->willStream(200, [], ChatCompletions::chunk(['content' => 'Hello.'], 'stop'), ChatCompletions::DONE);
                } else {
                    $transport->willReturn(ChatCompletions::answered());
                }
                $gateway = ChatCompletions::gateway($transport, $options);

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

describe('the closed list of fields', function (): void {
    it('never sends a field outside the list, whatever the request and the options', function (): void {
        $bodies = everyBodySent();
        expect(count($bodies))->toBeGreaterThan(90);

        $seen = [];
        foreach ($bodies as $what => [$body]) {
            expect(array_values(array_diff(array_keys($body), ChatCompletions::FIELDS)))->toBe([], $what);
            $seen += array_flip(array_keys($body));

            // The two names of the maximum of output tokens never go together.
            expect(isset($body['max_tokens']) && isset($body['max_completion_tokens']))->toBeFalse($what);
        }

        // The matrix reached every field of the list: the rule above was tried on each of them.
        expect(array_values(array_diff(ChatCompletions::FIELDS, array_keys($seen))))->toBe([]);
    });

    it('never sends an empty or null tools, and never a tool choice without tools', function (): void {
        foreach (everyBodySent() as $what => [$body, $raw]) {
            if (array_key_exists('tools', $body)) {
                expect($body['tools'])->toBeArray($what)
                    ->and($body['tools'] === [])->toBeFalse($what . ' holds an empty tools');
            } else {
                expect(array_key_exists('tool_choice', $body))->toBeFalse($what . ' holds a tool choice without tools');
            }
            expect(str_contains($raw, '"tools":[]') || str_contains($raw, '"tools":null'))->toBeFalse($what . ' holds an empty or null tools');
        }
    });

    it('never sends parallel_tool_calls or another field no neutral request asks for, at any depth', function (): void {
        $never = [
            'parallel_tool_calls', '"n":', '"stop":', 'presence_penalty', 'frequency_penalty', 'seed', 'reasoning_effort', 'top_p', 'logit_bias',
            'logprobs', '"user":', 'metadata', 'service_tier', 'search_parameters', 'web_search_options', 'partial', 'prefix', 'ttl',
            'draft_model', 'guided_json', 'steering_concepts', 'safe_prompt', 'prompt_cache_key', 'safety_identifier', 'prediction',
            'thinking', 'functions', 'function_call', 'previous_response_id', 'deferred', '"store":true', 'include_obfuscation',
        ];

        foreach (everyBodySent() as $what => [, $raw]) {
            foreach ($never as $field) {
                expect(str_contains($raw, $field))->toBeFalse($what . ' holds ' . $field);
            }
        }
    });

    it('sends only the documented members of a message, a tool and a tool call', function (): void {
        foreach (everyBodySent() as $what => [$body]) {
            foreach ($body['messages'] as $message) {
                $allowed = match ($message['role']) {
                    'system', 'developer', 'user' => ['role', 'content'],
                    'assistant' => ['role', 'content', 'reasoning_content', 'tool_calls'],
                    'tool' => ['role', 'tool_call_id', 'name', 'content'],
                    default => [],
                };
                expect(array_values(array_diff(array_keys($message), $allowed)))->toBe([], $what);

                foreach ($message['tool_calls'] ?? [] as $call) {
                    expect(array_keys($call))->toBe(['id', 'type', 'function'], $what)
                        ->and(array_keys($call['function']))->toBe(['name', 'arguments'], $what)
                        ->and($call['function']['arguments'])->toBeString($what);
                }
            }
            foreach ($body['tools'] ?? [] as $tool) {
                expect(array_keys($tool))->toBe(['type', 'function'], $what)
                    ->and(array_values(array_diff(array_keys($tool['function']), ['name', 'description', 'parameters', 'strict'])))->toBe([], $what);
            }
            if (isset($body['response_format'])) {
                expect(array_keys($body['response_format']))->toBe(['type', 'json_schema'], $what)
                    ->and(array_values(array_diff(array_keys($body['response_format']['json_schema']), ['name', 'description', 'schema', 'schema_definition', 'strict'])))->toBe([], $what);
            }
            if (isset($body['stream_options'])) {
                expect($body['stream_options'])->toBe(['include_usage' => true], $what);
            }
        }
    });

    it('holds exactly these fields for a plain request, and exactly these for a full one', function (): void {
        $plain = ChatCompletions::bodyFor(ChatCompletions::request());
        expect($plain)->toBe(['model' => ChatCompletions::MODEL, 'messages' => [['role' => 'user', 'content' => 'Say hello.']]]);

        $full = new Request(ChatCompletions::MODEL, [Message::user('Where to?')], 30.0, [ChatCompletions::lookup()], ToolChoice::required(), ChatCompletions::trip(), 100, 0.5);
        $options = ['store_field' => 'false', 'tools_with_structured_output' => 'native'];

        expect(array_keys(ChatCompletions::bodyFor($full, $options, true)))
            ->toBe(['model', 'messages', 'tools', 'tool_choice', 'response_format', 'max_completion_tokens', 'temperature', 'stream', 'stream_options', 'store'])
            ->and(array_keys(ChatCompletions::bodyFor($full, $options)))
            ->toBe(['model', 'messages', 'tools', 'tool_choice', 'response_format', 'max_completion_tokens', 'temperature', 'store']);
    });
});

describe('the address and the headers', function (): void {
    it('posts to the chat path under the base URL, with one header of its own and the sign-in of the base', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered());
        ChatCompletions::gateway($transport)->complete(ChatCompletions::request());
        $sent = $transport->requests()[0];

        expect($sent->method)->toBe('POST')
            ->and($sent->url)->toBe('https://llm.example.test/v1/chat/completions')
            ->and($sent->headers)->toBe(['Content-Type' => 'application/json'])
            ->and(array_keys($sent->secretHeaders))->toBe(['Authorization']);
    });

    it('sends a stream to the same address', function (): void {
        $transport = (new FakeTransport())->willStream(200, [], ChatCompletions::chunk(['content' => 'Hello.'], 'stop'), ChatCompletions::DONE);
        Talk::events(ChatCompletions::gateway($transport)->stream(ChatCompletions::request()));

        expect($transport->requests()[0]->url)->toBe('https://llm.example.test/v1/chat/completions')
            ->and($transport->requests()[0]->headers)->toBe(['Content-Type' => 'application/json']);
    });

    it('uses the address of the customer exactly as given, with or without a version in it', function (string $baseUrl, string $address): void {
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered());
        $gateway = GatewayFactory::create(ChatCompletions::preset(), new NoCredentials(), $transport, new GatewayConfig(RetryPolicy::none(), $baseUrl));
        $gateway->complete(ChatCompletions::request());

        expect($transport->requests()[0]->url)->toBe($address)
            ->and($transport->requests()[0]->secretHeaders)->toBe([]);
    })->with([
        'with a version' => ['http://localhost:1234/v1', 'http://localhost:1234/v1/chat/completions'],
        'without one' => ['https://inference.example.test', 'https://inference.example.test/chat/completions'],
        'with a longer path and a slash at its end' => ['https://llm.example.test/api/v3/', 'https://llm.example.test/api/v3/chat/completions'],
    ]);

    it('sets no header with an underscore in its name, whatever the credentials', function (): void {
        foreach ([new BearerKey('key-of-the-test'), new HeaderKey('key-of-the-test'), new NoCredentials()] as $credentials) {
            $transport = (new FakeTransport())->willReturn(ChatCompletions::answered())->willReturn(new HttpResponse(200, [], '{"data":[]}'));
            $gateway = ChatCompletions::gateway($transport, credentials: $credentials, settings: ['project' => 'proj-1']);
            $gateway->complete(ChatCompletions::request());
            $gateway->models(10.0);

            foreach ($transport->requests() as $sent) {
                foreach (array_keys($sent->allHeaders()) as $name) {
                    expect($name)->not->toContain('_');
                }
            }
        }
    });

    it('sends a setting of the customer in the header its field names, beside its own header and without a clash', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(ChatCompletions::answered())
            ->willStream(200, [], ChatCompletions::chunk(['content' => 'Hello.'], 'stop'), ChatCompletions::DONE)
            ->willReturn(new HttpResponse(200, [], '{"data":[{"id":"example-chat-1"}]}'));
        $gateway = ChatCompletions::gateway($transport, settings: ['project' => 'proj-1']);

        expect($gateway->complete(ChatCompletions::request())->text)->toBe('Hello.')
            ->and(Talk::finishedIn(Talk::events($gateway->stream(ChatCompletions::request())))->answer->text)->toBe('Hello.')
            ->and($gateway->models(10.0))->toHaveCount(1);

        [$completion, $stream, $models] = $transport->requests();
        expect($completion->headers)->toBe(['Content-Type' => 'application/json', 'X-Example-Project' => 'proj-1'])
            ->and($stream->headers)->toBe(['Content-Type' => 'application/json', 'X-Example-Project' => 'proj-1'])
            ->and($models->headers)->toBe(['Accept' => 'application/json', 'X-Example-Project' => 'proj-1']);

        // Without the setting the header is not sent.
        $plain = (new FakeTransport())->willReturn(ChatCompletions::answered());
        ChatCompletions::gateway($plain)->complete(ChatCompletions::request());
        expect($plain->requests()[0]->headers)->toBe(['Content-Type' => 'application/json']);
    });

    it('sends neither the trace id of the call nor the id of the preset', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered());
        ChatCompletions::gateway($transport)->complete(ChatCompletions::request());
        $sent = $transport->requests()[0];

        $all = $sent->url . $sent->body . json_encode($sent->allHeaders());

        expect($all)->not->toContain('trace-1')
            ->and($all)->not->toContain('chat-completions-test');
    });
});

describe('the values of a request', function (): void {
    it('sends the model, the maximum and the temperature as they were given, also a temperature of 0', function (): void {
        $body = ChatCompletions::bodyFor(new Request('publisher/example-model:7b@q4', [Message::user('Hi.')], 30.0, maxOutputTokens: 1, temperature: 0.0));

        expect($body['model'])->toBe('publisher/example-model:7b@q4')
            ->and($body['max_completion_tokens'])->toBe(1)
            // A temperature of 0 is a value: it is sent, as the number it is.
            ->and(array_key_exists('temperature', $body))->toBeTrue()
            ->and($body['temperature'])->toEqual(0);
    });

    it('refuses a temperature for a model that takes none, and drops nothing', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered());
        $gateway = ChatCompletions::gateway($transport, ['temperature' => 'not_accepted']);

        $error = Talk::errorOf(fn() => $gateway->complete(new Request(ChatCompletions::MODEL, [Message::user('Hi.')], 30.0, temperature: 0.0)));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($transport->requests())->toBe([]);
    });

    it('sends a tool with its name, its description and its schema as a JSON object', function (): void {
        $tool = new ToolDefinition('now', '', JsonSchema::fromJson('{"type":"object","properties":{}}'));
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered());
        ChatCompletions::gateway($transport)->complete(new Request(ChatCompletions::MODEL, [Message::user('What time is it?')], 30.0, [$tool]));

        expect($transport->requests()[0]->body)->toContain('"tools":[{"type":"function","function":{"name":"now","description":"","parameters":{"type":"object","properties":{}}}}]');
    });

    it('refuses a tool name outside the rule of the format as rejected, before anything is sent', function (string $name): void {
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered());
        $gateway = ChatCompletions::gateway($transport);
        $request = new Request(ChatCompletions::MODEL, [Message::user('Hi.')], 30.0, [ChatCompletions::lookup(), ChatCompletions::lookup($name)]);

        $error = Talk::errorOf(fn() => $gateway->complete($request));
        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->attempts)->toBe(0)
            ->and($error->httpStatus)->toBeNull()
            ->and($error->getMessage())->not->toContain($name);

        expect(Talk::failedIn(Talk::events($gateway->stream($request)))->error->reason)->toBe(ErrorReason::Rejected)
            ->and($transport->requests())->toBe([]);
    })->with([
        'longer than 64' => [str_repeat('n', 65)],
        'a dot' => ['lookup.city'],
        'a colon' => ['lookup:city'],
        'a letter outside ASCII' => ['zoek_café'],
    ]);

    it('takes a tool name of 64 letters, digits, underscores and hyphens', function (): void {
        $name = 'Look_up-' . str_repeat('9', 56);
        $body = ChatCompletions::bodyFor(new Request(ChatCompletions::MODEL, [Message::user('Hi.')], 30.0, [ChatCompletions::lookup($name)]));

        expect($body['tools'][0]['function']['name'])->toBe($name);
    });

    it('sends the arguments of a tool call back as they arrived, also when they could not be read', function (): void {
        $messages = [
            Message::user('Where to?'),
            Message::assistant(
                '',
                ToolCall::unreadable('call_1', 'lookup', 'the arguments are not a JSON object', '{"city": "Par'),
                ToolCall::decoded('call_2', 'lookup', [], ''),
                ToolCall::decoded('call_3', 'lookup', ['city' => 'Rome'], ''),
            ),
            Message::toolResult('call_1', 'lookup', 'unreadable'),
            Message::toolResult('call_2', 'lookup', 'no city'),
            Message::toolResult('call_3', 'lookup', '{"degrees":21}'),
        ];
        $calls = ChatCompletions::bodyFor(new Request(ChatCompletions::MODEL, $messages, 30.0, [ChatCompletions::lookup()]))['messages'][1]['tool_calls'];

        expect(array_column(array_column($calls, 'function'), 'arguments'))->toBe(['{"city": "Par', '{}', '{"city":"Rome"}']);
    });

    it('sends a transcript that ends with an assistant message as it is, with nothing added to it', function (): void {
        $body = ChatCompletions::bodyFor(ChatCompletions::request(Message::user('Finish this.'), Message::assistant('Once upon')));

        expect($body['messages'][1])->toBe(['role' => 'assistant', 'content' => 'Once upon']);
    });
});

describe('a response format', function (): void {
    it('goes into the system text, after the system messages of the caller, where the server takes none', function (array $options): void {
        $request = ChatCompletions::request(Message::system('Be brief.'), Message::user('Plan a trip.'))->withResponseFormat(ChatCompletions::trip());
        $body = ChatCompletions::bodyFor($request, $options);

        expect($body)->not->toHaveKey('response_format')
            ->and($body['messages'])->toHaveCount(3)
            ->and($body['messages'][0])->toBe(['role' => 'system', 'content' => 'Be brief.'])
            ->and($body['messages'][1]['role'])->toBe('system')
            ->and($body['messages'][1]['content'])->toContain('JSON Schema:')->toContain('"days":{"type":"integer"}')->toContain('A trip to one city.')
            ->and($body['messages'][2])->toBe(['role' => 'user', 'content' => 'Plan a trip.']);
    })->with([
        'structured output emulated' => [['structured_output' => 'emulated']],
    ]);

    it('is the last of the one system message where the system messages are joined', function (): void {
        $request = ChatCompletions::request(Message::system('Be brief.'), Message::user('Plan a trip.'))->withResponseFormat(ChatCompletions::trip());
        $messages = ChatCompletions::bodyFor($request, ['structured_output' => 'emulated', 'system_messages' => 'joined', 'system_role' => 'developer'])['messages'];

        expect($messages)->toHaveCount(2)
            ->and($messages[0]['role'])->toBe('developer')
            ->and($messages[0]['content'])->toStartWith("Be brief.\n\nAnswer with exactly one JSON object");
    });

    it('goes into the system text beside tools, and the tools are sent, unless the server takes both', function (): void {
        $request = new Request(ChatCompletions::MODEL, [Message::user('Plan a trip.')], 30.0, [ChatCompletions::lookup()], responseFormat: ChatCompletions::trip());

        $emulated = ChatCompletions::bodyFor($request);
        expect($emulated)->not->toHaveKey('response_format')
            ->and($emulated['tools'])->toHaveCount(1)
            ->and($emulated['messages'][0]['role'])->toBe('system')
            ->and($emulated['messages'][0]['content'])->toContain('JSON Schema:');

        $native = ChatCompletions::bodyFor($request, ['tools_with_structured_output' => 'native']);
        expect($native['response_format']['type'])->toBe('json_schema')
            ->and($native['tools'])->toHaveCount(1)
            ->and($native['messages'])->toBe([['role' => 'user', 'content' => 'Plan a trip.']]);
    });

    it('is checked by the library whatever the route: an answer that does not match is returned with its reasons', function (array $options): void {
        $response = ChatCompletions::answered(['role' => 'assistant', 'content' => '{"city":"Paris","days":"three"}']);
        $answer = ChatCompletions::answerTo($response, $options, ChatCompletions::request()->withResponseFormat(ChatCompletions::trip()));

        expect($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->issues[0]->path)->toBe('/days');
    })->with([
        'native' => [[]],
        'native, with the strict flag' => [['json_schema_strict' => 'when_fits']],
        'emulated' => [['structured_output' => 'emulated']],
    ]);
});
