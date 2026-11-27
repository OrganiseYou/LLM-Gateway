<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\OpenAiCompatible;

/**
 * Whether the reasoning that came with an assistant answer goes back with it, and where it sits: the values of the
 * option `reasoning_carrier`.
 */
enum ReasoningCarrier: string
{
    /** Reasoning is read past and never sent back. */
    case None = 'none';

    /** A string beside the content of the assistant message; it goes back in the same place. */
    case MessageField = 'message_field';

    /** Entries in the content of the answer that are no text; the whole list goes back as the content. */
    case ContentEntries = 'content_entries';
}
