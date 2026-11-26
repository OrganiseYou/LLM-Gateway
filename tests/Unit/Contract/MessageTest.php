<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\ContentPart;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\TextPart;
use OrganiseYou\LlmGateway\Contract\ToolCall;

function messageCall(string $id = 'call_0'): ToolCall
{
    return ToolCall::decoded($id, 'lookup', ['q' => 'x'], '{"q":"x"}');
}

describe('Role', function (): void {
    it('has the four neutral values', function (): void {
        expect(array_map(fn(Role $role): string => $role->value, Role::cases()))
            ->toBe(['system', 'user', 'assistant', 'tool']);
    });
});

describe('TextPart', function (): void {
    it('is a content part and keeps its text', function (): void {
        $part = new TextPart('hello');

        expect($part)->toBeInstanceOf(ContentPart::class)
            ->and($part->text)->toBe('hello');
    });

    it('allows an empty string', function (): void {
        expect((new TextPart(''))->text)->toBe('');
    });

    it('throws when the text is not valid UTF-8', function (): void {
        new TextPart("caf\xE9");
    })->throws(InvalidArgumentException::class);
});

describe('Message named constructors', function (): void {
    it('builds a system message', function (): void {
        $message = Message::system('be brief');

        expect($message->role)->toBe(Role::System)
            ->and($message->parts)->toEqual([new TextPart('be brief')])
            ->and($message->toolCalls)->toBe([])
            ->and($message->toolCallId)->toBeNull()
            ->and($message->toolName)->toBeNull();
    });

    it('builds a user message', function (): void {
        $message = Message::user('hello');

        expect($message->role)->toBe(Role::User)
            ->and($message->text())->toBe('hello');
    });

    it('builds an assistant message with text and tool calls', function (): void {
        $first = messageCall('call_0');
        $second = messageCall('call_1');
        $message = Message::assistant('one moment', $first, $second);

        expect($message->role)->toBe(Role::Assistant)
            ->and($message->text())->toBe('one moment')
            ->and($message->toolCalls)->toBe([$first, $second]);
    });

    it('gives an empty parts list for an assistant message with empty text and a tool call', function (): void {
        $message = Message::assistant('', messageCall());

        expect($message->parts)->toBe([])
            ->and($message->text())->toBe('');
    });

    it('gives exactly one empty text part for an assistant message with empty text and no tool call', function (): void {
        $message = Message::assistant('');

        expect($message->parts)->toEqual([new TextPart('')])
            ->and($message->parts)->toHaveCount(1)
            ->and($message->toolCalls)->toBe([]);
    });

    it('keeps the tool calls as a list when they are spread from a keyed array', function (): void {
        $message = Message::assistant('', ...['first' => messageCall()]);

        expect(array_keys($message->toolCalls))->toBe([0]);
    });

    it('builds a tool-result message with the call id and the tool name', function (): void {
        $message = Message::toolResult('call_0', 'lookup', 'not found');

        expect($message->role)->toBe(Role::Tool)
            ->and($message->toolCallId)->toBe('call_0')
            ->and($message->toolName)->toBe('lookup')
            ->and($message->text())->toBe('not found')
            ->and($message->toolCalls)->toBe([]);
    });

    it('joins the text of all text parts without a separator and skips unknown parts', function (): void {
        $unknown = new class implements ContentPart {};
        $message = new Message(Role::User, [new TextPart('a'), $unknown, new TextPart('b')]);

        expect($message->text())->toBe('ab');
    });
});

describe('Message validation', function (): void {
    it('rule 1: throws when parts is not a list', function (): void {
        new Message(Role::User, untyped(['first' => new TextPart('a')]));
    })->throws(InvalidArgumentException::class);

    it('rule 1: throws when parts holds a value that is not a content part', function (): void {
        new Message(Role::User, untyped([new TextPart('a'), 'b']));
    })->throws(InvalidArgumentException::class);

    it('rule 2: throws when toolCalls holds a value that is not a tool call', function (): void {
        new Message(Role::Assistant, [new TextPart('a')], untyped(['not a call']));
    })->throws(InvalidArgumentException::class);

    it('rule 2: throws when toolCalls is not a list', function (): void {
        new Message(Role::Assistant, [new TextPart('a')], untyped(['first' => messageCall()]));
    })->throws(InvalidArgumentException::class);

    it('rule 3: throws for a system or user message', function (Role $role, mixed $parts, mixed $toolCalls, ?string $toolCallId, ?string $toolName): void {
        new Message($role, $parts, $toolCalls, $toolCallId, $toolName);
    })->with([
        'system, parts empty' => [Role::System, [], [], null, null],
        'system, with tool calls' => [Role::System, [new TextPart('a')], [messageCall()], null, null],
        'system, with a tool call id' => [Role::System, [new TextPart('a')], [], 'call_0', null],
        'system, with a tool name' => [Role::System, [new TextPart('a')], [], null, 'lookup'],
        'user, parts empty' => [Role::User, [], [], null, null],
        'user, with tool calls' => [Role::User, [new TextPart('a')], [messageCall()], null, null],
        'user, with a tool call id' => [Role::User, [new TextPart('a')], [], 'call_0', null],
        'user, with a tool name' => [Role::User, [new TextPart('a')], [], null, 'lookup'],
    ])->throws(InvalidArgumentException::class);

    it('rule 4: throws for an assistant message', function (mixed $parts, mixed $toolCalls, ?string $toolCallId, ?string $toolName): void {
        new Message(Role::Assistant, $parts, $toolCalls, $toolCallId, $toolName);
    })->with([
        'parts and tool calls both empty' => [[], [], null, null],
        'with a tool call id' => [[new TextPart('a')], [], 'call_0', null],
        'with a tool name' => [[new TextPart('a')], [], null, 'lookup'],
    ])->throws(InvalidArgumentException::class);

    it('rule 5: throws for a tool message', function (mixed $parts, mixed $toolCalls, ?string $toolCallId, ?string $toolName): void {
        new Message(Role::Tool, $parts, $toolCalls, $toolCallId, $toolName);
    })->with([
        'parts empty' => [[], [], 'call_0', 'lookup'],
        'with tool calls' => [[new TextPart('a')], [messageCall()], 'call_0', 'lookup'],
        'tool call id null' => [[new TextPart('a')], [], null, 'lookup'],
        'tool call id empty' => [[new TextPart('a')], [], '', 'lookup'],
        'tool name null' => [[new TextPart('a')], [], 'call_0', null],
    ])->throws(InvalidArgumentException::class);

    it('rule 6: throws when two tool calls of an assistant message have the same id', function (): void {
        Message::assistant('', messageCall('call_0'), messageCall('call_0'));
    })->throws(InvalidArgumentException::class);
});
