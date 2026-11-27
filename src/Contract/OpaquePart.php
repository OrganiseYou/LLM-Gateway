<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use SensitiveParameter;

/**
 * Data that came with an answer and must go back with it, unchanged, in the next request of the same conversation.
 *
 * It has no meaning in the contract: do not read it, do not change it and do not show it. Answer::toMessage() puts
 * it in the assistant message of the next round. A caller that stores a conversation stores `$part->data` as it is
 * and builds the part again with `new OpaquePart($data)`. A gateway uses the part only when it is its own and the
 * message it stands in is the one it came with; in every other case the message is sent without it.
 */
final readonly class OpaquePart implements ContentPart
{
    public function __construct(#[SensitiveParameter] public string $data)
    {
        if ($data === '') {
            throw new InvalidValue('The data of an opaque part must not be empty.');
        }
        if (preg_match('//u', $data) !== 1) {
            throw new InvalidValue('The data of an opaque part must be valid UTF-8.');
        }
    }
}
