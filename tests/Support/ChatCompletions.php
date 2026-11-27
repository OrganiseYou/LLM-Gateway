<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\ChatCompletionsAdapter;
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
 * Builds what the tests of the chat-completions adapter need: a preset on a made-up address, a gateway on it,
 * requests, and the bodies and chunks a server of the format gives. Everything here is made up.
 */
final class ChatCompletions
{
    public const string BASE_URL = 'https://llm.example.test/v1';

    public const string MODEL = 'example-chat-1';

    /** Every top-level field the adapter may ever send, with both names of the maximum of output tokens. */
    public const array FIELDS = [
        'model', 'messages', 'tools', 'tool_choice', 'response_format', 'max_completion_tokens', 'max_tokens',
        'temperature', 'stream', 'stream_options', 'store',
    ];

    /**
     * The options of a server whose answer holds its reasoning as an entry of the content: the five that row 13 of
     * the preset entries sets (docs/design/adapters.md, section 9).
     */
    public const array CONTENT_ENTRIES_SERVER = [
        'max_tokens_field' => 'max_tokens',
        'required_word' => 'any',
        'tool_result_name' => true,
        'reasoning_carrier' => 'content_entries',
        'stream_usage' => 'unasked',
    ];

    /**
     * @param array<string, bool|int|string> $options
     * @param list<ModelOverride> $overrides
     */
    public static function preset(array $options = [], array $overrides = []): Preset
    {
        return new Preset(
            id: 'chat-completions-test',
            label: 'Chat completions (test)',
            description: 'A made-up server for the tests.',
            adapter: ChatCompletionsAdapter::class,
            baseUrlRule: BaseUrlRule::CustomerMay,
            defaultBaseUrl: self::BASE_URL,
            credentialKinds: [CredentialKind::BearerKey, CredentialKind::HeaderKey, CredentialKind::BearerToken, CredentialKind::None],
            keyHeader: 'api-key',
            fields: [new PresetField('project', 'Project', false, '[a-z0-9-]+', 'X-Example-Project')],
            dataLocation: 'not stated by the provider',
            dataLocationSource: null,
            sendsNoStoreOption: ($options['store_field'] ?? 'omit') !== 'omit',
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

    public static function trip(): ResponseFormat
    {
        return new ResponseFormat(
            JsonSchema::fromJson('{"type":"object","properties":{"city":{"type":"string"},"days":{"type":"integer"}},"required":["city","days"],"additionalProperties":false}'),
            'trip',
            'A trip to one city.',
        );
    }

    /**
     * A successful answer of the format with this message.
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed> $more top-level fields beside the usual ones
     */
    public static function answered(array $message = ['role' => 'assistant', 'content' => 'Hello.'], ?string $finish = 'stop', array $more = []): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], self::json([
            'id' => 'chatcmpl-example-1',
            'object' => 'chat.completion',
            'model' => 'example-chat-1-0001',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5],
            ...$more,
        ]));
    }

    /**
     * An assistant message of the format that asks for these calls of the tool "lookup".
     *
     * @return array<string, mixed>
     */
    public static function calling(mixed ...$arguments): array
    {
        $calls = [];
        foreach (array_values($arguments) as $at => $given) {
            $calls[] = ['id' => 'call_' . ($at + 1), 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => $given]];
        }

        return ['role' => 'assistant', 'content' => null, 'tool_calls' => $calls];
    }

    /**
     * A failed response of the format.
     *
     * @param array<string, mixed>|string $body
     * @param array<string, string> $headers
     */
    public static function failed(int $status, array|string $body = '', array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers, is_array($body) ? self::json($body) : $body);
    }

    /**
     * One chunk of a stream of the format, as the bytes a server sends: a choice with this delta.
     *
     * @param array<string, mixed> $delta
     * @param array<string, mixed> $more top-level fields beside the usual ones
     */
    public static function chunk(array $delta, ?string $finish = null, array $more = []): string
    {
        return self::data(['id' => 'chatcmpl-example-1', 'object' => 'chat.completion.chunk', 'model' => 'example-chat-1-0001', 'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]], ...$more]);
    }

    /**
     * One event of a stream with this data.
     *
     * @param array<string, mixed> $data
     */
    public static function data(array $data): string
    {
        return 'data: ' . self::json($data) . "\n\n";
    }

    public const string DONE = "data: [DONE]\n\n";

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
            $transport->willStream(200, [], self::chunk(['role' => 'assistant', 'content' => 'Hello.']), self::chunk([], 'stop'), self::DONE);
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
     * @param array<string, bool|int|string> $options
     * @param array<array-key, string> $chunks
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
