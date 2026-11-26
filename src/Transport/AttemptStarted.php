<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\Operation;

/**
 * The report before one HTTP attempt.
 */
final readonly class AttemptStarted
{
    /**
     * @param string $presetId the id of the preset the caller built the gateway from
     * @param int $attempt from 1
     * @param HttpRequest $request read it with toArray(): the secret headers are masked there
     */
    public function __construct(
        public string $presetId,
        public Operation $operation,
        public string $traceId,
        public int $attempt,
        public HttpRequest $request,
    ) {
        if ($attempt < 1) {
            throw new InvalidArgumentException('The attempt must be 1 or more.');
        }
    }
}
