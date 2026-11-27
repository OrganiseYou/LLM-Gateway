<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Stream;

use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\ToolCall;

/**
 * A tool call is whole: its arguments are decoded, or it holds why they could not be read.
 */
final readonly class ToolCallCompleted implements StreamEvent
{
    /**
     * @param int $index the position of the call in the answer, from 0
     */
    public function __construct(
        public int $index,
        public ToolCall $toolCall,
    ) {
        if ($index < 0) {
            throw new InvalidValue('The index of a tool call must be 0 or more.');
        }
    }
}
