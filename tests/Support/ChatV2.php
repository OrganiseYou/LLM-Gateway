<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Adapter\Cohere\ChatV2Adapter;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\ModelOverride;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Preset\PresetField;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Transport\AttemptObserver;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\NullAttemptObserver;
use SensitiveParameter;

/**
 * Builds what the tests of the Chat v2 adapter need: a preset on a made-up address, a gateway on it, requests, and
 * the bodies and events a server of the format gives. Everything here is made up.
 */
final class ChatV2
{
    /** The base URL carries nothing of a path: the chat is under `/v2`, the model list under `/v1`. */
    public const string BASE_URL = 'https://llm.example.test';

    public const string MODEL = 'example-chat-1';

    /** Every top-level field the adapter may ever send. */
    public const array FIELDS = ['model', 'messages', 'tools', 'tool_choice', 'response_format', 'max_tokens', 'temperature', 'stream'];

    /** The usage every body built here reports: 3 input and 2 output tokens, beside billing figures that differ. */
    public const array USAGE = ['billed_units' => ['input_tokens' => 1, 'output_tokens' => 1], 'tokens' => ['input_tokens' => 3, 'output_tokens' => 2]];

    /**
     * @param array<string, bool|int|string> $options
     * @param list<ModelOverride> $overrides
     */
    public static function preset(array $options = [], array $overrides = []): Preset
    {
        return new Preset(
            id: 'chat-v2-test',
            label: 'Chat v2 (test)',
            description: 'A made-up server for the tests.',
            adapter: ChatV2Adapter::class,
            baseUrlRule: BaseUrlRule::CustomerMay,
            defaultBaseUrl: self::BASE_URL,
            credentialKinds: [CredentialKind::BearerKey, CredentialKind::BearerToken, CredentialKind::None],
            keyHeader: null,
            fields: [new PresetField('workspace', 'Workspace', false, '[a-z][a-z0-9-]+', 'X-Example-Workspace')],
            dataLocation: 'not stated by the provider',
            dataLocationSource: null,
            sendsNoStoreOption: false,
            adapterOptions: $options,
            modelOverrides: $overrides,
        );
    }

    /**
     * @param array<string, bool|int|string> $options
     * @param array<string, string> $settings
     * @param list<ModelOverride> $overrides
     */
    public static function gateway(
        FakeTransport $transport,
        array $options = [],
        ?RetryPolicy $retry = null,
        ?FakeClock $clock = null,
        #[SensitiveParameter]
        ?Credentials $credentials = null,
        ?AttemptObserver $observer = null,
        array $settings = [],
        array $overrides = [],
    ): Gateway {
        return GatewayFactory::create(
            self::preset($options, $overrides),
            $credentials ?? new BearerKey('key-of-the-test'),
            $transport,
            new GatewayConfig(
                retry: $retry ?? RetryPolicy::none(),
                settings: $settings,
                observer: $observer ?? new NullAttemptObserver(),
                clock: $clock ?? new FakeClock(),
            ),
        );
    }

    public static function request(Message ...$messages): Request
    {
        return new Request(self::MODEL, $messages === [] ? [Message::user('Say hello.')] : array_values($messages), 30.0, traceId: 'trace-1');
    }

    public static function lookup(string $name = 'lookup'): ToolDefinition
    {
        return new ToolDefinition($name, 'Looks up a city.', JsonSchema::fromJson('{"type":"object","properties":{"city":{"type":"string"}},"required":["city"]}'));
    }

    public static function trip(?string $schema = null): ResponseFormat
    {
        return new ResponseFormat(
            JsonSchema::fromJson($schema ?? '{"type":"object","properties":{"city":{"type":"string"},"days":{"type":"integer"}},"required":["city","days"],"additionalProperties":false}'),
            'trip',
            'A trip to one city.',
        );
    }

    /**
     * A text block of the format.
     *
     * @return array<string, string>
     */
    public static function text(string $text = 'Hello.'): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * A thinking block of the format.
     *
     * @return array<string, string>
     */
    public static function thinking(string $thinking = 'Let me think.'): array
    {
        return ['type' => 'thinking', 'thinking' => $thinking];
    }

    /**
     * A tool call of the format, as an entry of `tool_calls`: the arguments are a string that holds JSON.
     *
     * @param mixed $arguments a string is sent as it is; anything else stands where the string should
     * @return array<string, mixed>
     */
    public static function call(mixed $arguments = '{"city":"Paris"}', ?string $id = 'lookup_example01', ?string $name = 'lookup'): array
    {
        $function = ['arguments' => $arguments];
        if ($name !== null) {
            $function = ['name' => $name, ...$function];
        }
        $call = ['type' => 'function', 'function' => $function];

        return $id === null ? $call : ['id' => $id, ...$call];
    }

    /**
     * The body of a response.
     *
     * @param list<mixed>|string|null $content the content of the message; null: one text block that says "Hello."
     * @param list<mixed> $calls the tool calls of the message
     * @param string|null $finish the finish reason, or null for none; "auto": the word that fits the calls
     * @param string|null $plan the tool plan, or null for none
     * @param array<string, mixed> $more top-level fields beside the usual ones, or in place of them
     * @return array<string, mixed>
     */
    public static function body(array|string|null $content = null, array $calls = [], ?string $finish = 'auto', ?string $plan = null, array $more = []): array
    {
        $message = ['role' => 'assistant'];
        if ($plan !== null) {
            $message['tool_plan'] = $plan;
        }
        if ($content !== null || $calls === []) {
            $message['content'] = $content ?? [self::text()];
        }
        if ($calls !== []) {
            $message['tool_calls'] = $calls;
        }

        $body = ['id' => 'synthetic-answer-1'];
        if ($finish !== null) {
            $body['finish_reason'] = $finish === 'auto' ? ($calls === [] ? 'COMPLETE' : 'TOOL_CALL') : $finish;
        }

        return [...$body, 'message' => $message, 'usage' => self::USAGE, ...$more];
    }

    /**
     * A successful answer of the format.
     *
     * @param list<mixed>|string|null $content
     * @param list<mixed> $calls
     * @param array<string, mixed> $more
     */
    public static function answered(array|string|null $content = null, array $calls = [], ?string $finish = 'auto', ?string $plan = null, array $more = []): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], self::json(self::body($content, $calls, $finish, $plan, $more)));
    }

    /**
     * A failed response of the format: the documented error body.
     *
     * @param array<string, string> $headers
     */
    public static function failed(int $status, string $message = 'No.', array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers, self::errorBody($message));
    }

    public static function errorBody(string $message = 'No.'): string
    {
        return self::json(['id' => 'synthetic-error-1', 'message' => $message]);
    }

    /**
     * One event of a stream of the format, as the bytes a server sends: a line with the type, and a data line whose
     * object repeats the type.
     *
     * @param array<string, mixed> $data
     */
    public static function event(string $type, array $data = []): string
    {
        return 'event: ' . $type . "\ndata: " . self::json(['type' => $type, ...$data]) . "\n\n";
    }

    public static function started(): string
    {
        return self::event('message-start', ['id' => 'synthetic-answer-1', 'delta' => ['message' => ['role' => 'assistant']]]);
    }

    /**
     * The start of a content block: its kind, with an empty text.
     */
    public static function blockStart(int $index = 0, string $kind = 'text', string $with = ''): string
    {
        return self::event('content-start', ['index' => $index, 'delta' => ['message' => ['content' => ['type' => $kind, $kind => $with]]]]);
    }

    /**
     * A piece of a content block: of its text, or of its thinking.
     */
    public static function piece(string $piece, int $index = 0, string $kind = 'text'): string
    {
        return self::event('content-delta', ['index' => $index, 'delta' => ['message' => ['content' => [$kind => $piece]]]]);
    }

    public static function blockEnd(int $index = 0): string
    {
        return self::event('content-end', ['index' => $index]);
    }

    public static function planPiece(string $piece): string
    {
        return self::event('tool-plan-delta', ['delta' => ['message' => ['tool_plan' => $piece]]]);
    }

    /**
     * The start of a tool call: one object, not a list, with its id, its name and empty arguments.
     */
    public static function callStart(int $index = 0, ?string $id = 'lookup_example01', ?string $name = 'lookup', string $arguments = ''): string
    {
        return self::event('tool-call-start', ['index' => $index, 'delta' => ['message' => ['tool_calls' => self::call($arguments, $id, $name)]]]);
    }

    public static function callPiece(string $piece, int $index = 0): string
    {
        return self::event('tool-call-delta', ['index' => $index, 'delta' => ['message' => ['tool_calls' => ['function' => ['arguments' => $piece]]]]]);
    }

    public static function callEnd(int $index = 0): string
    {
        return self::event('tool-call-end', ['index' => $index]);
    }

    /**
     * A whole tool call of a stream: its start, its arguments in these pieces, and its end.
     *
     * @param list<string> $pieces
     * @return list<string>
     */
    public static function calling(int $index = 0, ?string $id = 'lookup_example01', array $pieces = ['{"city":', '"Paris"}'], ?string $name = 'lookup'): array
    {
        return [self::callStart($index, $id, $name), ...array_map(fn(string $piece): string => self::callPiece($piece, $index), $pieces), self::callEnd($index)];
    }

    /**
     * The last event of a stream: the finish reason and the usage.
     *
     * @param array<string, mixed> $more members of `delta` beside the usual ones, or in place of them
     */
    public static function ended(?string $finish = 'COMPLETE', array $more = []): string
    {
        $delta = $finish === null ? [] : ['finish_reason' => $finish];

        return self::event('message-end', ['id' => 'synthetic-answer-1', 'delta' => [...$delta, 'usage' => self::USAGE, ...$more]]);
    }

    /**
     * A whole stream that says this text, each piece in an event of its own.
     *
     * @return list<string>
     */
    public static function saying(string ...$pieces): array
    {
        $pieces = $pieces === [] ? ['Hel', 'lo.'] : array_values($pieces);

        return [self::started(), self::blockStart(), ...array_map(fn(string $piece): string => self::piece($piece), $pieces), self::blockEnd(), self::ended()];
    }

    /**
     * The body of the request the transport received, decoded.
     *
     * @return array<string, mixed>
     */
    public static function sent(FakeTransport $transport, int $at = 0): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($transport->requests()[$at]->body, true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }

    /**
     * The body the adapter sends for a request to a server with these options: the request goes to a transport
     * that answers "Hello.", through complete() or as a stream, and what the transport received is given.
     *
     * @param array<string, bool|int|string> $options
     * @param list<ModelOverride> $overrides
     * @return array<string, mixed>
     */
    public static function bodyFor(Request $request, array $options = [], bool $stream = false, array $overrides = []): array
    {
        $transport = new FakeTransport();
        $gateway = self::gateway($transport, $options, overrides: $overrides);

        if ($stream) {
            $transport->willStream(200, [], ...self::saying());
            Talk::finishedIn(Talk::events($gateway->stream($request)));
        } else {
            $transport->willReturn(self::answered());
            $gateway->complete($request);
        }

        return self::sent($transport);
    }

    /**
     * The answer of a server with these options to the plain request, given this response.
     *
     * @param array<string, bool|int|string> $options
     */
    public static function answerTo(HttpResponse $response, array $options = [], ?Request $request = null): Answer
    {
        $transport = (new FakeTransport())->willReturn($response);

        return self::gateway($transport, $options)->complete($request ?? self::request());
    }

    /**
     * The events of a stream of a server with these options, given these chunks.
     *
     * @param array<array-key, string> $chunks
     * @param array<string, bool|int|string> $options
     * @return list<StreamEvent>
     */
    public static function eventsOf(array $chunks, array $options = [], ?Request $request = null): array
    {
        $transport = (new FakeTransport())->willStream(200, ['Content-Type' => 'text/event-stream'], ...array_values($chunks));

        return Talk::events(self::gateway($transport, $options)->stream($request ?? self::request()));
    }

    /**
     * @param array<mixed> $data
     */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
