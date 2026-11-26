<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use LogicException;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Preset\PresetField;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Transport\AttemptObserver;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\NullAttemptObserver;
use OrganiseYou\LlmGateway\Transport\Transport;
use SensitiveParameter;

/**
 * Builds what the tests of the adapter base need: a preset for the made-up "talk" format, a gateway on it, requests
 * and the bodies a server of that format gives. Everything here is made up.
 */
final class Talk
{
    public const string BASE_URL = 'https://talk.example.test/api';

    /**
     * @param array<string, bool|int|string> $options
     */
    public static function preset(array $options = []): Preset
    {
        return new Preset(
            id: 'talk-test',
            label: 'Talk (test)',
            description: 'A made-up server for the tests.',
            adapter: TalkAdapter::class,
            baseUrlRule: BaseUrlRule::CustomerMay,
            defaultBaseUrl: self::BASE_URL,
            credentialKinds: [CredentialKind::BearerKey, CredentialKind::HeaderKey, CredentialKind::BearerToken, CredentialKind::None],
            keyHeader: 'X-Talk-Key',
            fields: [new PresetField('dialect', 'Dialect', false, '[a-z]+')],
            dataLocation: 'not stated by the provider',
            dataLocationSource: null,
            sendsNoStoreOption: false,
            adapterOptions: $options,
        );
    }

    /**
     * @param array<string, bool|int|string> $options
     * @param array<string, string> $settings
     */
    public static function gateway(
        Transport $transport,
        ?FakeClock $clock = null,
        ?RetryPolicy $retry = null,
        #[SensitiveParameter]
        ?Credentials $credentials = null,
        ?AttemptObserver $observer = null,
        array $options = [],
        array $settings = [],
    ): Gateway {
        return GatewayFactory::create(
            self::preset($options),
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

    public static function request(float $timeoutSeconds = 30.0, ?string $traceId = 'trace-1'): Request
    {
        return new Request('model-a', [Message::user('Say hello.')], $timeoutSeconds, traceId: $traceId);
    }

    /**
     * A successful answer of the format.
     *
     * @param array<string, mixed> $more
     */
    public static function answered(string $say = 'Hello.', array $more = []): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], self::json([
            'say' => $say,
            'end' => 'done',
            'by' => 'model-a-0001',
            'used' => ['in' => 3, 'out' => 2],
            ...$more,
        ]));
    }

    /**
     * A failed answer of the format.
     *
     * @param array<string, string> $headers
     */
    public static function failed(int $status, ?string $code = null, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers, $code === null ? '' : self::json(['fault' => ['code' => $code]]));
    }

    /**
     * One event of a stream of the format, as the bytes a server sends.
     *
     * @param array<string, mixed> $data
     */
    public static function event(string $event, array $data): string
    {
        return 'event: ' . $event . "\n" . 'data: ' . self::json($data) . "\n\n";
    }

    /**
     * The gateway exception a call ends in.
     *
     * @param callable(): mixed $call
     */
    public static function errorOf(callable $call): GatewayException
    {
        try {
            $call();
        } catch (GatewayException $error) {
            return $error;
        }

        throw new LogicException('The call did not fail with a gateway exception.');
    }

    /**
     * Reads a stream to its end.
     *
     * @phpstan-impure reading a stream is what sends its request
     * @param iterable<int, StreamEvent> $stream
     * @return list<StreamEvent>
     */
    public static function events(iterable $stream): array
    {
        $events = [];
        foreach ($stream as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * The failed event a stream ended with.
     *
     * @param list<StreamEvent> $events
     */
    public static function failedIn(array $events): StreamFailed
    {
        $last = $events[count($events) - 1] ?? null;
        if (! $last instanceof StreamFailed) {
            throw new LogicException('The stream did not end with a failed event.');
        }

        return $last;
    }

    /**
     * The finished event a stream ended with.
     *
     * @param list<StreamEvent> $events
     */
    public static function finishedIn(array $events): StreamFinished
    {
        $last = $events[count($events) - 1] ?? null;
        if (! $last instanceof StreamFinished) {
            throw new LogicException('The stream did not end with a finished event.');
        }

        return $last;
    }

    /**
     * A token supplier that gives token-1, token-2, … and counts how often it was asked.
     */
    public static function tokens(): CountingTokenSupplier
    {
        return new CountingTokenSupplier();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
