<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Stream;

use OrganiseYou\LlmGateway\Contract\Usage;

/**
 * The provider reported the tokens of the call. At most once in a stream.
 */
final readonly class UsageReported implements StreamEvent
{
    public function __construct(public Usage $usage) {}
}
