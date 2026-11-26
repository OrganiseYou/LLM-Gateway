<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\Operation;
use SensitiveParameter;

/**
 * The report after one HTTP attempt.
 */
final readonly class AttemptFinished
{
    /**
     * @param string $presetId the id of the preset the caller built the gateway from
     * @param int $attempt from 1
     * @param HttpRequest $request read it with toArray(): the secret headers are masked there
     * @param HttpResponse|null $response null when no response arrived; for a stream the status, the headers and
     *                                    the bytes read so far as the body
     * @param TransportFailure|null $transportFailure not null exactly when the outcome is a transport error
     */
    public function __construct(
        public string $presetId,
        public Operation $operation,
        public string $traceId,
        public int $attempt,
        public HttpRequest $request,
        #[SensitiveParameter]
        public ?HttpResponse $response,
        public AttemptOutcome $outcome,
        public ?TransportFailure $transportFailure,
        public bool $willRetry,
        public int $durationMs,
    ) {
        if ($attempt < 1) {
            throw new InvalidArgumentException('The attempt must be 1 or more.');
        }
        if (($outcome === AttemptOutcome::TransportError) !== ($transportFailure !== null)) {
            throw new InvalidArgumentException(
                'A transport failure goes with the outcome transport_error, and only with it.',
            );
        }
        if ($durationMs < 0) {
            throw new InvalidArgumentException('The duration must be 0 or more.');
        }
    }
}
