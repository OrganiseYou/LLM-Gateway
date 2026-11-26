<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * Why a call failed. The same eight reasons whatever answers the call.
 */
enum ErrorReason: string
{
    /** A required setting is missing, the credential kind does not fit, or the host's transport refused the address. */
    case NotConfigured = 'not_configured';

    /** The sign-in was refused, or the token supplier failed. */
    case CredentialsRejected = 'credentials_rejected';

    /** The other side asks to slow down. */
    case RateLimited = 'rate_limited';

    /** Server error, lost connection, or a redirect. */
    case Unavailable = 'unavailable';

    /** The request was refused: unknown model, invalid schema, a value out of range. */
    case Rejected = 'rejected';

    /** An answer without content, an error inside a successful response, a body that cannot be read. */
    case ProviderError = 'provider_error';

    /** The call's time ran out. */
    case Timeout = 'timeout';

    /** A capability that is lacking. */
    case NotSupported = 'not_supported';
}
