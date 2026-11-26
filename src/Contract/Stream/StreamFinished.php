<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Stream;

use OrganiseYou\LlmGateway\Contract\Answer;

/**
 * The end of a stream: the same answer a completion without streaming returns.
 */
final readonly class StreamFinished implements StreamEvent
{
    public function __construct(public Answer $answer) {}
}
