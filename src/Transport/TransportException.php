<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

use RuntimeException;
use Throwable;

/**
 * The one error of a transport. The library reads only the failure; it never copies the message or the previous
 * exception anywhere, so a host's transport may put there what helps the host.
 */
final class TransportException extends RuntimeException
{
    public function __construct(
        public readonly TransportFailure $failure,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
