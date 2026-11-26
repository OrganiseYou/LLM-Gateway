<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

/**
 * Hears of every HTTP attempt, before and after it. The host decides what it stores.
 *
 * Whatever an observer throws is caught and dropped by its caller: reporting never changes the result of a call.
 */
interface AttemptObserver
{
    public function attemptStarted(AttemptStarted $attempt): void;

    /**
     * For a stream: called when the stream ends, breaks or is abandoned by the caller.
     */
    public function attemptFinished(AttemptFinished $attempt): void;
}
