<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use InvalidArgumentException;

/**
 * One message of a conversation. A tool result is a message too: the library never runs a tool.
 */
final readonly class Message
{
    /**
     * @param list<ContentPart> $parts the content, always a list
     * @param list<ToolCall> $toolCalls calls the assistant asked for
     * @param string|null $toolCallId for role Tool: the id of the call this message answers
     * @param string|null $toolName for role Tool: the name of the tool that was called
     */
    public function __construct(
        public Role $role,
        public array $parts,
        public array $toolCalls = [],
        public ?string $toolCallId = null,
        public ?string $toolName = null,
    ) {
        self::assertListOf($parts, ContentPart::class, 'parts');
        self::assertListOf($toolCalls, ToolCall::class, 'toolCalls');

        match ($role) {
            Role::System, Role::User => $this->assertPlain(),
            Role::Assistant => $this->assertAssistant(),
            Role::Tool => $this->assertToolResult(),
        };
    }

    public static function system(string $text): self
    {
        return new self(Role::System, [new TextPart($text)]);
    }

    public static function user(string $text): self
    {
        return new self(Role::User, [new TextPart($text)]);
    }

    public static function assistant(string $text, ToolCall ...$toolCalls): self
    {
        $parts = $text === '' && $toolCalls !== [] ? [] : [new TextPart($text)];

        return new self(Role::Assistant, $parts, array_values($toolCalls));
    }

    public static function toolResult(string $toolCallId, string $toolName, string $text): self
    {
        return new self(Role::Tool, [new TextPart($text)], [], $toolCallId, $toolName);
    }

    /**
     * The text of all text parts, joined without a separator.
     */
    public function text(): string
    {
        $text = '';
        foreach ($this->parts as $part) {
            if ($part instanceof TextPart) {
                $text .= $part->text;
            }
        }

        return $text;
    }

    private function assertPlain(): void
    {
        if ($this->parts === []) {
            throw new InvalidArgumentException('A system or user message needs at least one content part.');
        }
        if ($this->toolCalls !== []) {
            throw new InvalidArgumentException('A system or user message cannot hold tool calls.');
        }
        if ($this->toolCallId !== null || $this->toolName !== null) {
            throw new InvalidArgumentException('A system or user message cannot hold a tool call id or a tool name.');
        }
    }

    private function assertAssistant(): void
    {
        if ($this->parts === [] && $this->toolCalls === []) {
            throw new InvalidArgumentException('An assistant message needs a content part or a tool call.');
        }
        if ($this->toolCallId !== null || $this->toolName !== null) {
            throw new InvalidArgumentException('An assistant message cannot hold a tool call id or a tool name.');
        }

        $seen = [];
        foreach ($this->toolCalls as $toolCall) {
            if (isset($seen[$toolCall->id])) {
                throw new InvalidArgumentException('Two tool calls of an assistant message have the same id.');
            }
            $seen[$toolCall->id] = true;
        }
    }

    private function assertToolResult(): void
    {
        if ($this->parts === []) {
            throw new InvalidArgumentException('A tool message needs at least one content part.');
        }
        if ($this->toolCalls !== []) {
            throw new InvalidArgumentException('A tool message cannot hold tool calls.');
        }
        if ($this->toolCallId === null || $this->toolCallId === '') {
            throw new InvalidArgumentException('A tool message needs the id of the call it answers.');
        }
        if ($this->toolName === null) {
            throw new InvalidArgumentException('A tool message needs the name of the tool that was called.');
        }
    }

    /**
     * @param array<mixed> $values
     * @param class-string $class
     */
    private static function assertListOf(array $values, string $class, string $field): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException(sprintf('The %s of a message must be a list.', $field));
        }
        foreach ($values as $value) {
            if (! $value instanceof $class) {
                throw new InvalidArgumentException(sprintf('The %s of a message holds a value of the wrong type.', $field));
            }
        }
    }
}
