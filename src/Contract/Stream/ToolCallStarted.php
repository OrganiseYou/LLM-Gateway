<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Stream;

use InvalidArgumentException;

/**
 * A tool call begins.
 */
final readonly class ToolCallStarted implements StreamEvent
{
    /**
     * @param int $index the position of the call in the answer, from 0
     */
    public function __construct(
        public int $index,
        public string $id,
        public string $name,
    ) {
        if ($index < 0) {
            throw new InvalidArgumentException('The index of a tool call must be 0 or more.');
        }
    }
}
