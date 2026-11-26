<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\Usage;

describe('the stream events', function (): void {
    it('are six final readonly values behind one marker interface without methods', function (): void {
        $interface = new ReflectionClass(StreamEvent::class);

        expect($interface->isInterface())->toBeTrue()
            ->and($interface->getMethods())->toBe([]);

        foreach ([TextDelta::class, ToolCallStarted::class, ToolCallCompleted::class, UsageReported::class, StreamFinished::class, StreamFailed::class] as $class) {
            $reflection = new ReflectionClass($class);

            expect($reflection->isFinal())->toBeTrue($class . ' must be final')
                ->and($reflection->isReadOnly())->toBeTrue($class . ' must be readonly')
                ->and($reflection->implementsInterface(StreamEvent::class))->toBeTrue($class . ' must be a stream event');
        }
    });

    it('holds a piece of text in a text delta', function (): void {
        expect((new TextDelta('Hel'))->text)->toBe('Hel');
    });

    it('refuses an empty text delta', function (): void {
        expect(fn() => new TextDelta(''))->toThrow(InvalidArgumentException::class, 'A text delta must not be empty.');
    });

    it('holds the index, the id and the name of a tool call that begins', function (): void {
        $started = new ToolCallStarted(0, 'call-1', 'lookup');

        expect($started->index)->toBe(0)
            ->and($started->id)->toBe('call-1')
            ->and($started->name)->toBe('lookup');
    });

    it('refuses a tool call that begins at an index below 0', function (): void {
        expect(fn() => new ToolCallStarted(-1, 'call-1', 'lookup'))
            ->toThrow(InvalidArgumentException::class, 'The index of a tool call must be 0 or more.');
    });

    it('holds the index and the whole tool call when it is complete', function (): void {
        $call = ToolCall::decoded('call-1', 'lookup', ['q' => 'x'], '{"q":"x"}');
        $completed = new ToolCallCompleted(1, $call);

        expect($completed->index)->toBe(1)
            ->and($completed->toolCall)->toBe($call);
    });

    it('refuses a complete tool call at an index below 0', function (): void {
        expect(fn() => new ToolCallCompleted(-1, ToolCall::decoded('call-1', 'lookup', [], '{}')))
            ->toThrow(InvalidArgumentException::class, 'The index of a tool call must be 0 or more.');
    });

    it('holds the usage, the answer of the end, and the error of a failure with what arrived', function (): void {
        $usage = new Usage(3, 2);
        $answer = new Answer('Hello.', null, [], FinishReason::Stop, 'model-a', $usage, 12, 1, 'trace-1');
        $error = new GatewayException(ErrorReason::Unavailable, 'trace-1');
        $call = ToolCall::unreadable('call-1', 'lookup', 'not JSON', '{');
        $failed = new StreamFailed($error, 'Hel', [$call]);

        expect((new UsageReported($usage))->usage)->toBe($usage)
            ->and((new StreamFinished($answer))->answer)->toBe($answer)
            ->and($failed->error)->toBe($error)
            ->and($failed->textSoFar)->toBe('Hel')
            ->and($failed->toolCallsSoFar)->toBe([$call]);
    });

    it('refuses tool calls so far that are not a list of tool calls', function (mixed $toolCalls, string $message): void {
        expect(fn() => new StreamFailed(new GatewayException(ErrorReason::Unavailable, 'trace-1'), '', untyped($toolCalls)))
            ->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'not a list' => [['first' => 'x'], 'The tool calls so far must be a list.'],
        'another value' => [['call-1'], 'The tool calls so far hold a value that is not a tool call.'],
    ]);
});
