<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

/**
 * The ways a call can fail that the shared test set plays, each with the reason every gateway must give for it and
 * whether it may be tried again.
 */
enum ContractFailure: string
{
    case Status401 = 'status 401';
    case Status403 = 'status 403';
    case Status429 = 'status 429';
    case Status408 = 'status 408';
    case Status500 = 'status 500';
    case Status503 = 'status 503';

    /**
     * A failure that is tried again by its status, in a response that itself says it must not be: a header or a
     * field the format documents. Only for a format that has such a sign (AdapterFixtures::retryForbidden()).
     */
    case RetryForbidden = 'status 503 in a response that says it must not be tried again';
    case Status302 = 'status 302';
    case Status400 = 'status 400';
    case Status404 = 'status 404';
    case Status422 = 'status 422';
    case UnreadableBody = 'status 200 with a body that cannot be read';
    case ErrorInBody = 'status 200 with an error in its body';
    case ConnectionFailed = 'a connection that fails';
    case TimedOut = 'a transport that runs out of time';
    case Redirected = 'a redirect the transport refuses';
    case Blocked = 'a request the transport of the host refuses';

    public function reason(): ErrorReason
    {
        return match ($this) {
            self::Status401, self::Status403 => ErrorReason::CredentialsRejected,
            self::Status429 => ErrorReason::RateLimited,
            self::Status408, self::Status500, self::Status503, self::RetryForbidden, self::Status302, self::ConnectionFailed, self::Redirected => ErrorReason::Unavailable,
            self::Status400, self::Status404, self::Status422 => ErrorReason::Rejected,
            self::UnreadableBody, self::ErrorInBody => ErrorReason::ProviderError,
            self::TimedOut => ErrorReason::Timeout,
            self::Blocked => ErrorReason::NotConfigured,
        };
    }

    /**
     * Whether the retry policy may try a call again after this failure.
     */
    public function isRetried(): bool
    {
        return in_array($this, [self::Status429, self::Status408, self::Status500, self::Status503, self::ConnectionFailed], true);
    }

    /**
     * The status of the response; null when no response arrives.
     */
    public function httpStatus(): ?int
    {
        return match ($this) {
            self::Status401 => 401,
            self::Status403 => 403,
            self::Status429 => 429,
            self::Status408 => 408,
            self::Status500 => 500,
            self::Status503, self::RetryForbidden => 503,
            self::Status302 => 302,
            self::Status400 => 400,
            self::Status404 => 404,
            self::Status422 => 422,
            self::UnreadableBody, self::ErrorInBody => 200,
            self::ConnectionFailed, self::TimedOut, self::Redirected, self::Blocked => null,
        };
    }

    /**
     * How the transport fails; null when a response arrives.
     */
    public function transportFailure(): ?TransportFailure
    {
        return match ($this) {
            self::ConnectionFailed => TransportFailure::ConnectionFailed,
            self::TimedOut => TransportFailure::TimedOut,
            self::Redirected => TransportFailure::Redirected,
            self::Blocked => TransportFailure::Blocked,
            default => null,
        };
    }
}
