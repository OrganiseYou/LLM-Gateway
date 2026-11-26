<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Stream;

use InvalidArgumentException;

/**
 * A piece of the answer's text.
 */
final readonly class TextDelta implements StreamEvent
{
    public function __construct(public string $text)
    {
        if ($text === '') {
            throw new InvalidArgumentException('A text delta must not be empty.');
        }
    }
}
