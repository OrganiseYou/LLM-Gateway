<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use Generator;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ConnectionReport;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\StructuredResult;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;

/**
 * A gateway that breaks one rule of the contract on purpose, around a gateway that keeps them all. The shared test
 * set must fail on each of these: that is how its own tests show it checks what it says.
 */
final class BrokenGateway implements Gateway
{
    public const string USAGE_TWICE = 'reports the usage twice';
    public const string OTHER_TEXT = 'ends a stream with a text that is not its text deltas';
    public const string EVENT_AFTER_END = 'gives an event after the last one';
    public const string CALL_NEVER_COMPLETES = 'starts a tool call that never completes';
    public const string THROWS_FROM_STREAM = 'throws a gateway error from stream()';
    public const string EAGER_STREAM = 'sends the request before the stream is read';
    public const string STRUCTURED_FOR_TOOL_CALL = 'gives a structured result for an answer with tool calls';
    public const string THROWS_UNUSABLE = 'throws for a structured answer that does not match';
    public const string OWN_TRACE_ID = 'answers with a trace id of its own';
    public const string KEY_IN_DETAIL = 'repeats the key in the detail of an error';
    public const string ANSWERS_WHAT_IT_CANNOT = 'answers a request its capabilities say it cannot do';
    public const string KEY_AS_CODE = 'repeats the key as the provider code';
    public const string INVENTED_CODE = 'gives an error a provider code that the provider did not give';
    public const string DROPS_USAGE = 'drops the usage the provider reported';
    public const string SECOND_REQUEST_AFTER_REDIRECT = 'sends a second request after a 302 in stream()';
    public const string WRONG_ATTEMPTS = 'counts an attempt too many in the failed event of a stream';
    public const string NO_STATUS_IN_STREAM = 'leaves the status out of the failed event of a stream';
    public const string OTHER_STARTED_NAME = 'starts a tool call under another tool name than it completes with';
    public const string DROPS_OPAQUE = 'drops the opaque part of an answer';
    public const string CHANGES_OPAQUE_BYTES = 'changes the bytes of the opaque part of an answer';
    public const string OPAQUE_IN_TEXT = 'repeats the opaque data in the text of the answer and in a text event';
    public const string KEY_IN_OPAQUE = 'lets the key the provider repeated into the opaque part of an answer';
    public const string OPAQUE_IN_DETAIL = 'repeats the opaque data of a request in the detail of an error';

    public function __construct(private readonly Gateway $inner, private readonly string $fault, private readonly ?Capabilities $says = null) {}

    public function complete(Request $request): Answer
    {
        if ($this->fault === self::ANSWERS_WHAT_IT_CANNOT) {
            return new Answer('Hello.', null, [], FinishReason::Stop, $request->model, new Usage(null, null), 0, 1, $request->traceId ?? 'trace-of-the-broken');
        }

        try {
            $answer = $this->inner->complete($request);
        } catch (GatewayException $error) {
            throw match ($this->fault) {
                self::KEY_IN_DETAIL => new GatewayException($error->reason, $error->traceId, $error->httpStatus, null, null, $error->attempts, 'the provider refused the key ' . ContractTestCase::MARKER_KEY),
                self::KEY_AS_CODE => new GatewayException($error->reason, $error->traceId, $error->httpStatus, ContractTestCase::MARKER_KEY, null, $error->attempts),
                self::INVENTED_CODE => new GatewayException($error->reason, $error->traceId, $error->httpStatus, 'invented_code', null, $error->attempts),
                self::OPAQUE_IN_DETAIL => new GatewayException($error->reason, $error->traceId, $error->httpStatus, null, null, $error->attempts, self::opaqueDetail($request) ?? $error->detail),
                default => $error,
            };
        }

        if ($this->fault === self::THROWS_UNUSABLE && $answer->structured?->isUsable() === false) {
            throw new GatewayException(ErrorReason::ProviderError, $answer->traceId, 200, null, null, 1, 'the answer does not match the schema');
        }

        return $this->changed($answer);
    }

    public function stream(Request $request): iterable
    {
        if ($this->fault === self::EAGER_STREAM) {
            return iterator_to_array($this->events($request), false);
        }

        return $this->events($request);
    }

    public function models(float $timeoutSeconds, ?string $traceId = null): array
    {
        return $this->inner->models($timeoutSeconds, $traceId);
    }

    public function capabilities(string $model): Capabilities
    {
        return $this->says ?? $this->inner->capabilities($model);
    }

    public function check(string $model, float $timeoutSeconds, ?string $traceId = null): ConnectionReport
    {
        return $this->inner->check($model, $timeoutSeconds, $traceId);
    }

    /**
     * @return Generator<int, StreamEvent, mixed, void>
     */
    private function events(Request $request): Generator
    {
        foreach ($this->inner->stream($request) as $event) {
            if ($event instanceof StreamFailed && $this->fault === self::THROWS_FROM_STREAM) {
                throw $event->error;
            }
            if ($event instanceof ToolCallCompleted && $this->fault === self::CALL_NEVER_COMPLETES) {
                continue;
            }
            if ($event instanceof UsageReported && $this->fault === self::USAGE_TWICE) {
                yield $event;
            }
            if ($event instanceof UsageReported && $this->fault === self::DROPS_USAGE) {
                continue;
            }
            if ($event instanceof ToolCallStarted && $this->fault === self::OTHER_STARTED_NAME) {
                $event = new ToolCallStarted($event->index, $event->id, 'another_tool');
            }
            if ($event instanceof StreamFailed) {
                $error = $event->error;
                if ($this->fault === self::SECOND_REQUEST_AFTER_REDIRECT && $error->httpStatus === 302) {
                    foreach ($this->inner->stream($request) as $ignored) {
                        unset($ignored);
                    }
                }
                $event = new StreamFailed(
                    new GatewayException(
                        $error->reason,
                        $error->traceId,
                        $this->fault === self::NO_STATUS_IN_STREAM ? null : $error->httpStatus,
                        $error->providerCode,
                        $error->retryAfterSeconds,
                        $error->attempts + ($this->fault === self::WRONG_ATTEMPTS ? 1 : 0),
                        $this->fault === self::OPAQUE_IN_DETAIL ? self::opaqueDetail($request) ?? $error->detail : $error->detail,
                    ),
                    $event->textSoFar,
                    $event->toolCallsSoFar,
                );
            }
            if ($event instanceof StreamFinished) {
                if ($this->fault === self::OPAQUE_IN_TEXT && $event->answer->opaque !== null) {
                    // The stream keeps its rules: the text of the answer is the text of its deltas.
                    yield new TextDelta(ContractFixture::OPAQUE_MARKER);
                }
                $event = new StreamFinished($this->changed($event->answer, $this->fault === self::OTHER_TEXT ? ' And more.' : ''));
            }

            yield $event;

            if ($event instanceof StreamFinished && $this->fault === self::EVENT_AFTER_END) {
                yield new TextDelta('More.');
            }
        }
    }

    private function changed(Answer $answer, string $moreText = ''): Answer
    {
        $structured = $answer->structured;
        if ($this->fault === self::STRUCTURED_FOR_TOOL_CALL && $answer->toolCalls !== []) {
            $structured = new StructuredResult(['city' => 'Paris', 'days' => 3], []);
        }

        $opaque = match (true) {
            $answer->opaque === null, $this->fault === self::DROPS_OPAQUE => null,
            $this->fault === self::CHANGES_OPAQUE_BYTES => new OpaquePart(strtoupper($answer->opaque->data)),
            $this->fault === self::KEY_IN_OPAQUE => new OpaquePart($answer->opaque->data . ContractTestCase::MARKER_KEY),
            default => $answer->opaque,
        };
        if ($this->fault === self::OPAQUE_IN_TEXT && $answer->opaque !== null) {
            $moreText .= ContractFixture::OPAQUE_MARKER;
        }

        return new Answer(
            $answer->text . $moreText,
            $structured,
            $answer->toolCalls,
            $answer->finishReason,
            $answer->model,
            $this->fault === self::DROPS_USAGE ? new Usage(null, null) : $answer->usage,
            $answer->latencyMs,
            $answer->attempts,
            $this->fault === self::OWN_TRACE_ID ? 'trace-of-the-broken' : $answer->traceId,
            $opaque,
        );
    }

    /**
     * What a careless gateway writes in an error about a request that carried an opaque part: a piece of the part.
     */
    private static function opaqueDetail(Request $request): ?string
    {
        foreach ($request->messages as $message) {
            foreach ($message->parts as $part) {
                if ($part instanceof OpaquePart && str_contains($part->data, ContractFixture::OPAQUE_MARKER)) {
                    return 'the provider refused the data ' . ContractFixture::OPAQUE_MARKER;
                }
            }
        }

        return null;
    }
}
