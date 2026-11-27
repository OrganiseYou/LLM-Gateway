<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use OrganiseYou\LlmGateway\Contract\InvalidValue;

/**
 * What the fixtures of a format give in place of a fixture the format cannot have, with the reason. The shared
 * test set then skips that one scenario and shows the reason: nothing is left out without a word.
 */
final readonly class NotApplicable
{
    /**
     * @param string $reason why the format cannot have it, in one sentence
     */
    public function __construct(public string $reason)
    {
        if (trim($reason) === '') {
            throw new InvalidValue('Say why the scenario does not apply to this format.');
        }
    }
}
