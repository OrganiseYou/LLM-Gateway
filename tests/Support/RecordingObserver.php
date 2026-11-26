<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Transport\AttemptFinished;
use OrganiseYou\LlmGateway\Transport\AttemptObserver;
use OrganiseYou\LlmGateway\Transport\AttemptStarted;
use RuntimeException;

/**
 * An observer that keeps every report, and throws after it kept one when the test asks for that.
 */
final class RecordingObserver implements AttemptObserver
{
    /** @var list<AttemptStarted> */
    public array $started = [];

    /** @var list<AttemptFinished> */
    public array $finished = [];

    public function __construct(
        private readonly bool $throwsBefore = false,
        private readonly bool $throwsAfter = false,
        private readonly bool $errorsBefore = false,
        private readonly ?FakeClock $clock = null,
        private readonly float $secondsAfter = 0.0,
    ) {}

    public function attemptStarted(AttemptStarted $attempt): void
    {
        $this->started[] = $attempt;
        if ($this->errorsBefore) {
            throw new \Error('The observer broke before the attempt.');
        }
        if ($this->throwsBefore) {
            throw new RuntimeException('The observer failed before the attempt.');
        }
    }

    public function attemptFinished(AttemptFinished $attempt): void
    {
        $this->finished[] = $attempt;
        // An observer that takes its time: the clock of the test moves while it works.
        $this->clock?->advance($this->secondsAfter);
        if ($this->throwsAfter) {
            throw new \Error('The observer failed after the attempt.');
        }
    }
}
