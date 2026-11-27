<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Adapter\Anthropic\MessagesAdapter;
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
 * Builds what the tests of the Messages adapter need: a preset on a made-up address, a gateway on it, requests, and
 * the bodies and events a server of the format gives. Everything here is made up.
 */
final class Messages
{
    public const string BASE_URL = 'https://llm.example.test';

    public const string MODEL = 'example-messages-1';

    /** Every top-level field the adapter may ever send. */
    public const array FIELDS = ['model', 'max_tokens', 'system', 'messages', 'tools', 'tool_choice', 'output_config', 'temperature', 'stream'];

    /**
     * The least a preset on this adapter sets: the version of the API and the maximum of output tokens for a
     * request that gives none. With the earlier of the two versions the provider file records, on which a request
     * may still carry a temperature; every capability is then the default of the format.
     */
    public const array EARLIER_VERSION = ['api_version' => '2023-06-01', 'default_max_output_tokens' => 16000];

    /** The options row 3 of the preset entries sets (docs/design/adapters.md, section 9). */
    public const array CURRENT_VERSION = ['api_version' => '2026-10-21', 'default_max_output_tokens' => 16000, 'temperature' => 'not_accepted'];

    /**
     * @param array<string, bool|int|string> $options
     * @param list<ModelOverride> $overrides
     */
    public static function preset(array $options = self::EARLIER_VERSION, array $overrides = []): Preset
    {
        return new Preset(
            id: 'messages-test',
            label: 'Messages (test)',
            description: 'A made-up server for the tests.',
            adapter: MessagesAdapter::class,
            baseUrlRule: BaseUrlRule::CustomerMay,
            defaultBaseUrl: self::BASE_URL,
            credentialKinds: [CredentialKind::BearerKey, CredentialKind::HeaderKey, CredentialKind::BearerToken, CredentialKind::None],
            keyHeader: 'x-api-key',
            fields: [new PresetField('workspace_id', 'Workspace', false, 'wrkspc_[A-Za-z0-9_-]+', 'anthropic-workspace-id')],
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
        array $options = self::EARLIER_VERSION,
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
     * @return array<string, mixed>
     */
    public static function text(string $text = 'Hello.'): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * A block of the format that asks for a call of the tool "lookup".
     *
     * @return array<string, mixed>
     */
    public static function call(mixed $input = ['city' => 'Paris'], string $id = 'toolu_example01', string $name = 'lookup'): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
    }

    /**
     * A thinking block of the format.
     *
     * @return array<string, mixed>
     */
    public static function thinking(string $thinking = 'One city, so the lookup tool.', string $mark = 'c2lnLWV4YW1wbGU='): array
    {
        return ['type' => 'thinking', 'thinking' => $thinking, 'signature' => $mark];
    }

    /**
     * A successful answer of the format with these content blocks.
     *
     * @param list<mixed>|null $content null: one text block that says "Hello."
     * @param array<string, mixed> $more top-level fields beside the usual ones, or in place of them
     */
    public static function answered(?array $content = null, ?string $stop = 'end_turn', array $more = []): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], self::json([
            'id' => 'msg_example01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'example-messages-1',
            'content' => $content ?? [self::text()],
            'stop_reason' => $stop,
            'stop_sequence' => null,
            'stop_details' => null,
            'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
            ...$more,
        ]));
    }

    /**
     * A failed response of the format: the documented error body with this type, and a code when one is given.
     *
     * @param array<string, string> $headers
     */
    public static function failed(int $status, string $type = 'invalid_request_error', array $headers = [], ?string $code = null): HttpResponse
    {
        return new HttpResponse($status, $headers, self::errorBody($type, $code));
    }

    public static function errorBody(string $type, ?string $code = null, string $message = 'No.'): string
    {
        $error = ['type' => $type, 'message' => $message];
        if ($code !== null) {
            $error['details'] = ['error_code' => $code];
        }

        return self::json(['type' => 'error', 'error' => $error, 'request_id' => 'req_example01']);
    }

    /**
     * One event of a stream of the format, as the bytes a server sends: the name line, and the data with its type.
     *
     * @param array<string, mixed> $data
     */
    public static function event(string $type, array $data = []): string
    {
        return 'event: ' . $type . "\ndata: " . self::json(['type' => $type, ...$data]) . "\n\n";
    }

    /**
     * The first event of a stream.
     *
     * @param array<string, mixed> $usage
     */
    public static function started(array $usage = ['input_tokens' => 3, 'output_tokens' => 1], string $model = 'example-messages-1'): string
    {
        return self::event('message_start', ['message' => [
            'id' => 'msg_example01', 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'content' => [],
            'stop_reason' => null, 'stop_sequence' => null, 'usage' => $usage,
        ]]);
    }

    /**
     * The start of a content block.
     *
     * @param array<string, mixed> $block
     */
    public static function blockStarted(int $index, array $block): string
    {
        return self::event('content_block_start', ['index' => $index, 'content_block' => $block]);
    }

    /**
     * One delta of a content block: `text_delta`, `input_json_delta`, `thinking_delta` or `signature_delta`.
     */
    public static function delta(int $index, string $type, string $piece): string
    {
        $member = match ($type) {
            'text_delta' => 'text',
            'input_json_delta' => 'partial_json',
            'thinking_delta' => 'thinking',
            'signature_delta' => 'signature',
            default => 'value',
        };

        return self::event('content_block_delta', ['index' => $index, 'delta' => ['type' => $type, $member => $piece]]);
    }

    public static function blockStopped(int $index): string
    {
        return self::event('content_block_stop', ['index' => $index]);
    }

    /**
     * The two events that end a stream: the stop reason with the usage, and the last event.
     *
     * @return list<string>
     */
    public static function stopped(?string $stop = 'end_turn'): array
    {
        return [
            self::event('message_delta', ['delta' => ['stop_reason' => $stop, 'stop_sequence' => null], 'usage' => ['output_tokens' => 2]]),
            self::event('message_stop'),
        ];
    }

    /**
     * A whole stream that says this text in one text block, each piece in its own delta.
     *
     * @return list<string>
     */
    public static function saying(string ...$pieces): array
    {
        $events = [self::started(), self::blockStarted(0, ['type' => 'text', 'text' => ''])];
        foreach ($pieces === [] ? ['Hello.'] : $pieces as $piece) {
            $events[] = self::delta(0, 'text_delta', $piece);
        }

        return [...$events, self::blockStopped(0), ...self::stopped()];
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
    public static function bodyFor(Request $request, array $options = self::EARLIER_VERSION, bool $stream = false, array $overrides = []): array
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
    public static function answerTo(HttpResponse $response, array $options = self::EARLIER_VERSION, ?Request $request = null): Answer
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
    public static function eventsOf(array $chunks, array $options = self::EARLIER_VERSION, ?Request $request = null): array
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
