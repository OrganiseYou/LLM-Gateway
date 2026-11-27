<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\OpenAiCompatible;

use OrganiseYou\LlmGateway\Contract\Support;

/**
 * How a server of the chat-completions format is made to call one named tool: the values of the option
 * `named_tool_choice`.
 */
enum NamedToolChoice: string
{
    /** The named-function object in `tool_choice`. */
    case NamedObject = 'object';

    /** The word for "must call some tool", with only that tool in `tools`. */
    case RequiredSingle = 'required_single';

    /** Not at all. */
    case Unsupported = 'unsupported';

    /**
     * What the caller is told about a forced tool call on a server that takes it this way.
     */
    public function support(): Support
    {
        return match ($this) {
            self::NamedObject => Support::Native,
            self::RequiredSingle => Support::Emulated,
            self::Unsupported => Support::None,
        };
    }
}
