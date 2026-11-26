<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Testing\AdapterFixtures;
use OrganiseYou\LlmGateway\Testing\AdapterSubject;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Testing\FakeGatewaySubject;
use OrganiseYou\LlmGateway\Testing\NotApplicable;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

/**
 * The shared test set on a gateway that breaks one rule on purpose. No runner finds this class (its name does not
 * end in "Test"): a test builds it, names the fault and calls one test of the set, which must then fail. Without a
 * fault the gateway is sound, and the same test passes.
 */
final class BrokenContractCase extends ContractTestCase
{
    public const string WRONG_REASON = 'gives a refused request (status 400) the reason unavailable and tries it again';
    public const string ANOTHER_KEY = 'is built with another key than the one of the set';
    public const string MISSING_FIXTURE = 'has no fixture for a tool call, though the gateway says it can call tools';

    public const string SENDS_TRACE_ID = 'sends the trace id of the caller to the provider';
    public const string PROVIDER_WORDS_IN_DETAIL = 'copies the words of the provider into the detail of an error';
    public const string SENDS_WHAT_IT_CANNOT_CARRY = 'sends a request its fixtures say the format cannot carry';

    public const array ON_THE_ADAPTER = [
        self::WRONG_REASON, self::ANOTHER_KEY, self::MISSING_FIXTURE, self::SENDS_TRACE_ID, self::PROVIDER_WORDS_IN_DETAIL,
        self::SENDS_WHAT_IT_CANNOT_CARRY, BrokenGateway::SECOND_REQUEST_AFTER_REDIRECT,
    ];

    /** Whether a sound gateway is the outside adapter and not the fake. */
    public bool $onTheAdapter = false;

    /** One of the faults of BrokenGateway or of this class; empty: a sound gateway. */
    public string $fault = '';

    protected function subject(RetryPolicy $retry): ContractSubject
    {
        $fault = $this->fault;

        if ($this->onTheAdapter || in_array($fault, self::ON_THE_ADAPTER, true)) {
            $options = match ($fault) {
                self::SENDS_TRACE_ID => ['max_tool_name' => 32, 'sends_trace' => true],
                // The preset sets no limit, and the fixtures still say a long tool name cannot be carried.
                self::SENDS_WHAT_IT_CANNOT_CARRY => [],
                default => ['max_tool_name' => 32],
            };
            $subject = new AdapterSubject(
                Talk::preset($options),
                new BearerKey($fault === self::ANOTHER_KEY ? 'key-of-another-test' : self::MARKER_KEY),
                self::fixtures($fault),
                'model-a',
                $retry,
            );

            return $fault === BrokenGateway::SECOND_REQUEST_AFTER_REDIRECT ? new BrokenSubject($subject, $fault) : $subject;
        }

        if ($fault === BrokenGateway::ANSWERS_WHAT_IT_CANNOT) {
            $few = new Capabilities(Support::None, Support::None, Support::None, Support::None, Support::None, Support::Emulated, Support::None, null);

            // The fake refuses by itself: it is told it can do everything, and the broken gateway says otherwise.
            return new BrokenSubject(new FakeGatewaySubject(), $fault, $few);
        }

        return new BrokenSubject(new FakeGatewaySubject(), $fault);
    }

    private static function fixtures(string $fault): AdapterFixtures
    {
        return new class ($fault) implements AdapterFixtures {
            private readonly TalkFixtures $sound;

            public function __construct(private readonly string $fault)
            {
                $this->sound = new TalkFixtures(32);
            }

            public function errorInBody(string $echo): HttpResponse
            {
                // The adapter of the format copies "words" into the detail of its error: what a careless one does.
                return $this->fault === BrokenContractCase::PROVIDER_WORDS_IN_DETAIL
                    ? new HttpResponse(200, [], Talk::json(['fault' => ['code' => $echo, 'words' => $echo]]))
                    : $this->sound->errorInBody($echo);
            }

            public function errorInStream(string $echo): array
            {
                return $this->sound->errorInStream($echo);
            }

            public function requestTheFormatCannotCarry(Request $request): Request|NotApplicable
            {
                return $this->sound->requestTheFormatCannotCarry($request);
            }

            public function answer(ContractFixture $fixture): ?HttpResponse
            {
                return $this->fault === BrokenContractCase::MISSING_FIXTURE && $fixture === ContractFixture::ToolCall
                    ? null
                    : $this->sound->answer($fixture);
            }

            public function stream(ContractFixture $fixture): ?array
            {
                return $this->sound->stream($fixture);
            }

            public function failed(int $status, string $echo): HttpResponse
            {
                // The adapter of the format reads the code "overloaded" as unavailable: a wrong mapping for a 400.
                return $this->fault === BrokenContractCase::WRONG_REASON && $status === 400
                    ? new HttpResponse($status, [], Talk::json(['fault' => ['code' => 'overloaded', 'words' => $echo]]))
                    : $this->sound->failed($status, $echo);
            }
        };
    }
}
