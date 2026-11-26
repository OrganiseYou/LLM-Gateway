<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\Internal\Rules;

/**
 * The five answers of the connection check.
 */
final readonly class ConnectionReport
{
    public function __construct(
        public CheckResult $signIn,
        public CheckResult $modelExists,
        public CheckResult $toolCall,
        public CheckResult $structuredAnswer,
        public CheckResult $stream,
        public string $traceId,
    ) {
        if (! Rules::isTraceId($traceId)) {
            throw new InvalidArgumentException('The trace id must match ^[A-Za-z0-9_.:-]{1,128}$.');
        }
    }

    /**
     * True when no answer is "no" and none is "not run". "Not supported" is not a failure of the connection.
     */
    public function allPassed(): bool
    {
        foreach ([$this->signIn, $this->modelExists, $this->toolCall, $this->structuredAnswer, $this->stream] as $result) {
            if ($result->status === CheckStatus::No || $result->status === CheckStatus::NotRun) {
                return false;
            }
        }

        return true;
    }
}
