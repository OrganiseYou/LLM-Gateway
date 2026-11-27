<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

use OrganiseYou\LlmGateway\Contract\Internal\Traces;
use RuntimeException;
use Throwable;

/**
 * The one error of a transport. The library reads only the failure; it never copies the message or the previous
 * exception anywhere, so a host's transport may put there what helps the host.
 *
 * Its own trace keeps no argument of any call: it is built while the request, with its revealed sign-in header, is
 * on the stack. A previous exception the host chains is the host's own and is left as it is.
 */
final class TransportException extends RuntimeException
{
    public function __construct(
        public readonly TransportFailure $failure,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        // Built while the request is on the stack: the trace keeps where, not with what.
        Traces::withoutArguments($this);
    }
}
