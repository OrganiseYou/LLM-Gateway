<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Anthropic;

use OrganiseYou\LlmGateway\Contract\ErrorReason;
use SensitiveParameter;

/**
 * Reads the error body of the Messages format: `{"type": "error", "error": {"type": …, "message": …, "details":
 * {"error_code": …}}, "request_id": …}`. The type is always there, the code only sometimes; new types and codes can
 * appear. A body that is not JSON tells nothing.
 *
 * Only words are taken from a body: never its message.
 */
final readonly class ErrorBody
{
    /** The code of a status 429 that means the spend cap of the account was reached: waiting does not lift it. */
    private const string SPEND_LIMIT = 'enforced_spend_limit_reached';

    /**
     * The body as an array, or null when it is not a JSON object or a JSON list.
     *
     * @return array<mixed>|null
     */
    public static function decoded(#[SensitiveParameter] string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether a body that came with a successful status, or the data of an event of a stream, is an error all the
     * same: it names itself with `"type": "error"`, or holds an `error` object.
     *
     * @param array<mixed> $body
     */
    public static function isIn(#[SensitiveParameter] array $body): bool
    {
        $error = $body['error'] ?? null;

        return ($body['type'] ?? null) === 'error' || (is_array($error) && $error !== []);
    }

    /**
     * The provider's own code: `error.details.error_code` when the body has one, otherwise `error.type`.
     *
     * @param array<mixed>|null $body
     */
    public static function code(#[SensitiveParameter] ?array $body): ?string
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        if (is_string($details['error_code'] ?? null) && $details['error_code'] !== '') {
            return $details['error_code'];
        }

        return is_string($error['type'] ?? null) && $error['type'] !== '' ? $error['type'] : null;
    }

    /**
     * Whether the body says the spend cap of the account was reached.
     *
     * @param array<mixed>|null $body
     */
    public static function saysSpendLimit(#[SensitiveParameter] ?array $body): bool
    {
        return self::code($body) === self::SPEND_LIMIT;
    }

    /**
     * The reason of an error that arrives as an event of a stream. The status of the response is 200 then, so the
     * type is the only sign: overloaded is "unavailable", a rate limit is "rate limited" (or "rejected" when its
     * code says the spend cap was reached), and every other type is an error of the provider.
     *
     * @param array<mixed> $body the data of the event
     */
    public static function reasonInAStream(#[SensitiveParameter] array $body): ErrorReason
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        return match ($error['type'] ?? null) {
            'overloaded_error' => ErrorReason::Unavailable,
            'rate_limit_error' => self::saysSpendLimit($body) ? ErrorReason::Rejected : ErrorReason::RateLimited,
            default => ErrorReason::ProviderError,
        };
    }
}
