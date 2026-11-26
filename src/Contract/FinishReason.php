<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * Why the model stopped.
 */
enum FinishReason: string
{
    /** The model ended its answer. */
    case Stop = 'stop';

    /** The model asks for tool calls. */
    case ToolCalls = 'tool_calls';

    /** Cut off at the token limit. */
    case Length = 'length';

    /** The provider withheld the output. */
    case ContentFilter = 'content_filter';

    /** A reason that fits none of the above. */
    case Other = 'other';
}
