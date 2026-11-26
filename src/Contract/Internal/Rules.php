<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Internal;

use SensitiveParameter;

/**
 * The rules that several values share, each written once. Every method only answers: the class that asks throws
 * its own refusal, so no value is ever in a refusal made here.
 *
 * @internal not part of the contract: a host project must not call it, and it can change in any release
 */
final class Rules
{
    /**
     * Letters, digits and _ . : - up to 128 characters.
     */
    public static function isTraceId(string $traceId): bool
    {
        return preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $traceId) === 1;
    }

    /**
     * Not empty, valid UTF-8 and no control character. The rule of a model name, and of every other name that is
     * shown or sent as it is.
     */
    public static function isModelName(#[SensitiveParameter] string $name): bool
    {
        return $name !== '' && preg_match('/\p{Cc}/u', $name) === 0;
    }

    /**
     * The provider's own code, lower-cased, when it is then a plain word of at most 64 characters; otherwise null.
     */
    public static function plainProviderCode(#[SensitiveParameter] ?string $providerCode): ?string
    {
        if ($providerCode === null) {
            return null;
        }

        $lowered = strtolower($providerCode);

        return preg_match('/^[a-z0-9_.-]{1,64}$/D', $lowered) === 1 ? $lowered : null;
    }

    /**
     * A finite number of seconds above 0.
     */
    public static function isTimeout(float $seconds): bool
    {
        return is_finite($seconds) && $seconds > 0.0;
    }

    /**
     * A token of HTTP: the form of a header name.
     */
    public static function isHttpToken(#[SensitiveParameter] string $name): bool
    {
        return preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) === 1;
    }
}
