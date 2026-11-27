<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * Why the model stopped.
 *
 * An answer without text and without a tool call exists only with Length, ContentFilter or Refusal. A `match` over
 * this enum needs an arm for every case or a default arm: a later version may add a case.
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

    /** The model declined to answer. Its text, when it gave one, says so in its own words. */
    case Refusal = 'refusal';

    /** A reason that fits none of the above. */
    case Other = 'other';
}
