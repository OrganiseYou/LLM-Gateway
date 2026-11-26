<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

/**
 * How one HTTP attempt ended.
 */
enum AttemptOutcome: string
{
    case Succeeded = 'succeeded';

    /** A response the library treats as a failure, also an error inside a successful status. */
    case HttpError = 'http_error';

    /** No usable response: the transport failed. */
    case TransportError = 'transport_error';
}
