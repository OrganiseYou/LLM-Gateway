<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Adapter\Gemini\GenerateContentAdapter;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
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
 * Builds what the tests of the generate-content adapter need: a preset on a made-up address, a gateway on it,
 * requests, and the bodies and events a server of the format gives. Everything here is made up.
 */
final class GenerateContent
{
    /** The base URL carries everything before `/models`, as the preset entries do. */
    public const string BASE_URL = 'https://llm.example.test/v1beta';

    public const string MODEL = 'example-generate-1';

    /** Every top-level field the adapter may ever send. */
    public const array FIELDS = ['systemInstruction', 'contents', 'tools', 'toolConfig', 'generationConfig', 'store'];

    /** Every field of `generationConfig` the adapter may ever send. */
    public const array GENERATION_FIELDS = ['maxOutputTokens', 'temperature', 'responseMimeType', 'responseJsonSchema', 'responseFormat'];

    /** The options row 4 of the preset entries sets (docs/design/adapters.md, section 9): the way in with a key. */
    public const array KEY_ENTRY = ['store_field' => 'false'];

    /** The options rows 5 and 6 of the preset entries set: the way in with a token. */
    public const array TOKEN_ENTRY = ['model_listing' => 'none', 'tools_with_structured_output' => 'emulated'];

    /** The value the format documents in place of a signature, for a call the model did not produce. */
    public const string NO_SIGNATURE = 'skip_thought_signature_validator';

    /**
     * @param array<string, bool|int|string> $options
     * @param list<ModelOverride> $overrides
     */
    public static function preset(array $options = [], array $overrides = []): Preset
    {
        return new Preset(
            id: 'generate-content-test',
            label: 'Generate content (test)',
            description: 'A made-up server for the tests.',
            adapter: GenerateContentAdapter::class,
            baseUrlRule: BaseUrlRule::CustomerMay,
            defaultBaseUrl: self::BASE_URL,
            credentialKinds: [CredentialKind::BearerKey, CredentialKind::HeaderKey, CredentialKind::BearerToken, CredentialKind::None],
            keyHeader: 'x-goog-api-key',
            fields: [new PresetField('quota_project', 'Quota project', false, '[a-z][a-z0-9-]+', 'x-goog-user-project')],
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
            $credentials ?? new HeaderKey('key-of-the-test'),
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
     * A text part of the format.
     *
     * @param array<string, mixed> $more members beside the text: `thought`, `thoughtSignature`
     * @return array<string, mixed>
     */
    public static function text(string $text = 'Hello.', array $more = []): array
    {
        return ['text' => $text, ...$more];
    }

    /**
     * A part of the format that asks for a call of the tool "lookup".
     *
     * @param string|null $id null: the server gives the call no id
     * @param string|null $mark the thought signature on the part, or null for none
     * @return array<string, mixed>
     */
    public static function call(mixed $arguments = ['city' => 'Paris'], ?string $id = 'fc-example01', string $name = 'lookup', ?string $mark = null): array
    {
        $call = ['name' => $name, 'args' => $arguments];
        if ($id !== null) {
            $call = ['id' => $id, ...$call];
        }

        return $mark === null ? ['functionCall' => $call] : ['functionCall' => $call, 'thoughtSignature' => $mark];
    }

    /**
     * The body of a response, or the data of one chunk of a stream: one candidate with these parts.
     *
     * @param list<mixed>|null $parts null: one text part that says "Hello."
     * @param string|null $finish the finish reason, or null for none
     * @param array<string, mixed> $more top-level fields beside the usual ones, or in place of them
     * @return array<string, mixed>
     */
    public static function body(?array $parts = null, ?string $finish = 'STOP', array $more = []): array
    {
        $candidate = ['index' => 0, 'content' => ['role' => 'model', 'parts' => $parts ?? [self::text()]]];
        if ($finish !== null) {
            $candidate['finishReason'] = $finish;
        }

        return [
            'candidates' => [$candidate],
            'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2, 'totalTokenCount' => 5],
            'modelVersion' => self::MODEL,
            'responseId' => 'resp-example01',
            ...$more,
        ];
    }

    /**
     * A successful answer of the format with these parts.
     *
     * @param list<mixed>|null $parts null: one text part that says "Hello."
     * @param array<string, mixed> $more
     */
    public static function answered(?array $parts = null, ?string $finish = 'STOP', array $more = []): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], self::json(self::body($parts, $finish, $more)));
    }

    /**
     * A failed response of the format: the documented error body with this status word, and a reason among its
     * details when one is given.
     *
     * @param array<string, string> $headers
     */
    public static function failed(int $status, string $word = 'INVALID_ARGUMENT', ?string $reason = null, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers, self::errorBody($status, $word, $reason));
    }

    public static function errorBody(int $status, string $word, ?string $reason = null, string $message = 'No.'): string
    {
        $error = ['code' => $status, 'message' => $message, 'status' => $word];
        if ($reason !== null) {
            $error['details'] = [['@type' => 'type.example.test/ErrorInfo', 'reason' => $reason, 'domain' => 'example.test', 'metadata' => (object) []]];
        }

        return self::json(['error' => $error]);
    }

    /**
     * One event of a stream of the format, as the bytes a server sends: a data line with a whole response.
     *
     * @param array<string, mixed> $data
     */
    public static function event(array $data): string
    {
        return 'data: ' . self::json($data) . "\n\n";
    }

    /**
     * One chunk of a stream: a candidate with these parts.
     *
     * @param list<mixed> $parts
     * @param array<string, mixed> $more
     */
    public static function chunk(array $parts, ?string $finish = null, array $more = []): string
    {
        return self::event(self::body($parts, $finish, $more));
    }

    /**
     * A whole stream that says this text, each piece in a chunk of its own; the last chunk ends the answer.
     *
     * @return list<string>
     */
    public static function saying(string ...$pieces): array
    {
        $pieces = $pieces === [] ? ['Hel', 'lo.'] : array_values($pieces);
        $chunks = [];
        foreach ($pieces as $at => $piece) {
            $chunks[] = self::chunk([self::text($piece)], $at === count($pieces) - 1 ? 'STOP' : null);
        }

        return $chunks;
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
