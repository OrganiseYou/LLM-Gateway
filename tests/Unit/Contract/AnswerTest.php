<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\StructuredIssue;
use OrganiseYou\LlmGateway\Contract\StructuredIssueKind;
use OrganiseYou\LlmGateway\Contract\StructuredResult;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\Usage;

function answerCall(string $id = 'call_0'): ToolCall
{
    return ToolCall::decoded($id, 'lookup', ['q' => 'x'], '{"q":"x"}');
}

/**
 * @param array<string, mixed> $overrides
 */
function makeAnswer(array $overrides = []): Answer
{
    return new Answer(...array_merge([
        'text' => 'hello',
        'structured' => null,
        'toolCalls' => [],
        'finishReason' => FinishReason::Stop,
        'model' => 'model-a',
        'usage' => new Usage(10, 5),
        'latencyMs' => 120,
        'attempts' => 1,
        'traceId' => 'trace-1',
    ], $overrides));
}

describe('FinishReason', function (): void {
    it('has the five neutral values', function (): void {
        expect(array_map(fn(FinishReason $reason): string => $reason->value, FinishReason::cases()))
            ->toBe(['stop', 'tool_calls', 'length', 'content_filter', 'other']);
    });
});

describe('Usage', function (): void {
    it('keeps the token counts', function (): void {
        $usage = new Usage(10, 0);

        expect($usage->inputTokens)->toBe(10)
            ->and($usage->outputTokens)->toBe(0);
    });

    it('allows null when the provider did not report a count', function (): void {
        $usage = new Usage(null, null);

        expect($usage->inputTokens)->toBeNull()
            ->and($usage->outputTokens)->toBeNull();
    });

    it('throws when inputTokens is below 0', function (): void {
        new Usage(-1, 5);
    })->throws(InvalidArgumentException::class);

    it('throws when outputTokens is below 0', function (): void {
        new Usage(5, -1);
    })->throws(InvalidArgumentException::class);
});

describe('StructuredIssue', function (): void {
    it('has the three kinds', function (): void {
        expect(array_map(fn(StructuredIssueKind $kind): string => $kind->value, StructuredIssueKind::cases()))
            ->toBe(['not_json', 'schema_mismatch', 'cut_off']);
    });

    it('gives path and message as the reason of a schema mismatch', function (): void {
        $issue = new StructuredIssue(StructuredIssueKind::SchemaMismatch, 'must be a string', '/city');

        expect($issue->kind)->toBe(StructuredIssueKind::SchemaMismatch)
            ->and($issue->message)->toBe('must be a string')
            ->and($issue->path)->toBe('/city')
            ->and($issue->reason())->toBe('/city: must be a string');
    });

    it('gives the message as the reason of another kind', function (StructuredIssueKind $kind): void {
        $issue = new StructuredIssue($kind, 'unusable', null);

        expect($issue->path)->toBeNull()
            ->and($issue->reason())->toBe('unusable');
    })->with([StructuredIssueKind::NotJson, StructuredIssueKind::CutOff]);

    it('throws when the message is empty', function (): void {
        new StructuredIssue(StructuredIssueKind::NotJson, '', null);
    })->throws(InvalidArgumentException::class);

    it('throws when the kind is a schema mismatch and the path is null', function (): void {
        new StructuredIssue(StructuredIssueKind::SchemaMismatch, 'must be an object', null);
    })->throws(InvalidArgumentException::class);

    it('throws when the kind is another case and the path is not null', function (StructuredIssueKind $kind): void {
        new StructuredIssue($kind, 'unusable', '/');
    })->with([StructuredIssueKind::NotJson, StructuredIssueKind::CutOff])->throws(InvalidArgumentException::class);
});

describe('StructuredResult', function (): void {
    it('is usable with data and no issues', function (): void {
        $result = new StructuredResult(['city' => 'Utrecht'], []);

        expect($result->data)->toBe(['city' => 'Utrecht'])
            ->and($result->issues)->toBe([])
            ->and($result->isUsable())->toBeTrue()
            ->and($result->reasons())->toBe([]);
    });

    it('is usable with an empty object as data', function (): void {
        expect((new StructuredResult([], []))->isUsable())->toBeTrue();
    });

    it('keeps data that decoded but does not match, with its reasons in order', function (): void {
        $result = new StructuredResult(['city' => 5], [
            new StructuredIssue(StructuredIssueKind::SchemaMismatch, 'must be a string', '/city'),
            new StructuredIssue(StructuredIssueKind::SchemaMismatch, 'is required', '/'),
        ]);

        expect($result->data)->toBe(['city' => 5])
            ->and($result->isUsable())->toBeFalse()
            ->and($result->reasons())->toBe(['/city: must be a string', '/: is required']);
    });

    it('is not usable without data', function (): void {
        $result = new StructuredResult(null, [new StructuredIssue(StructuredIssueKind::NotJson, 'not JSON: syntax error', null)]);

        expect($result->isUsable())->toBeFalse()
            ->and($result->reasons())->toBe(['not JSON: syntax error']);
    });

    it('throws when issues holds a value that is not a structured issue', function (): void {
        new StructuredResult(null, untyped(['not JSON']));
    })->throws(InvalidArgumentException::class);

    it('throws when issues is not a list', function (): void {
        new StructuredResult(null, untyped(['first' => new StructuredIssue(StructuredIssueKind::NotJson, 'not JSON', null)]));
    })->throws(InvalidArgumentException::class);

    it('throws when data is null while issues is empty', function (): void {
        new StructuredResult(null, []);
    })->throws(InvalidArgumentException::class);
});

describe('Answer', function (): void {
    it('keeps its fields', function (): void {
        $structured = new StructuredResult(['city' => 'Utrecht'], []);
        $usage = new Usage(10, 5);
        $call = answerCall();
        $answer = new Answer('hello', $structured, [$call], FinishReason::ToolCalls, 'model-a', $usage, 0, 2, 'trace-1');

        expect($answer->text)->toBe('hello')
            ->and($answer->structured)->toBe($structured)
            ->and($answer->toolCalls)->toBe([$call])
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($answer->model)->toBe('model-a')
            ->and($answer->usage)->toBe($usage)
            ->and($answer->latencyMs)->toBe(0)
            ->and($answer->attempts)->toBe(2)
            ->and($answer->traceId)->toBe('trace-1');
    });

    it('text: may be empty', function (): void {
        expect(makeAnswer(['text' => '', 'toolCalls' => [answerCall()]])->text)->toBe('');
    });

    it('text: throws when not valid UTF-8', function (): void {
        makeAnswer(['text' => "caf\xE9"]);
    })->throws(InvalidArgumentException::class);

    it('toolCalls: throws when not a list', function (): void {
        makeAnswer(['toolCalls' => ['first' => answerCall()]]);
    })->throws(InvalidArgumentException::class);

    it('toolCalls: throws when it holds a value that is not a tool call', function (): void {
        makeAnswer(['toolCalls' => [answerCall(), 'call_1']]);
    })->throws(InvalidArgumentException::class);

    it('toolCalls: throws when two calls have the same id', function (): void {
        makeAnswer(['toolCalls' => [answerCall('call_0'), answerCall('call_0')]]);
    })->throws(InvalidArgumentException::class);

    it('model: throws when empty', function (): void {
        makeAnswer(['model' => '']);
    })->throws(InvalidArgumentException::class);

    it('latencyMs: throws below 0', function (): void {
        makeAnswer(['latencyMs' => -1]);
    })->throws(InvalidArgumentException::class);

    it('attempts: throws below 1', function (): void {
        makeAnswer(['attempts' => 0]);
    })->throws(InvalidArgumentException::class);

    it('traceId: throws when it does not match the pattern', function (string $traceId): void {
        makeAnswer(['traceId' => $traceId]);
    })->with([
        'empty' => [''],
        'a space' => ['trace 1'],
        'too long' => [str_repeat('a', 129)],
        'a trailing line break' => ["trace-1\n"],
    ])->throws(InvalidArgumentException::class);

    it('toMessage gives the assistant message with the text and the tool calls', function (): void {
        $call = answerCall();
        $message = makeAnswer(['text' => 'one moment', 'toolCalls' => [$call]])->toMessage();

        expect($message->role)->toBe(Role::Assistant)
            ->and($message->text())->toBe('one moment')
            ->and($message->toolCalls)->toBe([$call]);
    });

    it('toMessage gives an empty parts list for empty text with tool calls', function (): void {
        expect(makeAnswer(['text' => '', 'toolCalls' => [answerCall()]])->toMessage()->parts)->toBe([]);
    });

    it('toMessage throws a logic exception when the text is empty and there are no tool calls', function (): void {
        makeAnswer(['text' => ''])->toMessage();
    })->throws(LogicException::class);
});
