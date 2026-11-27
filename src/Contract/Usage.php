<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * The tokens a call used. A count is null when the provider did not report it.
 */
final readonly class Usage
{
    public function __construct(
        public ?int $inputTokens,
        public ?int $outputTokens,
    ) {
        if ($inputTokens !== null && $inputTokens < 0) {
            throw new InvalidValue('The input tokens must be 0 or more.');
        }
        if ($outputTokens !== null && $outputTokens < 0) {
            throw new InvalidValue('The output tokens must be 0 or more.');
        }
    }
}
