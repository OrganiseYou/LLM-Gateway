<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\ContentPart;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\TextPart;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\Usage;

function opaqueCall(string $id = 'call_0'): ToolCall
{
    return ToolCall::decoded($id, 'lookup', ['q' => 'x'], '{"q":"x"}');
}

/**
 * @param list<ToolCall> $toolCalls
 */
function answerWithPart(string $text, array $toolCalls, ?OpaquePart $opaque): Answer
{
    return new Answer($text, null, $toolCalls, $toolCalls === [] ? FinishReason::Stop : FinishReason::ToolCalls, 'model-a', new Usage(3, 2), 0, 1, 'trace-1', $opaque);
}

describe('OpaquePart', function (): void {
    it('is a final readonly content part with the one field data, kept as it was given', function (): void {
        $data = "{\"f\":\"zz\"}\n  any text, also with spaces at its end  ";
        $part = new OpaquePart($data);
        $class = new ReflectionClass(OpaquePart::class);

        expect($part)->toBeInstanceOf(ContentPart::class)
            ->and($part->data)->toBe($data)
            ->and($class->isFinal())->toBeTrue()
            ->and($class->isReadOnly())->toBeTrue()
            ->and(array_map(fn(ReflectionProperty $property): string => $property->getName(), $class->getProperties()))->toBe(['data']);
    });

    it('refuses empty data', function (): void {
        expect(fn() => new OpaquePart(''))->toThrow(InvalidValue::class, 'must not be empty');
    });

    it('refuses data that is not valid UTF-8', function (): void {
        expect(fn() => new OpaquePart("caf\xE9"))->toThrow(InvalidArgumentException::class, 'must be valid UTF-8');
    });

    it('keeps the refused data out of the refusal and its trace', function (): void {
        withExceptionIgnoreArgs(false, function (): void {
            try {
                new OpaquePart(LEAK_MARKER . "\xFF");
                $refusal = null;
            } catch (InvalidValue $thrown) {
                $refusal = $thrown;
            }

            expect($refusal)->not->toBeNull()
                ->and(OrganiseYou\LlmGateway\Testing\OutputForms::holding(exceptionForms($refusal ?? new LogicException('not refused')), 'MARKER'))->toBe([]);
        });
    });

    it('marks its data as a sensitive parameter', function (): void {
        $parameter = (new ReflectionMethod(OpaquePart::class, '__construct'))->getParameters()[0];

        expect($parameter->getAttributes(SensitiveParameter::class))->not->toBe([]);
    });
});

describe('a message with an opaque part', function (): void {
    it('accepts one in an assistant message, wherever it stands, and leaves it out of the text', function (): void {
        $part = new OpaquePart('data');

        $first = new Message(Role::Assistant, [$part, new TextPart('one '), new TextPart('moment')], [opaqueCall()]);
        $last = new Message(Role::Assistant, [new TextPart('one moment'), $part]);
        $alone = new Message(Role::Assistant, [$part], [opaqueCall()]);

        expect($first->text())->toBe('one moment')
            ->and($first->parts[0])->toBe($part)
            ->and($last->text())->toBe('one moment')
            ->and($alone->text())->toBe('');
    });

    it('refuses an assistant message whose only content is an opaque part', function (): void {
        expect(fn() => new Message(Role::Assistant, [new OpaquePart('data')]))
            ->toThrow(InvalidValue::class, 'An opaque part needs a text or a tool call beside it.')
            // An empty text beside it is a text part: the message of an answer that was cut off can be rebuilt.
            ->and((new Message(Role::Assistant, [new OpaquePart('data'), new TextPart('')]))->text())->toBe('');
    });

    it('refuses two in one assistant message', function (): void {
        expect(fn() => new Message(Role::Assistant, [new OpaquePart('a'), new TextPart('x'), new OpaquePart('b')]))
            ->toThrow(InvalidValue::class, 'one opaque part, not more');
    });

    it('refuses one in a message of another role', function (Role $role, ?string $toolCallId, ?string $toolName): void {
        expect(fn() => new Message($role, [new TextPart('x'), new OpaquePart('a')], [], $toolCallId, $toolName))
            ->toThrow(InvalidArgumentException::class, 'Only an assistant message can hold an opaque part.');
    })->with([
        'system' => [Role::System, null, null],
        'user' => [Role::User, null, null],
        'tool' => [Role::Tool, 'call_0', 'lookup'],
    ]);

    it('builds no opaque part in assistant(): a message written by hand has none', function (): void {
        expect(Message::assistant('one moment', opaqueCall())->parts)->toEqual([new TextPart('one moment')]);
    });
});

describe('an answer with an opaque part', function (): void {
    it('has none unless one is given: the parameter is the last and optional', function (): void {
        $parameters = (new ReflectionMethod(Answer::class, '__construct'))->getParameters();
        $last = $parameters[count($parameters) - 1];

        expect($last->getName())->toBe('opaque')
            ->and($last->isDefaultValueAvailable())->toBeTrue()
            ->and($last->getDefaultValue())->toBeNull()
            ->and($last->getAttributes(SensitiveParameter::class))->not->toBe([])
            ->and((new Answer('hello', null, [], FinishReason::Stop, 'model-a', new Usage(3, 2), 0, 1, 'trace-1'))->opaque)->toBeNull();
    });

    it('toMessage puts the opaque part first, then the text, and keeps the tool calls', function (): void {
        $part = new OpaquePart('data');
        $call = opaqueCall();
        $message = answerWithPart('one moment', [$call], $part)->toMessage();

        expect($message->role)->toBe(Role::Assistant)
            ->and($message->parts)->toEqual([$part, new TextPart('one moment')])
            ->and($message->parts[0])->toBe($part)
            ->and($message->text())->toBe('one moment')
            ->and($message->toolCalls)->toBe([$call]);
    });

    it('toMessage leaves the text part out when the text is empty and there are tool calls', function (): void {
        $part = new OpaquePart('data');

        expect(answerWithPart('', [opaqueCall()], $part)->toMessage()->parts)->toBe([$part]);
    });

    it('toMessage of a text answer with an opaque part holds both', function (): void {
        $part = new OpaquePart('data');

        expect(answerWithPart('hello', [], $part)->toMessage()->parts)->toEqual([$part, new TextPart('hello')]);
    });

    it('toMessage builds the message it built before when there is no opaque part', function (): void {
        $call = opaqueCall();

        expect(answerWithPart('one moment', [$call], null)->toMessage())->toEqual(Message::assistant('one moment', $call))
            ->and(answerWithPart('', [$call], null)->toMessage())->toEqual(Message::assistant('', $call))
            ->and(answerWithPart('hello', [], null)->toMessage())->toEqual(Message::assistant('hello'));
    });

    it('still cannot become a message without text and without tool calls, opaque part or not', function (): void {
        expect(fn() => answerWithPart('', [], new OpaquePart('data'))->toMessage())->toThrow(LogicException::class);
    });

    it('shows the data of the part in a dump: it is content, stored by the caller, not a secret', function (): void {
        $answer = answerWithPart('hello', [], new OpaquePart('data-of-the-part'));

        expect(print_r($answer, true))->toContain('data-of-the-part')
            ->and(print_r($answer->toMessage(), true))->toContain('data-of-the-part');
    });
});
