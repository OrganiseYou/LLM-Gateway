<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\WrongUse;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\TransportFailure;
use SensitiveParameter;

/**
 * A gateway built from a preset on the fake transport, for the shared test set: the way to prove an adapter, also
 * one that a host project wrote itself.
 *
 * Every response it lets the provider give repeats the test key of the set in its headers, in a comment line of a
 * stream and, through the fixtures, in an error body: a provider can repeat a key, and no output of the gateway
 * may then hold it.
 */
final readonly class AdapterSubject implements ContractSubject
{
    private FakeClock $clock;

    private FakeTransport $transport;

    private AttemptRecorder $recorder;

    private Gateway $gateway;

    /**
     * @param Credentials $credentials built with ContractTestCase::MARKER_KEY as the key or the token
     * @param string $model a model name the fixtures answer for
     * @param RetryPolicy $retry the policy the set hands to subject()
     * @param array<string, string> $settings the values for the fields of the preset
     * @param string|null $baseUrl for a preset whose address the customer gives
     */
    public function __construct(
        Preset $preset,
        #[SensitiveParameter]
        Credentials $credentials,
        private AdapterFixtures $fixtures,
        private string $model,
        RetryPolicy $retry,
        array $settings = [],
        ?string $baseUrl = null,
    ) {
        $this->clock = new FakeClock();
        $this->transport = new FakeTransport($this->clock);
        $this->recorder = new AttemptRecorder();
        $this->gateway = GatewayFactory::create(
            $preset,
            $credentials,
            $this->transport,
            new GatewayConfig($retry, $baseUrl, $settings, $this->recorder, $this->clock),
        );
    }

    public function gateway(): Gateway
    {
        return $this->gateway;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function makesAttempts(): bool
    {
        return true;
    }

    public function gives(ContractFixture $fixture, bool $asStream): void
    {
        if ($asStream && $this->streamsNatively()) {
            $this->transport->willStream(200, self::echoHeaders(), ...$this->chunks($fixture));

            return;
        }

        $response = $this->fixtures->answer($fixture);
        if ($response === null) {
            throw new WrongUse('There is no fixture for the answer "' . $fixture->value . '", and the gateway says it can give it.');
        }
        if ($response->status < 200 || $response->status > 299) {
            throw new WrongUse('The fixture for the answer "' . $fixture->value . '" must be a successful response.');
        }

        $this->transport->willReturn(new HttpResponse($response->status, [...$response->headers, ...self::echoHeaders()], $response->body));
    }

    public function fails(ContractFailure $failure, ?int $retryAfterSeconds = null, bool $asStream = false, ?string $echo = null): void
    {
        $echo ??= ContractTestCase::MARKER_KEY;
        $transportFailure = $failure->transportFailure();
        if ($transportFailure !== null) {
            $this->transport->willFail($transportFailure);

            return;
        }

        $status = (int) $failure->httpStatus();
        $headers = self::echoHeaders();
        if ($retryAfterSeconds !== null) {
            $headers['Retry-After'] = (string) $retryAfterSeconds;
        }

        if ($failure === ContractFailure::UnreadableBody) {
            $this->transport->willReturn(new HttpResponse($status, $headers, '<<< no body of any format: ' . ContractTestCase::MARKER_KEY . ' >>>'));

            return;
        }

        if ($failure === ContractFailure::ErrorInBody && $asStream && $this->streamsNatively()) {
            $chunks = $this->fixtures->errorInStream($echo);
            if ($chunks instanceof NotApplicable) {
                throw new WrongUse('The fixtures say an error inside a stream does not apply: ask cannotPlay() first.');
            }
            self::assertRepeats(implode('', $chunks), $echo);
            $this->transport->willStream(200, $headers, ': ' . ContractTestCase::MARKER_KEY . "\n\n", ...$chunks);

            return;
        }

        if ($failure === ContractFailure::ErrorInBody) {
            $response = $this->fixtures->errorInBody($echo);
            if ($response instanceof NotApplicable) {
                throw new WrongUse('The fixtures say an error inside a successful response does not apply: ask cannotPlay() first.');
            }
            if ($response->status !== 200) {
                throw new WrongUse('The fixture for an error inside a successful response must have the status 200.');
            }
        } else {
            $response = $this->fixtures->failed($status, $echo);
            if ($response->status !== $status) {
                throw new WrongUse('The fixture for a failed request must have the status it was asked for: ' . $status . '.');
            }
        }
        self::assertRepeats($response->body, $echo);
        $status = $response->status;

        $this->transport->willReturn(new HttpResponse($status, [...$response->headers, ...$headers], $response->body));
    }

    public function breaksAfterTheFirstText(): void
    {
        if (! $this->streamsNatively()) {
            throw new WrongUse('Only a stream that arrives in pieces can break half-way.');
        }

        $chunks = $this->chunks(ContractFixture::PlainAnswer);
        if (count($chunks) < 3) {
            throw new WrongUse('The fixture for the stream of the plain answer must have two chunks or more, with the first piece of text in the first.');
        }

        // The comment line with the key comes first: the stream breaks after it and the first chunk of the fixture.
        $this->transport->willStream(200, self::echoHeaders(), ...$chunks)->willBreakStreamAfter(2, TransportFailure::ConnectionFailed);
    }

    public function cannotPlay(ContractFailure $failure, bool $asStream): ?string
    {
        if ($failure !== ContractFailure::ErrorInBody) {
            return null;
        }

        $fixture = $asStream && $this->streamsNatively()
            ? $this->fixtures->errorInStream('words')
            : $this->fixtures->errorInBody('words');

        return $fixture instanceof NotApplicable ? $fixture->reason : null;
    }

    public function requestItCannotCarry(Request $request): Request|NotApplicable
    {
        return $this->fixtures->requestTheFormatCannotCarry($request);
    }

    public function sentToTheProvider(string $text): bool
    {
        foreach ($this->transport->requests() as $request) {
            $sent = $request->url . "\n" . $request->body;
            foreach ($request->allHeaders() as $name => $value) {
                $sent .= "\n" . $name . ': ' . $value;
            }
            if (str_contains($sent, $text)) {
                return true;
            }
        }

        return false;
    }

    public function delays(float $seconds): void
    {
        $this->transport->willDelay($seconds);
    }

    public function requestsReceived(): int
    {
        return count($this->transport->requests());
    }

    public function waits(): array
    {
        return $this->clock->sleeps();
    }

    public function timeoutsGiven(): array
    {
        $timeouts = [];
        foreach ($this->transport->requests() as $request) {
            $timeouts[] = $request->timeoutSeconds;
        }

        return $timeouts;
    }

    public function signedInWith(#[SensitiveParameter] string $key): bool
    {
        foreach ($this->transport->requests() as $request) {
            foreach ($request->secretHeaders as $secret) {
                if (str_contains($secret->reveal(), $key)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function logged(): array
    {
        $requests = [];
        foreach ($this->recorder->started() as $at => $report) {
            $requests['the report before attempt ' . ($at + 1)] = $report->request;
        }
        foreach ($this->recorder->finished() as $at => $report) {
            $requests['the report after attempt ' . ($at + 1)] = $report->request;
        }

        $logged = [];
        foreach ($requests as $where => $request) {
            $logged[$where . ', toArray'] = (string) json_encode($request->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR);
            foreach (OutputForms::ofValue($request) as $form => $output) {
                $logged[$where . ', ' . $form] = $output;
            }
        }

        return $logged;
    }

    private function streamsNatively(): bool
    {
        return $this->gateway->capabilities($this->model)->streaming === Support::Native;
    }

    /**
     * The chunks of the fixture, after a comment line of the event stream that repeats the key.
     *
     * @return list<string>
     */
    private function chunks(ContractFixture $fixture): array
    {
        $chunks = $this->fixtures->stream($fixture);
        if ($chunks === null || $chunks === []) {
            throw new WrongUse('There is no fixture for the stream of "' . $fixture->value . '", and the gateway says it streams.');
        }

        return [': ' . ContractTestCase::MARKER_KEY . "\n\n", ...$chunks];
    }

    private static function assertRepeats(string $body, string $echo): void
    {
        if (! str_contains($body, $echo)) {
            throw new WrongUse('The fixture for a failure must repeat the text it is given in its body, where the format has room for words of the provider.');
        }
    }

    /**
     * @return array<string, string>
     */
    private static function echoHeaders(): array
    {
        return ['X-Echo' => ContractTestCase::MARKER_KEY, 'Set-Cookie' => 'session=' . ContractTestCase::MARKER_KEY];
    }
}
