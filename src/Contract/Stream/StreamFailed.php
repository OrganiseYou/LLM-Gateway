<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Stream;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\ToolCall;

/**
 * The end of a stream by failure, with what arrived before it.
 */
final readonly class StreamFailed implements StreamEvent
{
    /**
     * @param string $textSoFar the text of every text delta before the failure
     * @param list<ToolCall> $toolCallsSoFar the tool calls that were whole before the failure
     */
    public function __construct(
        public GatewayException $error,
        public string $textSoFar,
        public array $toolCallsSoFar,
    ) {
        self::assertToolCalls($toolCallsSoFar);
    }

    /**
     * @param array<mixed> $toolCallsSoFar
     */
    private static function assertToolCalls(array $toolCallsSoFar): void
    {
        if (! array_is_list($toolCallsSoFar)) {
            throw new InvalidArgumentException('The tool calls so far must be a list.');
        }
        foreach ($toolCallsSoFar as $toolCall) {
            if (! $toolCall instanceof ToolCall) {
                throw new InvalidArgumentException('The tool calls so far hold a value that is not a tool call.');
            }
        }
    }
}
