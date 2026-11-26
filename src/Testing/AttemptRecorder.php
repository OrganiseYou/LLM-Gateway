<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use OrganiseYou\LlmGateway\Transport\AttemptFinished;
use OrganiseYou\LlmGateway\Transport\AttemptObserver;
use OrganiseYou\LlmGateway\Transport\AttemptStarted;

/**
 * An observer for tests: it keeps every report it gets, in order.
 */
final class AttemptRecorder implements AttemptObserver
{
    /** @var list<AttemptStarted> */
    private array $started = [];

    /** @var list<AttemptFinished> */
    private array $finished = [];

    public function attemptStarted(AttemptStarted $attempt): void
    {
        $this->started[] = $attempt;
    }

    public function attemptFinished(AttemptFinished $attempt): void
    {
        $this->finished[] = $attempt;
    }

    /**
     * @return list<AttemptStarted>
     */
    public function started(): array
    {
        return $this->started;
    }

    /**
     * @return list<AttemptFinished>
     */
    public function finished(): array
    {
        return $this->finished;
    }
}
