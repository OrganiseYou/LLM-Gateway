<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * A piece of text in a message.
 */
final readonly class TextPart implements ContentPart
{
    public function __construct(public string $text)
    {
        if (preg_match('//u', $text) !== 1) {
            throw new InvalidValue('The text of a text part must be valid UTF-8.');
        }
    }
}
