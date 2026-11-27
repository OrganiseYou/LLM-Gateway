<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Stream;

use OrganiseYou\LlmGateway\Contract\InvalidValue;

/**
 * A piece of the answer's text.
 */
final readonly class TextDelta implements StreamEvent
{
    public function __construct(public string $text)
    {
        if ($text === '') {
            throw new InvalidValue('A text delta must not be empty.');
        }
    }
}
