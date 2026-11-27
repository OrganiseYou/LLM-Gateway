<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use Generator;
use LogicException;
use OrganiseYou\LlmGateway\Adapter\Shared\StructuredAnswerChecker;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ConnectionReport;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoiceMode;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Contract\WrongUse;

/**
 * A gateway for the tests of an application: it sends nothing and answers what the test scripted.
 *
 * willAnswer(), willAnswerText(), willCallTools(), willStream() and willFail() script one call each, in order; one
 * scripted entry serves one call of complete() or stream(), whichever comes. A scripted answer read through stream()
 * comes as the events of a gateway without streaming; a scripted stream read through complete() gives the answer of
 * its last event, or throws the error of it. A call with nothing scripted throws a LogicException. Every request is
 * recorded, a stream's when it is read.
 *
 * It keeps the rules of a real gateway where they can apply to it:
 *
 * - The trace id of a call is the one of its request. A request without one keeps the id of the scripted answer or
 *   failure; a scripted text or tool call then gets a new one.
 * - A request with a response format gets a structured result unless the answer holds tool calls: the one that was
 *   scripted, or else the same check of the text that a real gateway runs. A request without one never gets one.
 * - An answer without text and without a tool call is returned when its finish reason explains it (cut off at the
 *   token limit, refused by the model, withheld by the provider); with any other finish reason it is a provider
 *   error.
 * - stream() throws no gateway error: a failure is the last event.
 * - With withCapabilities(), a request that asks what those capabilities lack (tools, a forced tool call, a
 *   response format, a temperature for a model that takes none) is refused as not supported, and no scripted entry
 *   is used for it. Until then the fake says it can do everything.
 * - It never makes an opaque part. One that a scripted answer holds reaches the caller in the answer and in its
 *   toMessage(); a recorded request holds the parts it was sent, so a test can assert that the part went into the
 *   next round. The fake does not judge a part: it has no provider to send it to.
 *
 * Its script and its record are the state of one object, on purpose: it lives in a test.
 */
final class FakeGateway implements Gateway
{
    /** @var list<Answer|GatewayException|string|array{text: string, usage: Usage}|array{calls: list<ToolCall>}|array{events: list<StreamEvent>}> */
    private array $script = [];

    /** @var list<Request> */
    private array $requests = [];

    private ?Capabilities $capabilities = null;

    /** @var list<ModelInfo>|null */
    private ?array $models = null;

    private ?ConnectionReport $report = null;

    /**
     * The next call gets this answer, with the trace id of its request and the structured result of the rules above.
     */
    public function willAnswer(Answer $answer): static
    {
        $this->script[] = $answer;

        return $this;
    }

    /**
     * The next call gets an answer with this text that ended by itself, from the model of its request.
     *
     * @param Usage|null $usage the tokens the answer reports; null: none, as a provider that reports no usage
     */
    public function willAnswerText(string $text, ?Usage $usage = null): static
    {
        if (preg_match('//u', $text) !== 1) {
            throw new InvalidValue('The text of an answer must be valid UTF-8.');
        }

        $this->script[] = $usage === null ? $text : ['text' => $text, 'usage' => $usage];

        return $this;
    }

    /**
     * The next call gets an answer without text that asks for these tool calls.
     */
    public function willCallTools(ToolCall ...$toolCalls): static
    {
        $toolCalls = array_values($toolCalls);
        if ($toolCalls === []) {
            throw new InvalidValue('Give at least one tool call.');
        }
        // The rules of an answer, checked where the script is written.
        self::answerOf('', $toolCalls, 'model', 'script');

        $this->script[] = ['calls' => $toolCalls];

        return $this;
    }

    /**
     * The next call gets these events. They must be a whole stream: one last event that is a StreamFinished or a
     * StreamFailed, the text of the text deltas equal to the text of that last event, every tool call started once
     * and then completed with the same id and name, the completed calls equal (by value) to those of the last event,
     * and usage reported at most once and equal to the usage of the answer.
     */
    public function willStream(StreamEvent ...$events): static
    {
        $events = array_values($events);
        self::assertWholeStream($events);

        $this->script[] = ['events' => $events];

        return $this;
    }

    /**
     * The next call fails with this error: thrown by complete(), the one event of stream().
     */
    public function willFail(GatewayException $error): static
    {
        $this->script[] = $error;

        return $this;
    }

    /**
     * What every model can do from now on. A request that needs more is refused as a real gateway refuses it.
     */
    public function withCapabilities(Capabilities $capabilities): static
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    /**
     * What models() gives from now on.
     */
    public function withModels(ModelInfo ...$models): static
    {
        $this->models = array_values($models);

        return $this;
    }

    /**
     * What check() gives from now on.
     */
    public function withConnectionReport(ConnectionReport $report): static
    {
        $this->report = $report;

        return $this;
    }

    /**
     * Every request of complete() and of a stream that was read, in order.
     *
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * How many scripted answers, streams and failures are still unused.
     */
    public function remaining(): int
    {
        return count($this->script);
    }

    public function complete(Request $request): Answer
    {
        $this->requests[] = $request;
        $this->refuseWhatItCannotDo($request);

        $entry = $this->next();

        if ($entry instanceof GatewayException) {
            throw self::withTraceId($entry, $request->traceId ?? $entry->traceId);
        }
        if (is_array($entry) && isset($entry['events'])) {
            $last = $entry['events'][count($entry['events']) - 1];
            if ($last instanceof StreamFailed) {
                throw self::withTraceId($last->error, $request->traceId ?? $last->error->traceId);
            }
            if (! $last instanceof StreamFinished) {
                throw new WrongUse('A scripted stream has no last event.');
            }
            $entry = $last->answer;
        }

        return self::finished(self::scripted($entry, $request), $request);
    }

    public function stream(Request $request): iterable
    {
        return $this->events($request);
    }

    public function models(float $timeoutSeconds, ?string $traceId = null): array
    {
        self::assertTimeout($timeoutSeconds);
        self::assertTraceId($traceId);

        if ($this->capabilities?->modelListing === Support::None) {
            throw new GatewayException(ErrorReason::NotSupported, $traceId ?? self::newTraceId(), null, null, null, 0, 'the models cannot be listed here');
        }
        if ($this->models === null) {
            throw new WrongUse('The fake gateway was asked for its models and has none: give them with withModels().');
        }

        return $this->models;
    }

    public function capabilities(string $model): Capabilities
    {
        self::assertModel($model);

        return $this->capabilities ?? new Capabilities(
            Support::Native,
            Support::Native,
            Support::Native,
            Support::Native,
            Support::Native,
            Support::Native,
            Support::Native,
            null,
        );
    }

    public function check(string $model, float $timeoutSeconds, ?string $traceId = null): ConnectionReport
    {
        self::assertModel($model);
        self::assertTimeout($timeoutSeconds);
        self::assertTraceId($traceId);

        $report = $this->report;
        if ($report === null) {
            throw new WrongUse('The fake gateway was asked for a connection check and has no report: give one with withConnectionReport().');
        }
        if ($traceId === null || $traceId === $report->traceId) {
            return $report;
        }

        return new ConnectionReport($report->signIn, $report->modelExists, $report->toolCall, $report->structuredAnswer, $report->stream, $traceId);
    }

    /**
     * Every event is given with a plain yield from this one generator, so the keys count up from 0.
     *
     * @return Generator<int, StreamEvent, mixed, void>
     */
    private function events(Request $request): Generator
    {
        $this->requests[] = $request;

        try {
            $this->refuseWhatItCannotDo($request);
        } catch (GatewayException $refusal) {
            yield new StreamFailed($refusal, '', []);

            return;
        }

        $entry = $this->next();

        if ($entry instanceof GatewayException) {
            yield new StreamFailed(self::withTraceId($entry, $request->traceId ?? $entry->traceId), '', []);

            return;
        }

        if (! is_array($entry) || ! isset($entry['events'])) {
            // No stream was scripted: the whole answer, as the events of a gateway without streaming.
            try {
                $answer = self::finished(self::scripted($entry, $request), $request);
            } catch (GatewayException $error) {
                yield new StreamFailed($error, '', []);

                return;
            }

            if ($answer->text !== '') {
                yield new TextDelta($answer->text);
            }
            foreach ($answer->toolCalls as $index => $toolCall) {
                yield new ToolCallStarted($index, $toolCall->id, $toolCall->name);
                yield new ToolCallCompleted($index, $toolCall);
            }
            if ($answer->usage->inputTokens !== null || $answer->usage->outputTokens !== null) {
                yield new UsageReported($answer->usage);
            }
            yield new StreamFinished($answer);

            return;
        }

        $text = '';
        $toolCalls = [];
        foreach ($entry['events'] as $event) {
            if ($event instanceof StreamFailed) {
                yield new StreamFailed(
                    self::withTraceId($event->error, $request->traceId ?? $event->error->traceId),
                    $event->textSoFar,
                    $event->toolCallsSoFar,
                );

                return;
            }
            if ($event instanceof StreamFinished) {
                try {
                    $answer = self::finished(self::scripted($event->answer, $request), $request);
                } catch (GatewayException $error) {
                    yield new StreamFailed($error, $text, $toolCalls);

                    return;
                }

                yield new StreamFinished($answer);

                return;
            }
            if ($event instanceof TextDelta) {
                $text .= $event->text;
            } elseif ($event instanceof ToolCallCompleted) {
                $toolCalls[] = $event->toolCall;
            }

            yield $event;
        }
    }

    /**
     * @return Answer|GatewayException|string|array{text: string, usage: Usage}|array{calls: list<ToolCall>}|array{events: list<StreamEvent>}
     */
    private function next(): Answer|GatewayException|string|array
    {
        if ($this->script === []) {
            throw new WrongUse(
                'The fake gateway got request ' . count($this->requests) . ' and has no scripted answer left.',
            );
        }

        return array_shift($this->script);
    }

    /**
     * The refusals of a gateway whose model cannot do what the request asks: before anything of the script is used.
     *
     * @throws GatewayException not_supported
     */
    private function refuseWhatItCannotDo(Request $request): void
    {
        $can = $this->capabilities;
        if ($can === null) {
            return;
        }

        $hasTools = $request->tools !== [];
        $hasFormat = $request->responseFormat !== null;

        $detail = match (true) {
            $hasTools && $can->toolCalling === Support::None => 'this model cannot call tools',
            $request->toolChoice->mode === ToolChoiceMode::Tool && $can->forcedToolCall === Support::None => 'this model cannot be made to call one named tool',
            $hasFormat && $can->structuredOutput === Support::None => 'this model cannot give a structured answer',
            $hasTools && $hasFormat && $can->toolsWithStructuredOutput === Support::None => 'this model cannot take tools and a response format in one request',
            $request->temperature !== null && $can->temperature === Support::None => 'this model takes no temperature',
            default => null,
        };
        if ($detail !== null) {
            throw new GatewayException(ErrorReason::NotSupported, $request->traceId ?? self::newTraceId(), null, null, null, 0, $detail);
        }
    }

    /**
     * The scripted entry as an answer with the trace id of the call.
     *
     * @param Answer|string|array{text: string, usage: Usage}|array{calls: list<ToolCall>}|array{events: list<StreamEvent>} $entry
     */
    private static function scripted(Answer|string|array $entry, Request $request): Answer
    {
        if ($entry instanceof Answer) {
            return $entry;
        }
        if (is_string($entry)) {
            return self::answerOf($entry, [], $request->model, $request->traceId ?? self::newTraceId());
        }
        if (isset($entry['text'], $entry['usage'])) {
            return self::answerOf($entry['text'], [], $request->model, $request->traceId ?? self::newTraceId(), $entry['usage']);
        }
        if (! isset($entry['calls'])) {
            throw new WrongUse('A scripted stream is no answer.');
        }

        return self::answerOf('', $entry['calls'], $request->model, $request->traceId ?? self::newTraceId());
    }

    /**
     * @param list<ToolCall> $toolCalls
     */
    private static function answerOf(string $text, array $toolCalls, string $model, string $traceId, ?Usage $usage = null): Answer
    {
        return new Answer(
            $text,
            null,
            $toolCalls,
            $toolCalls === [] ? FinishReason::Stop : FinishReason::ToolCalls,
            $model,
            $usage ?? new Usage(null, null),
            0,
            1,
            $traceId,
        );
    }

    /**
     * The answer as a real gateway hands it over: with the trace id of the call and the structured result the
     * request asks for.
     *
     * @throws GatewayException provider_error, for an answer without text and without a tool call whose finish
     *                          reason does not explain it; rejected, when the schema of the response format cannot
     *                          be used
     */
    private static function finished(Answer $answer, Request $request): Answer
    {
        $traceId = $request->traceId ?? $answer->traceId;

        $explained = match ($answer->finishReason) {
            FinishReason::Length, FinishReason::Refusal, FinishReason::ContentFilter => true,
            FinishReason::Stop, FinishReason::ToolCalls, FinishReason::Other => false,
        };
        if ($answer->text === '' && $answer->toolCalls === [] && ! $explained) {
            throw new GatewayException(ErrorReason::ProviderError, $traceId, null, null, null, $answer->attempts, 'the answer has no text and no tool call');
        }

        $structured = null;
        if ($request->responseFormat !== null && $answer->toolCalls === []) {
            try {
                $structured = $answer->structured
                    ?? (new StructuredAnswerChecker())->check($answer->text, $request->responseFormat, $answer->finishReason);
            } catch (GatewayException $refusal) {
                // The check knows no call: its error gets the trace id and the attempts of this one.
                throw new GatewayException($refusal->reason, $traceId, null, $refusal->providerCode, null, $answer->attempts, $refusal->detail);
            }
        }

        if ($structured === $answer->structured && $traceId === $answer->traceId) {
            return $answer;
        }

        return new Answer(
            $answer->text,
            $structured,
            $answer->toolCalls,
            $answer->finishReason,
            $answer->model,
            $answer->usage,
            $answer->latencyMs,
            $answer->attempts,
            $traceId,
            $answer->opaque,
        );
    }

    private static function withTraceId(GatewayException $error, string $traceId): GatewayException
    {
        if ($traceId === $error->traceId) {
            return $error;
        }

        return new GatewayException(
            $error->reason,
            $traceId,
            $error->httpStatus,
            $error->providerCode,
            $error->retryAfterSeconds,
            $error->attempts,
            $error->detail,
        );
    }

    /**
     * @param list<StreamEvent> $events
     */
    private static function assertWholeStream(array $events): void
    {
        $last = $events === [] ? null : $events[count($events) - 1];
        if (! $last instanceof StreamFinished && ! $last instanceof StreamFailed) {
            throw new InvalidValue('A scripted stream must end with a StreamFinished or a StreamFailed.');
        }

        $text = '';
        $open = [];
        $seen = [];
        $completed = [];
        $usage = null;
        $usageReports = 0;
        foreach (array_slice($events, 0, -1) as $event) {
            if ($event instanceof StreamFinished || $event instanceof StreamFailed) {
                throw new InvalidValue('A scripted stream has one last event, and nothing comes after it.');
            }
            if ($event instanceof TextDelta) {
                $text .= $event->text;
            } elseif ($event instanceof ToolCallStarted) {
                if (isset($seen[$event->index])) {
                    throw new InvalidValue('A tool call of a scripted stream starts twice with the same index.');
                }
                $seen[$event->index] = true;
                $open[$event->index] = $event;
            } elseif ($event instanceof ToolCallCompleted) {
                $started = $open[$event->index] ?? null;
                if ($started === null) {
                    throw new InvalidValue('A tool call of a scripted stream completes without a start with the same index.');
                }
                if ($started->id !== $event->toolCall->id || $started->name !== $event->toolCall->name) {
                    throw new InvalidValue('A tool call of a scripted stream completes with another id or another name than it started with.');
                }
                unset($open[$event->index]);
                $completed[] = $event->toolCall;
            } elseif ($event instanceof UsageReported) {
                $usage = $event->usage;
                $usageReports++;
            }
        }

        if ($usageReports > 1) {
            throw new InvalidValue('A scripted stream reports usage at most once.');
        }
        // Two tool calls are the same call when their values are: a test may build the call twice.
        if ($last instanceof StreamFailed) {
            if ($last->textSoFar !== $text) {
                throw new InvalidValue('The text so far of a scripted failure must be the text of the text deltas before it.');
            }
            if ($last->toolCallsSoFar != $completed) {
                throw new InvalidValue('The tool calls so far of a scripted failure must be the calls that completed before it, in that order.');
            }

            return;
        }
        if ($open !== []) {
            throw new InvalidValue('A tool call of a scripted stream starts and never completes.');
        }
        if ($last->answer->text !== $text) {
            throw new InvalidValue('The text of the answer of a scripted stream must be the text of its text deltas.');
        }
        if ($last->answer->toolCalls != $completed) {
            throw new InvalidValue('The tool calls of the answer of a scripted stream must be the calls that completed, in that order.');
        }
        if ($usage !== null && $usage != $last->answer->usage) {
            throw new InvalidValue('The usage a scripted stream reports must be the usage of its answer.');
        }
    }

    private static function newTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function assertTimeout(float $timeoutSeconds): void
    {
        if (! Rules::isTimeout($timeoutSeconds)) {
            throw new InvalidValue('The timeout must be a finite number of seconds above 0.');
        }
    }

    private static function assertTraceId(?string $traceId): void
    {
        if ($traceId !== null && ! Rules::isTraceId($traceId)) {
            throw new InvalidValue('The trace id must match ^[A-Za-z0-9_.:-]{1,128}$.');
        }
    }

    private static function assertModel(string $model): void
    {
        if (! Rules::isModelName($model)) {
            throw new InvalidValue('The model must not be empty and must not hold a control character.');
        }
    }
}
