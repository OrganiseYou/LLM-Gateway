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
    public const string OPAQUE_FOR_THE_STREAM_ONLY = 'has fixtures that say opaque data does not apply to the whole response, and give a stream of it';
    public const string SENDS_FOREIGN_OPAQUE = 'sends an opaque part it did not make to the provider';
    public const string SENDS_OPAQUE_OF_A_CHANGED_MESSAGE = 'sends its opaque data with a message whose text was changed';
    public const string RETRIES_WHAT_FORBIDS_IT = 'tries a request again after a response that says it must not be tried again';
    public const string REFUSAL_FOR_THE_STREAM_ONLY = 'has fixtures that say a refusal does not apply to the whole response, and give a stream of it';
    public const string PLAIN_ANSWER_DOES_NOT_APPLY = 'has fixtures that say the plain answer does not apply, which no format may say';

    public const array ON_THE_ADAPTER = [
        self::WRONG_REASON, self::ANOTHER_KEY, self::MISSING_FIXTURE, self::SENDS_TRACE_ID, self::PROVIDER_WORDS_IN_DETAIL,
        self::SENDS_WHAT_IT_CANNOT_CARRY, BrokenGateway::SECOND_REQUEST_AFTER_REDIRECT, self::SENDS_FOREIGN_OPAQUE,
        self::SENDS_OPAQUE_OF_A_CHANGED_MESSAGE, self::OPAQUE_FOR_THE_STREAM_ONLY, self::RETRIES_WHAT_FORBIDS_IT,
        self::REFUSAL_FOR_THE_STREAM_ONLY, self::PLAIN_ANSWER_DOES_NOT_APPLY,
    ];

    /** The faults of this class: a fault of the adapter, its preset or its fixtures. Every other is BrokenGateway's. */
    private const array OF_THE_ADAPTER = [
        self::WRONG_REASON, self::ANOTHER_KEY, self::MISSING_FIXTURE, self::SENDS_TRACE_ID, self::PROVIDER_WORDS_IN_DETAIL,
        self::SENDS_WHAT_IT_CANNOT_CARRY, self::SENDS_FOREIGN_OPAQUE, self::SENDS_OPAQUE_OF_A_CHANGED_MESSAGE,
        self::OPAQUE_FOR_THE_STREAM_ONLY, self::RETRIES_WHAT_FORBIDS_IT, self::REFUSAL_FOR_THE_STREAM_ONLY,
        self::PLAIN_ANSWER_DOES_NOT_APPLY,
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
                self::SENDS_FOREIGN_OPAQUE => ['max_tool_name' => 32, 'aside_check' => 'none'],
                self::SENDS_OPAQUE_OF_A_CHANGED_MESSAGE => ['max_tool_name' => 32, 'aside_check' => 'mark_only'],
                self::RETRIES_WHAT_FORBIDS_IT => ['max_tool_name' => 32, 'ignores_no_retry' => true],
                default => ['max_tool_name' => 32],
            };
            $subject = new AdapterSubject(
                Talk::preset($options),
                new BearerKey($fault === self::ANOTHER_KEY ? 'key-of-another-test' : self::MARKER_KEY),
                self::fixtures($fault),
                'model-a',
                $retry,
            );

            // A fault of the gateway itself, on the adapter: the broken gateway stands around the sound adapter.
            return $fault !== '' && ! in_array($fault, self::OF_THE_ADAPTER, true) ? new BrokenSubject($subject, $fault) : $subject;
        }

        if ($fault === BrokenGateway::ANSWERS_WHAT_IT_CANNOT) {
            $few = new Capabilities(Support::None, Support::None, Support::None, Support::None, Support::None, Support::Emulated, Support::None, null, Support::None);

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

            public function retryForbidden(HttpResponse $failed): HttpResponse
            {
                return $this->sound->retryForbidden($failed);
            }

            public function requestTheFormatCannotCarry(Request $request): Request|NotApplicable
            {
                return $this->sound->requestTheFormatCannotCarry($request);
            }

            public function answer(ContractFixture $fixture): HttpResponse|NotApplicable|null
            {
                if ($this->fault === BrokenContractCase::OPAQUE_FOR_THE_STREAM_ONLY && $fixture === ContractFixture::ToolCallWithOpaqueData) {
                    return new NotApplicable('the whole response of this format holds no such data');
                }
                if ($this->fault === BrokenContractCase::REFUSAL_FOR_THE_STREAM_ONLY && $fixture === ContractFixture::Refused) {
                    return new NotApplicable('the whole response of this format tells no refusal');
                }
                if ($this->fault === BrokenContractCase::PLAIN_ANSWER_DOES_NOT_APPLY && $fixture === ContractFixture::PlainAnswer) {
                    return new NotApplicable('this format has no plain answer');
                }

                return $this->fault === BrokenContractCase::MISSING_FIXTURE && $fixture === ContractFixture::ToolCall
                    ? null
                    : $this->sound->answer($fixture);
            }

            public function stream(ContractFixture $fixture): array|NotApplicable|null
            {
                if ($this->fault === BrokenContractCase::PLAIN_ANSWER_DOES_NOT_APPLY && $fixture === ContractFixture::PlainAnswer) {
                    return new NotApplicable('this format has no plain answer');
                }

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
