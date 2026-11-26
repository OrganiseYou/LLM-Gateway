<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

/**
 * Why a transport could not give a response.
 */
enum TransportFailure: string
{
    /** Could not connect, or the connection broke. */
    case ConnectionFailed = 'connection_failed';

    case TimedOut = 'timed_out';

    /** The other side answered with a redirect; it is never followed. */
    case Redirected = 'redirected';

    /** The host's own rule refused the request. */
    case Blocked = 'blocked';
}
