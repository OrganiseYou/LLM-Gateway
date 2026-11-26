<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use Exception;
use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\StructuredIssueKind;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\Contract\Usage;
use PHPUnit\Framework\TestCase;

/**
 * The shared test set: what every gateway must do, written once. An adapter passes it or it is not an adapter.
 *
 * It is a PHPUnit test case, so the project that runs it needs `phpunit/phpunit` among its development packages:
 * the library itself does not require it. It is written for PHPUnit 12, the one Pest 4 brings, and Pest runs it as
 * it is. To prove an adapter, extend it in a test file whose name ends in `Test.php` and write subject():
 *
 *     final class MyFormatContractTest extends ContractTestCase
 *     {
 *         protected function subject(RetryPolicy $retry): ContractSubject
 *         {
 *             return new AdapterSubject(
 *                 MyPresets::myServer(),
 *                 new BearerKey(self::MARKER_KEY),
 *                 new MyFormatFixtures(),      // implements AdapterFixtures
 *                 'my-model',
 *                 $retry,
 *             );
 *         }
 *     }
 *
 * Every test builds its own subject, tells it what the provider does, calls the gateway and checks the result. A
 * scenario the gateway says its model cannot do (capabilities) is skipped with the reason, and the refusal of that
 * request is checked in its place by another test; a fixture that is missing for something the gateway says it can
 * do fails. A server with other capabilities is another test class with another preset.
 *
 * Expect skipped tests, each with its reason: that is the set saying what it did not check, not a fault. A gateway
 * that can do everything and streams natively skips six: the five tests of a refusal (tools, a forced tool call, a
 * response format, tools with a response format, the model list), because there is nothing to refuse, and the
 * test of the stream of a gateway without streaming. A gateway that lacks a capability skips the scenarios that
 * need it instead, and its refusal test runs. Three more can be skipped by what the fixtures declare as not
 * applicable (an error inside a successful response, a request the format cannot carry) or by PHP leaving the
 * arguments out of traces. A project that fails its run on a skipped test should exclude none of them: it will see
 * these skips, and should allow them for the classes that extend this one.
 *
 * What the fixtures must hold is said on ContractFixture and AdapterFixtures. Two things are easy to miss: every
 * fixture reports 3 input and 2 output tokens, and the first chunk of the stream of the plain answer gives its
 * first piece of text. (The subject sends one comment line of its own before the chunks of a fixture.)
 *
 * The traces of exceptions are searched for the key with the arguments of every call in them. Where PHP is set to
 * leave those out (zend.exception_ignore_args, which php.ini-production switches on), every other test gives the
 * verdict it gives elsewhere, and one test is skipped to say that the search saw less: set it to 0 for the tests,
 * with `<ini name="zend.exception_ignore_args" value="0"/>` in phpunit.xml. No other setting of PHP changes a
 * verdict. zend.exception_string_param_max_len, which php.ini-production sets to 0, only shortens the string form of
 * a trace, and every argument that form shows is searched whole in the trace itself.
 */
abstract class ContractTestCase extends TestCase
{
    /** The key every subject signs in with. No output of a gateway may hold it, or the word in its middle. */
    public const string MARKER_KEY = 'sk-test-MARKER-do-not-leak';

    /** The part of the key that is still there when an output form cuts a text short. */
    private const string MARKER_WORD = 'MARKER';

    /**
     * Words a provider repeats in an error where the set looks for them in the detail of the gateway's error. In the
     * form of a provider's own code, so a gateway may show them as that code, and nowhere else.
     */
    public const string PROVIDER_WORDS = 'words_of_the_provider_7391';

    private const string TRACE_ID = 'contract-trace-1';

    private const string TRIP_SCHEMA = '{"type":"object","properties":{"city":{"type":"string"},"days":{"type":"integer"}},"required":["city","days"],"additionalProperties":false}';

    /**
     * A new gateway under test, with what plays its provider and nothing scripted yet.
     *
     * @param RetryPolicy $retry the retry policy the gateway must be built with
     */
    abstract protected function subject(RetryPolicy $retry): ContractSubject;

    /**
     * Whether the gateway signs its requests with a key. Only a gateway for a server without sign-in says false;
     * the set then cannot check that the key stays out of the logged requests, and does not claim to.
     */
    protected function signsIn(): bool
    {
        return true;
    }

    /**
     * Whether the provider of this gateway reports the tokens it used. Only the test of a server that reports
     * none says false, and its fixtures then hold no usage: the set then checks that the gateway reports none
     * either, so the answer is checked both ways.
     *
     * @param bool $inAStream asked for an answer that arrives as a native stream; some servers report usage for a
     *                        whole response and not in a stream
     */
    protected function reportsUsage(bool $inAStream): bool
    {
        return true;
    }

    // ---------------------------------------------------------------------------------------------------------
    // The answer (design section 4, LR-07).
    // ---------------------------------------------------------------------------------------------------------

    public function testAPlainAnswer(): void
    {
        $this->assertAnswered(ContractFixture::PlainAnswer, false);
    }

    public function testAnAnswerHasATraceIdAlsoWhenTheRequestGivesNone(): void
    {
        $subject = $this->subject(RetryPolicy::none());
        $subject->gives(ContractFixture::PlainAnswer, false);

        $answer = $this->answerOf($subject, self::request($subject, ContractFixture::PlainAnswer, null));

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.:-]{1,128}$/D', $answer->traceId);
        self::assertNotSame('not-set', $answer->traceId, 'the trace id of an answer is the one of its call, not a placeholder');
    }

    public function testAToolCall(): void
    {
        $this->assertAnswered(ContractFixture::ToolCall, false);
    }

    public function testTwoToolCallsInOneAnswer(): void
    {
        $this->assertAnswered(ContractFixture::ParallelToolCalls, false);
    }

    public function testAToolCallWithUnreadableArgumentsIsReturnedNotThrown(): void
    {
        $this->assertAnswered(ContractFixture::GarbledToolCall, false);
    }

    public function testAStructuredAnswerThatMatchesItsSchema(): void
    {
        $this->assertAnswered(ContractFixture::StructuredValid, false);
    }

    public function testAStructuredAnswerThatDoesNotMatchIsReturnedWithItsReasons(): void
    {
        $this->assertAnswered(ContractFixture::StructuredInvalid, false);
    }

    public function testAStructuredAnswerCutOffAtTheTokenLimitIsReturnedWithItsReason(): void
    {
        $this->assertAnswered(ContractFixture::CutOff, false);
    }

    public function testAToolCallBesideAResponseFormatHasNoStructuredResult(): void
    {
        $this->assertAnswered(ContractFixture::ToolCallBesideResponseFormat, false);
    }

    public function testAnAnswerWithoutTextAndWithoutAToolCallIsAProviderError(): void
    {
        $subject = $this->subject(new RetryPolicy([1.0], 60.0));
        $subject->gives(ContractFixture::EmptyAnswer, false);
        $request = self::request($subject, ContractFixture::EmptyAnswer);

        $error = $this->errorOf($subject, $request);

        self::assertSame(ErrorReason::ProviderError, $error->reason);
        self::assertSame(self::TRACE_ID, $error->traceId);
        self::assertSame(1, $subject->requestsReceived(), 'an error inside a successful response is not tried again');

        $streamed = $this->subject(RetryPolicy::none());
        $streamed->gives(ContractFixture::EmptyAnswer, true);
        $events = $this->eventsOf($streamed, $request);

        self::assertSame(ErrorReason::ProviderError, $this->failedIn($events)->error->reason);
    }

    public function testAResponseSchemaThatCannotBeUsedIsRejectedWithTheTraceIdOfTheCall(): void
    {
        $subject = $this->subject(RetryPolicy::none());
        $this->skipWhatTheGatewayCannotDo($subject, ContractFixture::StructuredValid);
        $subject->gives(ContractFixture::StructuredValid, false);

        $broken = new ResponseFormat(JsonSchema::fromJson('{"type":"object","properties":{"city":{"type":[null]}}}'), 'trip');
        $request = self::request($subject, ContractFixture::PlainAnswer)->withResponseFormat($broken);

        $error = $this->errorOf($subject, $request);

        self::assertSame(ErrorReason::Rejected, $error->reason);
        self::assertSame(self::TRACE_ID, $error->traceId, 'an error of the structured check carries the trace id of the call');
    }

    // ---------------------------------------------------------------------------------------------------------
    // The stream (design section 6, LR-12, LR-35, G25).
    // ---------------------------------------------------------------------------------------------------------

    public function testAStreamOfAPlainAnswer(): void
    {
        $this->assertAnswered(ContractFixture::PlainAnswer, true);
    }

    public function testAStreamOfAToolCall(): void
    {
        $this->assertAnswered(ContractFixture::ToolCall, true);
    }

    public function testAStreamOfTwoToolCalls(): void
    {
        $this->assertAnswered(ContractFixture::ParallelToolCalls, true);
    }

    public function testAStreamOfAToolCallWithUnreadableArguments(): void
    {
        $this->assertAnswered(ContractFixture::GarbledToolCall, true);
    }

    public function testAStreamOfAStructuredAnswerThatMatches(): void
    {
        $this->assertAnswered(ContractFixture::StructuredValid, true);
    }

    public function testAStreamOfAStructuredAnswerThatDoesNotMatch(): void
    {
        $this->assertAnswered(ContractFixture::StructuredInvalid, true);
    }

    public function testAStreamOfAStructuredAnswerCutOffAtTheTokenLimit(): void
    {
        $this->assertAnswered(ContractFixture::CutOff, true);
    }

    public function testAStreamOfAToolCallBesideAResponseFormat(): void
    {
        $this->assertAnswered(ContractFixture::ToolCallBesideResponseFormat, true);
    }

    public function testNothingIsSentBeforeAStreamIsRead(): void
    {
        $subject = $this->subject(RetryPolicy::none());
        $subject->gives(ContractFixture::PlainAnswer, true);

        $stream = $subject->gateway()->stream(self::request($subject, ContractFixture::PlainAnswer));

        self::assertSame(0, $subject->requestsReceived(), 'stream() must send nothing until the caller reads its first event');

        $events = [];
        foreach ($stream as $event) {
            $events[] = $event;
        }

        self::assertSame(1, $subject->requestsReceived());
        self::assertInstanceOf(StreamFinished::class, $events[count($events) - 1] ?? null);
    }

    public function testAGatewayWithoutStreamingGivesTheWholeAnswerAsTheEventsOfAStream(): void
    {
        $subject = $this->subject(RetryPolicy::none());
        $can = self::can($subject);
        if ($can->streaming !== Support::Emulated) {
            self::markTestSkipped('The gateway says it streams natively: the stream of one text event is not its way.');
        }

        $subject->gives(ContractFixture::PlainAnswer, true);
        $plain = $this->eventsOf($subject, self::request($subject, ContractFixture::PlainAnswer));
        $usage = $this->finishedIn($plain)->answer->usage;
        $known = $usage->inputTokens !== null || $usage->outputTokens !== null;

        self::assertSame(
            $known ? [TextDelta::class, UsageReported::class, StreamFinished::class] : [TextDelta::class, StreamFinished::class],
            array_map(static fn(StreamEvent $event): string => $event::class, $plain),
            'one text event, the usage when it is known, the end',
        );

        if ($can->toolCalling === Support::None) {
            return;
        }

        $withCall = $this->subject(RetryPolicy::none());
        $withCall->gives(ContractFixture::ToolCall, true);
        $kinds = array_map(
            static fn(StreamEvent $event): string => $event::class,
            $this->eventsOf($withCall, self::request($withCall, ContractFixture::ToolCall)),
        );
        $kinds = array_values(array_filter($kinds, static fn(string $kind): bool => $kind !== TextDelta::class && $kind !== UsageReported::class));

        self::assertSame([ToolCallStarted::class, ToolCallCompleted::class, StreamFinished::class], $kinds);
    }

    public function testEveryFailureOfAStreamIsItsOneAndLastEvent(): void
    {
        foreach (ContractFailure::cases() as $failure) {
            $subject = $this->subject(RetryPolicy::none());
            if ($subject->cannotPlay($failure, true) !== null) {
                continue;
            }
            $subject->fails($failure, null, true);

            $events = $this->eventsOf($subject, self::request($subject, ContractFixture::PlainAnswer));
            $failed = $this->failedIn($events);

            self::assertCount(1, $events, $failure->value . ': a stream that fails before its first event gives the failure and nothing else');
            self::assertSame($failure->reason(), $failed->error->reason, $failure->value);
            self::assertSame($failure->httpStatus(), $failed->error->httpStatus, $failure->value . ': the status of the response, or none when no response arrived');
            self::assertSame(1, $failed->error->attempts, $failure->value . ': one attempt was made');
            self::assertSame(1, $subject->requestsReceived(), $failure->value . ': one request was sent');
            self::assertSame(self::TRACE_ID, $failed->error->traceId, $failure->value);
            self::assertSame('', $failed->textSoFar, $failure->value);
            self::assertSame([], $failed->toolCallsSoFar, $failure->value);
            $this->assertCodeIsNotTheKey($failed->error, $failure->value);
        }
    }

    public function testAStreamThatBreaksAfterItsFirstEventIsNotTriedAgain(): void
    {
        $subject = $this->subject(new RetryPolicy([1.0], 60.0));
        if (self::can($subject)->streaming !== Support::Native) {
            self::markTestSkipped('The gateway says it does not stream natively: its answer arrives whole, so no stream can break half-way.');
        }

        $subject->breaksAfterTheFirstText();
        $events = $this->eventsOf($subject, self::request($subject, ContractFixture::PlainAnswer));
        $failed = $this->failedIn($events);

        $text = '';
        foreach ($events as $event) {
            if ($event instanceof TextDelta) {
                $text .= $event->text;
            }
        }

        self::assertNotSame('', $text, 'the first chunk of the stream of the plain answer must give a piece of text');
        self::assertSame($text, $failed->textSoFar, 'the failed event carries the text that arrived');
        self::assertSame(ErrorReason::Unavailable, $failed->error->reason);
        self::assertSame(self::TRACE_ID, $failed->error->traceId);
        self::assertSame(1, $subject->requestsReceived(), 'a stream that gave an event is never tried again');
        self::assertSame([], $subject->waits());
    }

    // ---------------------------------------------------------------------------------------------------------
    // From a failure to its reason (design section 7.3, LR-32).
    // ---------------------------------------------------------------------------------------------------------

    public function testEveryFailureHasItsReason(): void
    {
        foreach (ContractFailure::cases() as $failure) {
            $subject = $this->subject(RetryPolicy::none());
            if ($subject->cannotPlay($failure, false) !== null) {
                continue;
            }
            $subject->fails($failure);

            $error = $this->errorOf($subject, self::request($subject, ContractFixture::PlainAnswer));
            $this->assertCodeIsNotTheKey($error, $failure->value);
            self::assertFalse($subject->sentToTheProvider(self::TRACE_ID), $failure->value . ': the trace id is not sent to the provider');

            self::assertSame($failure->reason(), $error->reason, $failure->value);
            self::assertSame($failure->httpStatus(), $error->httpStatus, $failure->value);
            self::assertSame(self::TRACE_ID, $error->traceId, $failure->value);
            self::assertSame(1, $error->attempts, $failure->value);
            self::assertSame(1, $subject->requestsReceived(), $failure->value);
            if ($error->retryAfterSeconds !== null) {
                self::assertContains($error->reason, [ErrorReason::RateLimited, ErrorReason::Unavailable], $failure->value);
            }
        }
    }

    public function testToolsAreRefusedWhenTheModelCannotCallTools(): void
    {
        $subject = $this->subject(RetryPolicy::none());
        if (self::can($subject)->toolCalling !== Support::None) {
            self::markTestSkipped('The gateway says this model can call tools: there is nothing to refuse.');
        }

        $this->assertRefused($subject, self::request($subject, ContractFixture::ToolCall));
    }

    public function testAForcedToolCallIsRefusedWhenTheModelCannotBeForced(): void
    {
        $subject = $this->subject(RetryPolicy::none());
        if (self::can($subject)->forcedToolCall !== Support::None) {
            self::markTestSkipped('The gateway says this model can be made to call one named tool: there is nothing to refuse.');
        }

        $this->assertRefused($subject, self::request($subject, ContractFixture::ToolCall)->withToolChoice(ToolChoice::tool('lookup')));
    }

    public function testAResponseFormatIsRefusedWhenTheModelCannotGiveAStructuredAnswer(): void
    {
        $subject = $this->subject(RetryPolicy::none());
        if (self::can($subject)->structuredOutput !== Support::None) {
            self::markTestSkipped('The gateway says this model can give a structured answer: there is nothing to refuse.');
        }

        $this->assertRefused($subject, self::request($subject, ContractFixture::StructuredValid));
    }

    public function testToolsWithAResponseFormatAreRefusedWhenTheModelCannotTakeBoth(): void
    {
        $subject = $this->subject(RetryPolicy::none());
        if (self::can($subject)->toolsWithStructuredOutput !== Support::None) {
            self::markTestSkipped('The gateway says this model can take tools and a response format together: there is nothing to refuse.');
        }

        $this->assertRefused($subject, self::request($subject, ContractFixture::ToolCallBesideResponseFormat));
    }

    public function testTheModelListIsRefusedWhenTheModelsCannotBeListed(): void
    {
        $subject = $this->subject(RetryPolicy::none());
        if (self::can($subject)->modelListing !== Support::None) {
            self::markTestSkipped('The gateway says its models can be listed: there is nothing to refuse.');
        }

        try {
            $subject->gateway()->models(10.0, self::TRACE_ID);
            self::fail('models() must be refused as not_supported when the capabilities say the models cannot be listed.');
        } catch (GatewayException $error) {
            self::assertSame(ErrorReason::NotSupported, $error->reason);
            self::assertSame(self::TRACE_ID, $error->traceId);
            self::assertSame(0, $error->attempts);
            self::assertSame(0, $subject->requestsReceived());
        }
    }

    public function testCapabilitiesSendNothingAndAMalformedArgumentIsNoGatewayError(): void
    {
        $subject = $this->subject(RetryPolicy::none());
        $gateway = $subject->gateway();

        self::assertEquals($gateway->capabilities($subject->model()), $gateway->capabilities($subject->model()));
        self::assertSame(0, $subject->requestsReceived(), 'capabilities() sends no request');

        $refused = 0;
        try {
            $gateway->capabilities('');
        } catch (InvalidArgumentException) {
            $refused++;
        }
        try {
            $gateway->models(0.0);
        } catch (InvalidArgumentException) {
            $refused++;
        }
        try {
            $gateway->models(10.0, 'no trace id');
        } catch (InvalidArgumentException) {
            $refused++;
        }

        self::assertSame(3, $refused, 'an empty model name, a timeout of 0 and a malformed trace id are each an InvalidArgumentException');
        self::assertSame(0, $subject->requestsReceived());
    }

    public function testAnErrorInsideASuccessfulResponseIsAProviderErrorWithoutTheWordsOfTheProvider(): void
    {
        $subject = $this->subject(new RetryPolicy([1.0], 60.0));
        $why = $subject->cannotPlay(ContractFailure::ErrorInBody, false);
        if ($why !== null) {
            self::markTestSkipped('The fixtures say an error inside a successful response does not apply: ' . $why);
        }

        $subject->fails(ContractFailure::ErrorInBody, null, false, self::PROVIDER_WORDS);
        $request = self::request($subject, ContractFixture::PlainAnswer);
        $error = $this->errorOf($subject, $request);

        self::assertSame(ErrorReason::ProviderError, $error->reason);
        self::assertSame(200, $error->httpStatus);
        self::assertSame(1, $error->attempts);
        self::assertSame(1, $subject->requestsReceived(), 'an error inside a successful response is not tried again');
        $this->assertOnlyTheCodeIsTheProviders($error);

        $streamed = $this->subject(RetryPolicy::none());
        if ($streamed->cannotPlay(ContractFailure::ErrorInBody, true) !== null) {
            return;
        }
        $streamed->fails(ContractFailure::ErrorInBody, null, true, self::PROVIDER_WORDS);
        $failed = $this->failedIn($this->eventsOf($streamed, $request));

        self::assertSame(ErrorReason::ProviderError, $failed->error->reason);
        $this->assertOnlyTheCodeIsTheProviders($failed->error);
    }

    public function testTheCodeOfAnErrorIsTheProvidersOwnAndItsDetailIsNotTheProvidersWords(): void
    {
        foreach ([ContractFailure::Status400, ContractFailure::Status401, ContractFailure::Status429, ContractFailure::Status500] as $failure) {
            $subject = $this->subject(RetryPolicy::none());
            $subject->fails($failure, null, false, self::PROVIDER_WORDS);

            $error = $this->errorOf($subject, self::request($subject, ContractFixture::PlainAnswer));

            self::assertSame($failure->reason(), $error->reason, $failure->value);
            $this->assertOnlyTheCodeIsTheProviders($error);
        }
    }

    public function testARequestTheFormatCannotCarryIsRejectedBeforeAnythingIsSent(): void
    {
        $subject = $this->subject(RetryPolicy::none());
        if (self::can($subject)->toolCalling === Support::None) {
            $request = $subject->requestItCannotCarry(self::request($subject, ContractFixture::PlainAnswer));
        } else {
            $request = $subject->requestItCannotCarry(self::request($subject, ContractFixture::ToolCall));
        }
        if ($request instanceof NotApplicable) {
            self::markTestSkipped('The fixtures say no request exists that the format cannot carry: ' . $request->reason);
        }

        // Scripted and never used: a gateway that sends the request gets an answer, and the test says so.
        $subject->gives(ContractFixture::PlainAnswer, false);
        $error = $this->errorOf($subject, $request);

        self::assertSame(ErrorReason::Rejected, $error->reason);
        self::assertSame(self::TRACE_ID, $error->traceId);
        self::assertSame(0, $error->attempts);
        self::assertNull($error->httpStatus);
        self::assertSame(0, $subject->requestsReceived(), 'a request the format cannot carry is refused before anything is sent');

        $streamed = $this->subject(RetryPolicy::none());
        $streamed->gives(ContractFixture::PlainAnswer, true);
        $events = $this->eventsOf($streamed, $request);

        self::assertCount(1, $events);
        self::assertSame(ErrorReason::Rejected, $this->failedIn($events)->error->reason);
        self::assertSame(0, $streamed->requestsReceived());
    }

    // ---------------------------------------------------------------------------------------------------------
    // Retries and waits (design section 8.2, LR-34).
    // ---------------------------------------------------------------------------------------------------------

    public function testOnlyTheFailuresThatMayBeRetriedAreTriedAgain(): void
    {
        $this->skipWithoutAttempts();

        foreach (ContractFailure::cases() as $failure) {
            $subject = $this->subject(new RetryPolicy([1.0], 60.0));
            $request = self::request($subject, ContractFixture::PlainAnswer);

            // The same table through stream(): until its first event a stream is retried as a completion is.
            $streamed = $this->subject(new RetryPolicy([1.0], 60.0));
            if ($streamed->cannotPlay($failure, true) === null) {
                $streamed->fails($failure, null, true);
                $streamed->gives(ContractFixture::PlainAnswer, true);
                $events = $this->eventsOf($streamed, $request);

                if ($failure->isRetried()) {
                    self::assertSame(2, $this->finishedIn($events)->answer->attempts, $failure->value . ', as a stream: the answer counts both attempts');
                    self::assertSame(2, $streamed->requestsReceived(), $failure->value . ', as a stream');
                    self::assertSame([1.0], $streamed->waits(), $failure->value . ', as a stream');
                } else {
                    $failed = $this->failedIn($events);

                    self::assertSame($failure->reason(), $failed->error->reason, $failure->value . ', as a stream');
                    self::assertSame(1, $failed->error->attempts, $failure->value . ', as a stream, is not tried again');
                    self::assertSame(1, $streamed->requestsReceived(), $failure->value . ', as a stream, is not tried again: no second request');
                    self::assertSame([], $streamed->waits(), $failure->value . ', as a stream');
                }
            }

            if ($subject->cannotPlay($failure, false) !== null) {
                continue;
            }
            $subject->fails($failure);
            $subject->gives(ContractFixture::PlainAnswer, false);

            if ($failure->isRetried()) {
                $answer = $this->answerOf($subject, $request);

                self::assertSame(2, $answer->attempts, $failure->value . ': the answer counts both attempts');
                self::assertSame(2, $subject->requestsReceived(), $failure->value);
                self::assertSame([1.0], $subject->waits(), $failure->value . ': the wait of the retry policy');
                self::assertGreaterThanOrEqual(1000, $answer->latencyMs, $failure->value . ': the latency covers every attempt and every wait');

                continue;
            }

            $error = $this->errorOf($subject, $request);

            self::assertSame($failure->reason(), $error->reason, $failure->value);
            self::assertSame(1, $error->attempts, $failure->value . ' is not tried again');
            self::assertSame(1, $subject->requestsReceived(), $failure->value . ' is not tried again');
            self::assertSame([], $subject->waits(), $failure->value);
        }
    }

    public function testAStreamIsTriedAgainUntilItsFirstEvent(): void
    {
        $this->skipWithoutAttempts();

        $subject = $this->subject(new RetryPolicy([1.0], 60.0));
        $subject->fails(ContractFailure::Status503);
        $subject->gives(ContractFixture::PlainAnswer, true);

        $answer = $this->finishedIn($this->eventsOf($subject, self::request($subject, ContractFixture::PlainAnswer)))->answer;

        self::assertSame(2, $answer->attempts);
        self::assertSame([1.0], $subject->waits());
    }

    public function testTheWaitTheProviderAsksForIsKept(): void
    {
        $this->skipWithoutAttempts();

        $subject = $this->subject(new RetryPolicy([1.0], 60.0));
        $subject->fails(ContractFailure::Status429, 7);
        $subject->gives(ContractFixture::PlainAnswer, false);

        $answer = $this->answerOf($subject, self::request($subject, ContractFixture::PlainAnswer));

        self::assertSame([7.0], $subject->waits(), 'a wait the provider tells, within the maximum, replaces the wait of the policy');
        self::assertSame(2, $answer->attempts);
        self::assertGreaterThanOrEqual(7000, $answer->latencyMs);
    }

    public function testAWaitAboveTheMaximumFailsAtOnceAndTellsTheWait(): void
    {
        $this->skipWithoutAttempts();

        $subject = $this->subject(new RetryPolicy([1.0, 1.0], 60.0));
        $subject->fails(ContractFailure::Status429, 120);

        $error = $this->errorOf($subject, self::request($subject, ContractFixture::PlainAnswer));

        self::assertSame(ErrorReason::RateLimited, $error->reason);
        self::assertSame(120, $error->retryAfterSeconds);
        self::assertSame(1, $error->attempts);
        self::assertSame([], $subject->waits());
    }

    public function testTheLastFailureIsTheErrorWhenNoRetryIsLeft(): void
    {
        $this->skipWithoutAttempts();

        $subject = $this->subject(new RetryPolicy([1.0], 60.0));
        $subject->fails(ContractFailure::Status503);
        $subject->fails(ContractFailure::Status429);

        $error = $this->errorOf($subject, self::request($subject, ContractFixture::PlainAnswer));

        self::assertSame(ErrorReason::RateLimited, $error->reason, 'the error of the last attempt');
        self::assertSame(429, $error->httpStatus);
        self::assertSame(2, $error->attempts);
        self::assertSame([1.0], $subject->waits());
    }

    public function testNoRetryWaitsPastTheDeadlineOfTheCall(): void
    {
        $this->skipWithoutAttempts();

        $subject = $this->subject(new RetryPolicy([10.0], 60.0));
        $subject->fails(ContractFailure::Status503);

        $error = $this->errorOf($subject, self::request($subject, ContractFixture::PlainAnswer, self::TRACE_ID, 5.0));

        self::assertSame(ErrorReason::Unavailable, $error->reason);
        self::assertSame(1, $error->attempts);
        self::assertSame([], $subject->waits(), 'a wait that would end after the deadline is not started');
    }

    // ---------------------------------------------------------------------------------------------------------
    // The timeout (design section 8.3, LR-36).
    // ---------------------------------------------------------------------------------------------------------

    public function testEveryAttemptGetsTheTimeThatIsLeft(): void
    {
        $this->skipWithoutAttempts();

        $subject = $this->subject(new RetryPolicy([4.0], 60.0));
        $subject->fails(ContractFailure::Status503);
        $subject->gives(ContractFixture::PlainAnswer, false);

        $this->answerOf($subject, self::request($subject, ContractFixture::PlainAnswer));
        $given = $subject->timeoutsGiven();

        self::assertCount(2, $given);
        self::assertEqualsWithDelta(30.0, $given[0], 0.001, 'the first attempt gets the timeout of the call');
        self::assertEqualsWithDelta(26.0, $given[1], 0.001, 'the second gets what the wait left of it');
    }

    public function testAResponseThatArrivesLateIsUsed(): void
    {
        $this->skipWithoutAttempts();

        $subject = $this->subject(RetryPolicy::none());
        $subject->delays(40.0);
        $subject->gives(ContractFixture::PlainAnswer, false);

        $answer = $this->answerOf($subject, self::request($subject, ContractFixture::PlainAnswer));

        self::assertSame('Hello.', $answer->text);
        self::assertGreaterThanOrEqual(40000, $answer->latencyMs);
    }

    public function testAStreamPastTheDeadlineOfTheCallEndsAsATimeout(): void
    {
        $this->skipWithoutAttempts();

        $subject = $this->subject(new RetryPolicy([1.0], 60.0));
        if (self::can($subject)->streaming !== Support::Native) {
            self::markTestSkipped('The gateway says it does not stream natively: its answer arrives whole, and a response that arrived is used.');
        }

        $subject->delays(40.0);
        $subject->gives(ContractFixture::PlainAnswer, true);

        $failed = $this->failedIn($this->eventsOf($subject, self::request($subject, ContractFixture::PlainAnswer)));

        self::assertSame(ErrorReason::Timeout, $failed->error->reason);
        self::assertSame(1, $subject->requestsReceived(), 'a call that ran out of time is not tried again');
    }

    // ---------------------------------------------------------------------------------------------------------
    // No key in any output (design section 11, LR-26).
    // ---------------------------------------------------------------------------------------------------------

    public function testNoKeyInAnAnswerOrInTheEventsOfAStream(): void
    {
        $subject = $this->subject(new RetryPolicy([1.0], 60.0));
        if ($subject->makesAttempts()) {
            // The first response is a failure that repeats the key: nothing of it may stay in the answer after it.
            $subject->fails(ContractFailure::Status503);
        }
        $subject->gives(ContractFixture::PlainAnswer, false);
        $request = self::request($subject, ContractFixture::PlainAnswer);

        $this->assertNoKeyIn(OutputForms::ofValue($this->answerOf($subject, $request)), 'the answer');
        $this->assertSignedInAndNothingLogged($subject);

        $streamed = $this->subject(RetryPolicy::none());
        $streamed->gives(ContractFixture::PlainAnswer, true);
        foreach ($this->eventsOf($streamed, $request) as $at => $event) {
            $this->assertNoKeyIn(OutputForms::ofValue($event), 'event ' . $at . ' of the stream');
        }
        $this->assertSignedInAndNothingLogged($streamed);
    }

    public function testNoKeyInAnyErrorOrItsTrace(): void
    {
        foreach (ContractFailure::cases() as $failure) {
            $subject = $this->subject(new RetryPolicy([1.0], 60.0));
            $request = self::request($subject, ContractFixture::PlainAnswer);

            if ($subject->cannotPlay($failure, false) === null) {
                $subject->fails($failure);
                $subject->fails($failure);

                $error = $this->errorOf($subject, $request);

                $this->assertNoKeyIn(OutputForms::ofException($error), 'the error of ' . $failure->value);
                $this->assertNoKeyIn(OutputForms::ofValue($error), 'the error of ' . $failure->value . ', as a value');
                $this->assertSignedInAndNothingLogged($subject);
            }

            $streamed = $this->subject(RetryPolicy::none());
            if ($streamed->cannotPlay($failure, true) !== null) {
                continue;
            }
            $streamed->fails($failure, null, true);
            foreach ($this->eventsOf($streamed, $request) as $event) {
                $this->assertNoKeyIn(OutputForms::ofValue($event), 'the failed event of a stream with ' . $failure->value);
                if ($event instanceof StreamFailed) {
                    $this->assertNoKeyIn(OutputForms::ofException($event->error), 'the error in the failed event of a stream with ' . $failure->value);
                }
            }
            $this->assertSignedInAndNothingLogged($streamed);
        }
    }

    public function testTheTracesAreSearchedWithTheArgumentsOfEveryCall(): void
    {
        if (! self::traceKeepsArguments(self::MARKER_WORD)) {
            self::markTestSkipped(
                'PHP leaves the arguments of calls out of the traces of exceptions here (zend.exception_ignore_args, which '
                . 'php.ini-production switches on), so the search of the traces for the key saw less than a host with the '
                . 'default setting would show. Set it to 0 for the tests: <ini name="zend.exception_ignore_args" value="0"/> '
                . 'under <php> in phpunit.xml.',
            );
        }

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------------------------------------------
    // The steps the tests share.
    // ---------------------------------------------------------------------------------------------------------

    /**
     * The gateway gives the answer of the fixture, through complete() or as a stream, and it is the same answer.
     */
    private function assertAnswered(ContractFixture $fixture, bool $asStream): void
    {
        $subject = $this->subject(RetryPolicy::none());
        $this->skipWhatTheGatewayCannotDo($subject, $fixture);

        $subject->gives($fixture, $asStream);
        $request = self::request($subject, $fixture);
        $what = $fixture->value;

        // Design section 4.2: the tokens the provider reported, or none where it reports none.
        $usage = $this->reportsUsage($asStream && self::can($subject)->streaming === Support::Native)
            ? new Usage(ContractFixture::INPUT_TOKENS, ContractFixture::OUTPUT_TOKENS)
            : new Usage(null, null);

        if ($asStream) {
            $events = $this->eventsOf($subject, $request);
            $answer = $this->finishedIn($events)->answer;
            $reports = $this->assertStreamKeepsItsRules($events, $answer);
            self::assertSame($usage->inputTokens === null ? 0 : 1, $reports, $what . ': a stream reports the usage once when the provider tells it, and never otherwise');
        } else {
            $answer = $this->answerOf($subject, $request);
        }

        self::assertEquals($usage, $answer->usage, $what . ': the usage the provider reported');
        // Design section 8.5.
        self::assertFalse($subject->sentToTheProvider(self::TRACE_ID), $what . ': the trace id is not sent to the provider');

        // Design section 4.4.
        self::assertSame(self::TRACE_ID, $answer->traceId, $what . ': the trace id of the request');
        self::assertNotSame('', $answer->model, $what);
        self::assertSame($fixture->finishReason(), $answer->finishReason, $what);
        self::assertGreaterThanOrEqual(0, $answer->latencyMs, $what);
        self::assertSame(1, $answer->attempts, $what . ': one attempt');
        self::assertSame(1, $subject->requestsReceived(), $what . ': one request');
        if ($fixture->text() !== null) {
            self::assertSame($fixture->text(), $answer->text, $what);
        }

        // Design section 3.6: the tool calls, in the order of the answer, readable or with the reason they are not.
        $expected = $fixture->toolCalls();
        self::assertCount(count($expected), $answer->toolCalls, $what);
        $ids = [];
        foreach ($expected as $at => $call) {
            $given = $answer->toolCalls[$at];
            $ids[$given->id] = true;

            self::assertNotSame('', $given->id, $what);
            self::assertSame($call->name, $given->name, $what);
            self::assertSame($call->isReadable(), $given->isReadable(), $what . ': unreadable arguments are a value, not an exception');
            self::assertSame($call->arguments, $given->arguments, $what);
            if (! $call->isReadable()) {
                self::assertNotSame('', (string) $given->argumentsError, $what . ': why the arguments could not be read');
                self::assertNotSame('', $given->rawArguments, $what . ': the arguments as they arrived');
            }
        }
        self::assertCount(count($expected), $ids, $what . ': every tool call has its own id');
        if ($expected !== []) {
            self::assertCount(count($expected), $answer->toMessage()->toolCalls, $what . ': the answer can go back as the assistant message of the next round');
        }

        // Design sections 4.3 and 10, with the rule that an answer with tool calls has no structured result.
        if (! $fixture->needsResponseFormat() || $expected !== []) {
            self::assertNull($answer->structured, $what . ': no structured result without a response format, and none for an answer that holds tool calls');

            return;
        }

        $structured = $answer->structured;
        self::assertNotNull($structured, $what . ': a request with a response format gets a structured result');

        if ($fixture === ContractFixture::StructuredValid) {
            self::assertSame([], $structured->reasons(), $what);
            self::assertTrue($structured->isUsable(), $what);
            self::assertSame(['city' => 'Paris', 'days' => 3], $structured->data, $what);

            return;
        }

        self::assertFalse($structured->isUsable(), $what . ': an unusable answer is returned with its reasons');
        self::assertNotSame([], $structured->reasons(), $what);

        if ($fixture === ContractFixture::StructuredInvalid) {
            self::assertSame(['city' => 'Paris', 'days' => 'three'], $structured->data, $what . ': the decoded answer is kept');
            self::assertSame(StructuredIssueKind::SchemaMismatch, $structured->issues[0]->kind, $what);
            self::assertSame('/days', $structured->issues[0]->path, $what);
        } else {
            self::assertSame(StructuredIssueKind::CutOff, $structured->issues[0]->kind, $what);
            self::assertSame(FinishReason::Length, $answer->finishReason, $what);
        }
    }

    /**
     * The rules of design section 6 that hold for every stream that ends with an answer.
     *
     * @param list<StreamEvent> $events
     */
    private function assertStreamKeepsItsRules(array $events, Answer $answer): int
    {
        $text = '';
        $open = [];
        $completed = [];
        $usageReports = 0;

        foreach ($events as $at => $event) {
            if ($at < count($events) - 1) {
                self::assertNotInstanceOf(StreamFinished::class, $event, 'rule 1: the finished event is the last event, and there is one');
                self::assertNotInstanceOf(StreamFailed::class, $event, 'rule 1: a stream ends with one finished or one failed event');
            }
            if ($event instanceof TextDelta) {
                $text .= $event->text;
            } elseif ($event instanceof ToolCallStarted) {
                self::assertArrayNotHasKey($event->index, $open, 'a tool call starts once');
                $open[$event->index] = $event;
            } elseif ($event instanceof ToolCallCompleted) {
                self::assertArrayHasKey($event->index, $open, 'rule 2: a tool call completes after it started, with the same index');
                self::assertSame($open[$event->index]->id, $event->toolCall->id, 'the call that completes is the call that started');
                self::assertSame($open[$event->index]->name, $event->toolCall->name, 'the call that completes has the tool name it started with');
                unset($open[$event->index]);
                $completed[] = $event->toolCall;
            } elseif ($event instanceof UsageReported) {
                $usageReports++;
                self::assertEquals($answer->usage, $event->usage, 'the usage that was reported is the usage of the answer');
            }
        }

        self::assertSame([], array_keys($open), 'rule 2: every tool call that started completes before the stream is finished');
        self::assertSame($answer->text, $text, 'rule 3: the text of the text deltas, joined, is the text of the answer');
        self::assertEquals($answer->toolCalls, $completed, 'the tool calls of the answer are the calls that completed, in that order');
        self::assertLessThanOrEqual(1, $usageReports, 'the usage is reported at most once');

        return $usageReports;
    }

    /**
     * The provider repeated the key of the set as its own code: an error may not show it, in any case of letters.
     */
    private function assertCodeIsNotTheKey(GatewayException $error, string $what): void
    {
        if ($this->signsIn()) {
            self::assertNull($error->providerCode, $what . ': the only code the provider gave is the key, so the error has no code; any other is invented');
        }
    }

    /**
     * The provider repeated PROVIDER_WORDS as its message and as its own code. The error may show them as the
     * code and nowhere else: its detail is a sentence of the library.
     */
    private function assertOnlyTheCodeIsTheProviders(GatewayException $error): void
    {
        self::assertContains($error->providerCode, [null, self::PROVIDER_WORDS], 'the code of an error is the one the provider gave, or none');
        self::assertFalse(
            stripos((string) $error->detail, self::PROVIDER_WORDS),
            'the detail of an error is a sentence the library wrote: never words of the provider',
        );
        self::assertFalse(stripos((string) preg_replace('/code: [a-z0-9_.-]+/', '', $error->getMessage()), self::PROVIDER_WORDS));
    }

    /**
     * The request is refused as not supported before anything is sent, by complete() and by stream().
     */
    private function assertRefused(ContractSubject $subject, Request $request): void
    {
        $error = $this->errorOf($subject, $request);

        self::assertSame(ErrorReason::NotSupported, $error->reason);
        self::assertSame(self::TRACE_ID, $error->traceId);
        self::assertSame(0, $error->attempts);
        self::assertNull($error->httpStatus);

        $events = $this->eventsOf($subject, $request);

        self::assertCount(1, $events);
        self::assertSame(ErrorReason::NotSupported, $this->failedIn($events)->error->reason);
        self::assertSame(0, $subject->requestsReceived(), 'a request the model cannot do is refused before anything is sent');
    }

    /**
     * @param array<string, string> $forms
     */
    private function assertNoKeyIn(array $forms, string $what): void
    {
        self::assertNotSame([], $forms);
        self::assertSame([], OutputForms::holding($forms, self::MARKER_WORD), $what . ' shows the key the gateway signed in with');
    }

    private function assertSignedInAndNothingLogged(ContractSubject $subject): void
    {
        if (! $subject->makesAttempts()) {
            return;
        }
        if ($this->signsIn()) {
            self::assertTrue(
                $subject->signedInWith(self::MARKER_KEY),
                'The gateway must sign in with ContractTestCase::MARKER_KEY in a secret header: without it this test proves nothing.',
            );
        }

        $this->assertNoKeyIn($subject->logged(), 'a request in the report of an attempt');
    }

    private function skipWithoutAttempts(): void
    {
        if (! $this->subject(RetryPolicy::none())->makesAttempts()) {
            self::markTestSkipped('The gateway makes no HTTP attempts: it has nothing to retry, no wait and no time that runs out.');
        }
    }

    private function skipWhatTheGatewayCannotDo(ContractSubject $subject, ContractFixture $fixture): void
    {
        $can = self::can($subject);

        if ($fixture->needsTools() && $can->toolCalling === Support::None) {
            self::markTestSkipped('The gateway says this model cannot call tools. That it refuses them is checked by another test.');
        }
        if ($fixture === ContractFixture::ParallelToolCalls && $can->parallelToolCalls === Support::None) {
            self::markTestSkipped('The gateway says one answer of this model cannot hold several tool calls.');
        }
        if ($fixture->needsResponseFormat() && $can->structuredOutput === Support::None) {
            self::markTestSkipped('The gateway says this model cannot give a structured answer. That it refuses a response format is checked by another test.');
        }
        if ($fixture->needsTools() && $fixture->needsResponseFormat() && $can->toolsWithStructuredOutput === Support::None) {
            self::markTestSkipped('The gateway says this model cannot take tools and a response format together. That it refuses them is checked by another test.');
        }
    }

    /**
     * The answer of the call. A gateway error fails the test: the scenario expects an answer.
     */
    private function answerOf(ContractSubject $subject, Request $request): Answer
    {
        try {
            return $subject->gateway()->complete($request);
        } catch (GatewayException $error) {
            self::fail('The call failed as ' . $error->reason->value . ' where an answer must be returned: ' . self::safe($error));
        }
    }

    /**
     * The gateway error of the call. An answer fails the test: the scenario expects an error.
     */
    private function errorOf(ContractSubject $subject, Request $request): GatewayException
    {
        try {
            $subject->gateway()->complete($request);
        } catch (GatewayException $error) {
            return $error;
        }

        self::fail('The call gave an answer where it must fail with a gateway error.');
    }

    /**
     * The stream of the call, read to its end. A gateway error thrown from it fails the test: a stream fails by
     * its last event.
     *
     * @return list<StreamEvent>
     */
    private function eventsOf(ContractSubject $subject, Request $request): array
    {
        $events = [];
        try {
            foreach ($subject->gateway()->stream($request) as $key => $event) {
                self::assertSame(count($events), $key, 'the keys of the events count up from 0');
                self::assertInstanceOf(StreamEvent::class, $event);
                $events[] = $event;
            }
        } catch (GatewayException $error) {
            self::fail('stream() threw a gateway error (' . $error->reason->value . '): every failure of a stream must be its last event.');
        }

        self::assertNotSame([], $events, 'a stream ends with a finished or a failed event');

        return $events;
    }

    /**
     * @param list<StreamEvent> $events
     */
    private function finishedIn(array $events): StreamFinished
    {
        $last = $events[count($events) - 1] ?? null;
        if ($last instanceof StreamFailed) {
            self::fail('The stream failed as ' . $last->error->reason->value . ' where it must end with an answer: ' . self::safe($last->error));
        }
        self::assertInstanceOf(StreamFinished::class, $last, 'a stream ends with a finished or a failed event');

        return $last;
    }

    /**
     * @param list<StreamEvent> $events
     */
    private function failedIn(array $events): StreamFailed
    {
        $last = $events[count($events) - 1] ?? null;
        self::assertInstanceOf(StreamFailed::class, $last, 'the stream must end with a failed event');
        foreach (array_slice($events, 0, -1) as $event) {
            self::assertNotInstanceOf(StreamFinished::class, $event);
            self::assertNotInstanceOf(StreamFailed::class, $event);
        }

        return $last;
    }

    private static function can(ContractSubject $subject): Capabilities
    {
        return $subject->gateway()->capabilities($subject->model());
    }

    /**
     * The request of the set for this fixture: with the tool "lookup" and the response format "trip" where the
     * fixture answers a request that has them.
     */
    private static function request(
        ContractSubject $subject,
        ContractFixture $fixture,
        ?string $traceId = self::TRACE_ID,
        float $timeoutSeconds = 30.0,
    ): Request {
        return new Request(
            $subject->model(),
            [Message::system('You are a travel desk.'), Message::user('Say hello.')],
            $timeoutSeconds,
            $fixture->needsTools()
                ? [new ToolDefinition('lookup', 'Looks up a city.', JsonSchema::fromJson('{"type":"object","properties":{"city":{"type":"string"}},"required":["city"]}'))]
                : [],
            responseFormat: $fixture->needsResponseFormat()
                ? new ResponseFormat(JsonSchema::fromJson(self::TRIP_SCHEMA), 'trip', 'A trip to one city.')
                : null,
            traceId: $traceId,
        );
    }

    /**
     * The message of an error for the message of a failed test, without the key should the error hold it.
     */
    private static function safe(GatewayException $error): string
    {
        return str_ireplace(self::MARKER_WORD, '…', $error->getMessage());
    }

    /**
     * Whether PHP keeps the arguments of calls in the trace of an exception here.
     */
    private static function traceKeepsArguments(string $argument): bool
    {
        $trace = (new Exception())->getTrace();

        return isset($trace[0]['args']) && $argument !== '';
    }
}
