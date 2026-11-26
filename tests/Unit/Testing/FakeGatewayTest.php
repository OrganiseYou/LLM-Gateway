<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\CheckResult;
use OrganiseYou\LlmGateway\Contract\CheckStatus;
use OrganiseYou\LlmGateway\Contract\ConnectionReport;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\StructuredIssue;
use OrganiseYou\LlmGateway\Contract\StructuredIssueKind;
use OrganiseYou\LlmGateway\Contract\StructuredResult;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Testing\FakeGateway;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

function requestOfTheFake(?ResponseFormat $format = null, ?string $traceId = 'trace-1', bool $tools = false): Request
{
    return new Request(
        'model-a',
        [Message::user('Say hello.')],
        30.0,
        $tools ? [new ToolDefinition('lookup', 'Looks up a city.', JsonSchema::fromJson('{"type":"object"}'))] : [],
        responseFormat: $format,
        traceId: $traceId,
    );
}

function formatForTheFake(string $schema = '{"type":"object","properties":{"city":{"type":"string"}},"required":["city"]}'): ResponseFormat
{
    return new ResponseFormat(JsonSchema::fromJson($schema), 'trip');
}

function answerForTheFake(string $text = 'Hello.', FinishReason $finish = FinishReason::Stop, ?StructuredResult $structured = null, ToolCall ...$calls): Answer
{
    return new Answer($text, $structured, array_values($calls), $finish, 'model-a-0001', new Usage(3, 2), 12, 2, 'scripted');
}

function capabilitiesForTheFake(Support $tools = Support::Native, Support $structured = Support::Native, Support $both = Support::Native, Support $forced = Support::Native, Support $models = Support::Native): Capabilities
{
    return new Capabilities($tools, $forced, $tools, $structured, $both, Support::Native, $models, null);
}

it('is a gateway, final and not readonly, with no state outside the object', function (): void {
    $reflection = new ReflectionClass(FakeGateway::class);

    expect(new FakeGateway())->toBeInstanceOf(Gateway::class)
        ->and($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeFalse()
        ->and($reflection->getStaticProperties())->toBe([]);
});

it('gives the scripted answers in order and records the requests it got', function (): void {
    $fake = (new FakeGateway())->willAnswerText('One.')->willAnswer(answerForTheFake('Two.'));
    $first = requestOfTheFake();
    $second = requestOfTheFake(traceId: 'trace-2');

    $one = $fake->complete($first);
    $two = $fake->complete($second);

    expect($one->text)->toBe('One.')
        ->and($one->finishReason)->toBe(FinishReason::Stop)
        ->and($one->model)->toBe('model-a')
        ->and($one->attempts)->toBe(1)
        ->and($one->traceId)->toBe('trace-1')
        ->and($one->structured)->toBeNull()
        ->and($two->text)->toBe('Two.')
        ->and($two->model)->toBe('model-a-0001')
        ->and($two->usage->inputTokens)->toBe(3)
        ->and($two->latencyMs)->toBe(12)
        ->and($two->attempts)->toBe(2)
        ->and($two->traceId)->toBe('trace-2')
        ->and($fake->requests())->toBe([$first, $second]);
});

it('keeps the trace id of a scripted answer when the request gives none, and makes one for a scripted text', function (): void {
    $fake = (new FakeGateway())->willAnswer(answerForTheFake())->willAnswerText('Hello.');

    expect($fake->complete(requestOfTheFake(traceId: null))->traceId)->toBe('scripted')
        ->and($fake->complete(requestOfTheFake(traceId: null))->traceId)->toMatch('/^[0-9a-f]{32}$/');
});

it('answers with scripted tool calls', function (): void {
    $call = ToolCall::decoded('call_0', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
    $answer = (new FakeGateway())->willCallTools($call)->complete(requestOfTheFake(tools: true));

    expect($answer->toolCalls)->toBe([$call])
        ->and($answer->text)->toBe('')
        ->and($answer->finishReason)->toBe(FinishReason::ToolCalls);
});

it('refuses a script of tool calls that no answer can hold', function (): void {
    $call = ToolCall::decoded('call_0', 'lookup', [], '{}');

    expect(fn() => (new FakeGateway())->willCallTools())->toThrow(InvalidArgumentException::class)
        ->and(fn() => (new FakeGateway())->willCallTools($call, $call))->toThrow(InvalidArgumentException::class);
});

it('throws a scripted failure with the trace id of the call', function (): void {
    $fake = (new FakeGateway())
        ->willFail(new GatewayException(ErrorReason::RateLimited, 'scripted', 429, 'slow_down', 7, 3, 'made up'))
        ->willFail(new GatewayException(ErrorReason::Timeout, 'scripted'));

    $error = Talk::errorOf(fn() => $fake->complete(requestOfTheFake()));

    expect($error->reason)->toBe(ErrorReason::RateLimited)
        ->and($error->traceId)->toBe('trace-1')
        ->and($error->httpStatus)->toBe(429)
        ->and($error->providerCode)->toBe('slow_down')
        ->and($error->retryAfterSeconds)->toBe(7)
        ->and($error->attempts)->toBe(3)
        ->and($error->detail)->toBe('made up')
        ->and(Talk::errorOf(fn() => $fake->complete(requestOfTheFake(traceId: null)))->traceId)->toBe('scripted');
});

it('throws a LogicException for a call that was not scripted', function (): void {
    $fake = new FakeGateway();

    expect(fn() => $fake->complete(requestOfTheFake()))->toThrow(LogicException::class)
        ->and(fn() => Talk::events($fake->stream(requestOfTheFake())))->toThrow(LogicException::class)
        ->and(fn() => $fake->models(10.0))->toThrow(LogicException::class)
        ->and(fn() => $fake->check('model-a', 10.0))->toThrow(LogicException::class)
        ->and($fake->requests())->toHaveCount(2);
});

it('gives a scripted answer as the events of a stream without streaming', function (): void {
    $call = ToolCall::decoded('call_0', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
    $fake = (new FakeGateway())->willAnswer(answerForTheFake('Let me look.', FinishReason::ToolCalls, null, $call))->willAnswerText('Hello.');

    $events = Talk::events($fake->stream(requestOfTheFake(tools: true)));
    $plain = Talk::events($fake->stream(requestOfTheFake()));

    expect(array_map(get_class(...), $events))->toBe([TextDelta::class, ToolCallStarted::class, ToolCallCompleted::class, UsageReported::class, StreamFinished::class])
        ->and(Talk::finishedIn($events)->answer->traceId)->toBe('trace-1')
        ->and(Talk::finishedIn($events)->answer->toolCalls)->toBe([$call])
        ->and(array_map(get_class(...), $plain))->toBe([TextDelta::class, StreamFinished::class]);
});

it('sends nothing and records nothing before the stream is read', function (): void {
    $fake = (new FakeGateway())->willAnswerText('Hello.');

    $stream = $fake->stream(requestOfTheFake());

    expect($fake->requests())->toBe([]);

    Talk::events($stream);

    expect($fake->requests())->toHaveCount(1);
});

it('gives a scripted stream as it was scripted, and its answer through complete', function (): void {
    $script = [new TextDelta('Hel'), new TextDelta('lo.'), new UsageReported(new Usage(3, 2)), new StreamFinished(answerForTheFake())];
    $fake = (new FakeGateway())->willStream(...$script)->willStream(...$script);

    $events = Talk::events($fake->stream(requestOfTheFake()));
    $answer = $fake->complete(requestOfTheFake(traceId: 'trace-2'));

    expect($events)->toHaveCount(4)
        ->and($events[0])->toBe($script[0])
        ->and(Talk::finishedIn($events)->answer->text)->toBe('Hello.')
        ->and(Talk::finishedIn($events)->answer->traceId)->toBe('trace-1')
        ->and($answer->text)->toBe('Hello.')
        ->and($answer->traceId)->toBe('trace-2');
});

it('never throws a gateway error from a stream: a scripted failure is the last event', function (): void {
    $failure = new GatewayException(ErrorReason::Unavailable, 'scripted', 503, null, null, 1);
    $fake = (new FakeGateway())
        ->willFail($failure)
        ->willStream(new TextDelta('Hel'), new StreamFailed($failure, 'Hel', []))
        ->willStream(new TextDelta('Hel'), new StreamFailed($failure, 'Hel', []));

    $refused = Talk::events($fake->stream(requestOfTheFake()));
    $broken = Talk::events($fake->stream(requestOfTheFake()));

    expect($refused)->toHaveCount(1)
        ->and(Talk::failedIn($refused)->error->reason)->toBe(ErrorReason::Unavailable)
        ->and(Talk::failedIn($refused)->error->traceId)->toBe('trace-1')
        ->and(Talk::failedIn($refused)->textSoFar)->toBe('')
        ->and($broken)->toHaveCount(2)
        ->and(Talk::failedIn($broken)->textSoFar)->toBe('Hel')
        ->and(Talk::failedIn($broken)->error->traceId)->toBe('trace-1')
        ->and(Talk::errorOf(fn() => $fake->complete(requestOfTheFake()))->reason)->toBe(ErrorReason::Unavailable);
});

it('refuses a scripted stream that breaks the rules of a stream', function (StreamEvent ...$events): void {
    expect(fn() => (new FakeGateway())->willStream(...$events))->toThrow(InvalidArgumentException::class);
})->with([
    'no event' => [],
    'no last event' => [new TextDelta('Hello.')],
    'an event after the last one' => [new StreamFinished(answerForTheFake()), new TextDelta('Hello.')],
    'two last events' => [new TextDelta('Hello.'), new StreamFinished(answerForTheFake()), new StreamFinished(answerForTheFake())],
    'a finished text that is not the text deltas' => [new TextDelta('Hel'), new StreamFinished(answerForTheFake())],
    'text so far that is not the text deltas' => [new TextDelta('Hel'), new StreamFailed(new GatewayException(ErrorReason::Timeout, 'scripted'), 'Hello', [])],
    'usage twice' => [new TextDelta('Hello.'), new UsageReported(new Usage(1, 1)), new UsageReported(new Usage(1, 1)), new StreamFinished(answerForTheFake())],
    'a tool call that never completes' => [
        new ToolCallStarted(0, 'call_0', 'lookup'),
        new StreamFinished(answerForTheFake('', FinishReason::ToolCalls, null, ToolCall::decoded('call_0', 'lookup', [], '{}'))),
    ],
    'a tool call that completes without a start' => [
        new ToolCallCompleted(0, ToolCall::decoded('call_0', 'lookup', [], '{}')),
        new StreamFinished(answerForTheFake('', FinishReason::ToolCalls, null, ToolCall::decoded('call_0', 'lookup', [], '{}'))),
    ],
    'a tool call that starts twice' => [
        new ToolCallStarted(0, 'call_0', 'lookup'),
        new ToolCallCompleted(0, ToolCall::decoded('call_0', 'lookup', [], '{}')),
        new ToolCallStarted(0, 'call_0', 'lookup'),
        new ToolCallCompleted(0, ToolCall::decoded('call_0', 'lookup', [], '{}')),
        new StreamFinished(answerForTheFake('', FinishReason::ToolCalls, null, ToolCall::decoded('call_0', 'lookup', [], '{}'))),
    ],
    'a tool call that completes with another id than it started with' => [
        new ToolCallStarted(0, 'call_9', 'lookup'),
        new ToolCallCompleted(0, ToolCall::decoded('call_0', 'lookup', [], '{}')),
        new StreamFinished(answerForTheFake('', FinishReason::ToolCalls, null, ToolCall::decoded('call_0', 'lookup', [], '{}'))),
    ],
    'a tool call that completes with another name than it started with' => [
        new ToolCallStarted(0, 'call_0', 'search'),
        new ToolCallCompleted(0, ToolCall::decoded('call_0', 'lookup', [], '{}')),
        new StreamFinished(answerForTheFake('', FinishReason::ToolCalls, null, ToolCall::decoded('call_0', 'lookup', [], '{}'))),
    ],
    'a reported usage that is not the usage of the answer' => [new TextDelta('Hello.'), new UsageReported(new Usage(9, 9)), new StreamFinished(answerForTheFake())],
    'tool calls so far that are not the calls that completed' => [
        new ToolCallStarted(0, 'call_0', 'lookup'),
        new ToolCallCompleted(0, ToolCall::decoded('call_0', 'lookup', [], '{}')),
        new StreamFailed(new GatewayException(ErrorReason::Timeout, 'scripted'), '', []),
    ],
    'tool calls that are not those of the answer' => [
        new ToolCallStarted(0, 'call_0', 'lookup'),
        new ToolCallCompleted(0, ToolCall::decoded('call_0', 'lookup', [], '{}')),
        new StreamFinished(answerForTheFake()),
    ],
]);

it('takes two tool calls with the same values for the same call in a scripted stream', function (): void {
    $events = Talk::events((new FakeGateway())->willStream(
        new ToolCallStarted(0, 'call_0', 'lookup'),
        new ToolCallCompleted(0, ToolCall::decoded('call_0', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')),
        new UsageReported(new Usage(3, 2)),
        new StreamFinished(answerForTheFake('', FinishReason::ToolCalls, null, ToolCall::decoded('call_0', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'))),
    )->willStream(
        new ToolCallStarted(0, 'call_0', 'lookup'),
        new ToolCallCompleted(0, ToolCall::decoded('call_0', 'lookup', [], '{}')),
        new StreamFailed(new GatewayException(ErrorReason::Timeout, 'scripted'), '', [ToolCall::decoded('call_0', 'lookup', [], '{}')]),
    )->stream(requestOfTheFake(tools: true)));

    expect($events)->toHaveCount(4)
        ->and(Talk::finishedIn($events)->answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris']);
});

it('runs the structured check on a scripted text, so a repair loop can be tested', function (): void {
    $fake = (new FakeGateway())->willAnswerText('{"city":"Paris"}')->willAnswerText('{"city":7}')->willAnswerText('Paris, I think.');

    $usable = $fake->complete(requestOfTheFake(formatForTheFake()));
    $mismatch = $fake->complete(requestOfTheFake(formatForTheFake()));
    $prose = Talk::finishedIn(Talk::events($fake->stream(requestOfTheFake(formatForTheFake()))))->answer;

    expect($usable->structured?->isUsable())->toBeTrue()
        ->and($usable->structured?->data)->toBe(['city' => 'Paris'])
        ->and($mismatch->structured?->isUsable())->toBeFalse()
        ->and($mismatch->structured?->issues[0]->kind)->toBe(StructuredIssueKind::SchemaMismatch)
        ->and($mismatch->structured?->issues[0]->path)->toBe('/city')
        ->and($prose->structured?->issues[0]->kind)->toBe(StructuredIssueKind::NotJson);
});

it('keeps a structured result that was scripted, and drops it when the request has no response format', function (): void {
    $scripted = new StructuredResult(['city' => 'Rome'], [new StructuredIssue(StructuredIssueKind::SchemaMismatch, 'made up', '/')]);
    $fake = (new FakeGateway())->willAnswer(answerForTheFake('{"city":"Paris"}', FinishReason::Stop, $scripted))->willAnswer(answerForTheFake('Hello.', FinishReason::Stop, $scripted));

    expect($fake->complete(requestOfTheFake(formatForTheFake()))->structured)->toBe($scripted)
        ->and($fake->complete(requestOfTheFake())->structured)->toBeNull();
});

it('gives no structured result for an answer that holds tool calls', function (): void {
    $call = ToolCall::decoded('call_0', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
    $scripted = new StructuredResult(['city' => 'Rome'], []);
    $fake = (new FakeGateway())
        ->willCallTools($call)
        ->willAnswer(answerForTheFake('{"city":"Rome"}', FinishReason::ToolCalls, $scripted, $call));

    expect($fake->complete(requestOfTheFake(formatForTheFake(), tools: true))->structured)->toBeNull()
        ->and(Talk::finishedIn(Talk::events($fake->stream(requestOfTheFake(formatForTheFake(), tools: true))))->answer->structured)->toBeNull();
});

it('gives the error of the structured check the trace id of the call', function (): void {
    $broken = formatForTheFake('{"type":"object","properties":{"city":{"type":[null]}}}');
    $fake = (new FakeGateway())->willAnswerText('{"city":"Paris"}')->willAnswerText('{"city":"Paris"}');

    $thrown = Talk::errorOf(fn() => $fake->complete(requestOfTheFake($broken)));
    $events = Talk::events($fake->stream(requestOfTheFake($broken, 'trace-2')));

    expect($thrown->reason)->toBe(ErrorReason::Rejected)
        ->and($thrown->traceId)->toBe('trace-1')
        ->and($thrown->attempts)->toBe(1)
        ->and(Talk::failedIn($events)->error->traceId)->toBe('trace-2')
        ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::Rejected);
});

it('treats an answer without text and without a tool call as a real gateway does', function (): void {
    $fake = (new FakeGateway())
        ->willAnswerText('')
        ->willAnswer(answerForTheFake('', FinishReason::Length))
        ->willAnswerText('');

    $error = Talk::errorOf(fn() => $fake->complete(requestOfTheFake()));

    expect($error->reason)->toBe(ErrorReason::ProviderError)
        ->and($error->traceId)->toBe('trace-1')
        ->and($fake->complete(requestOfTheFake())->finishReason)->toBe(FinishReason::Length)
        ->and(Talk::failedIn(Talk::events($fake->stream(requestOfTheFake())))->error->reason)->toBe(ErrorReason::ProviderError);
});

it('says it can do everything until it is told otherwise', function (): void {
    $fake = new FakeGateway();
    $all = $fake->capabilities('model-a');
    $told = capabilitiesForTheFake(Support::None, Support::Emulated, Support::None, Support::None, Support::None);

    expect($all)->toEqual(capabilitiesForTheFake())
        ->and($fake->withCapabilities($told)->capabilities('any-model'))->toBe($told)
        ->and(fn() => $fake->capabilities(''))->toThrow(InvalidArgumentException::class)
        ->and($fake->requests())->toBe([]);
});

it('refuses what its scripted capabilities lack, before it uses a scripted answer', function (Capabilities $can, Request $request): void {
    $fake = (new FakeGateway())->withCapabilities($can)->willAnswerText('Hello.');

    $error = Talk::errorOf(fn() => $fake->complete($request));
    $events = Talk::events($fake->stream($request));

    expect($error->reason)->toBe(ErrorReason::NotSupported)
        ->and($error->attempts)->toBe(0)
        ->and($error->httpStatus)->toBeNull()
        ->and($error->traceId)->toBe('trace-1')
        ->and($events)->toHaveCount(1)
        ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::NotSupported)
        ->and($fake->complete(requestOfTheFake())->text)->toBe('Hello.');
})->with([
    'tools' => [capabilitiesForTheFake(tools: Support::None, both: Support::None, forced: Support::None), requestOfTheFake(tools: true)],
    'a forced tool call' => [capabilitiesForTheFake(forced: Support::None), requestOfTheFake(tools: true)->withToolChoice(ToolChoice::tool('lookup'))],
    'a response format' => [capabilitiesForTheFake(structured: Support::None, both: Support::None), requestOfTheFake(formatForTheFake())],
    'tools with a response format' => [capabilitiesForTheFake(both: Support::None), requestOfTheFake(formatForTheFake(), tools: true)],
]);

it('lists the models it was given, and refuses when its capabilities say it cannot', function (): void {
    $model = new ModelInfo('model-a');
    $fake = (new FakeGateway())->withModels($model);

    expect($fake->models(10.0))->toBe([$model])
        ->and(fn() => $fake->models(0.0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $fake->models(10.0, 'not a trace id'))->toThrow(InvalidArgumentException::class);

    $error = Talk::errorOf(fn() => $fake->withCapabilities(capabilitiesForTheFake(models: Support::None))->models(10.0, 'trace-9'));

    expect($error->reason)->toBe(ErrorReason::NotSupported)
        ->and($error->traceId)->toBe('trace-9')
        ->and($error->attempts)->toBe(0);
});

it('returns the scripted connection report, with the trace id of the call when one is given', function (): void {
    $yes = new CheckResult(CheckStatus::Yes);
    $report = new ConnectionReport($yes, $yes, $yes, $yes, $yes, 'scripted');
    $fake = (new FakeGateway())->withConnectionReport($report);

    expect($fake->check('model-a', 10.0))->toBe($report)
        ->and($fake->check('model-a', 10.0, 'trace-3')->traceId)->toBe('trace-3')
        ->and($fake->check('model-a', 10.0, 'trace-3')->allPassed())->toBeTrue()
        ->and(fn() => $fake->check('', 10.0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $fake->check('model-a', NAN))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $fake->check('model-a', 10.0, ''))->toThrow(InvalidArgumentException::class);
});
