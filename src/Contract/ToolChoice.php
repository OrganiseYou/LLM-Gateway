<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use InvalidArgumentException;

/**
 * Whether the model may, must not or must call a tool, and which one.
 */
final readonly class ToolChoice
{
    public function __construct(
        public ToolChoiceMode $mode,
        public ?string $toolName = null,
    ) {
        if ($mode === ToolChoiceMode::Tool) {
            if ($toolName === null || $toolName === '') {
                throw new InvalidArgumentException('The tool choice "tool" needs the name of the tool.');
            }

            return;
        }
        if ($toolName !== null) {
            throw new InvalidArgumentException('Only the tool choice "tool" takes a tool name.');
        }
    }

    public static function auto(): self
    {
        return new self(ToolChoiceMode::Auto);
    }

    public static function none(): self
    {
        return new self(ToolChoiceMode::None);
    }

    /**
     * The model must call some tool.
     */
    public static function required(): self
    {
        return new self(ToolChoiceMode::Required);
    }

    /**
     * The model must call this tool.
     */
    public static function tool(string $name): self
    {
        return new self(ToolChoiceMode::Tool, $name);
    }
}
