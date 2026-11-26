<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

/**
 * The observer that does nothing: the default.
 */
final readonly class NullAttemptObserver implements AttemptObserver
{
    public function attemptStarted(AttemptStarted $attempt): void {}

    public function attemptFinished(AttemptFinished $attempt): void {}
}
