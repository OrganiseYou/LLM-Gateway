<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Gemini;

use SensitiveParameter;

/**
 * Reads the error body of the generate-content format: `{"error": {"code": 429, "message": …, "status":
 * "RESOURCE_EXHAUSTED", "details": [{"@type": …, "reason": …, "domain": …, "metadata": {…}}]}}`. `status` is an
 * upper-case word, `details` a list of typed objects of which some carry a `reason`. A body that is not JSON tells
 * nothing.
 *
 * Only words are taken from a body: never its message.
 */
final readonly class ErrorBody
{
    /** The reason the format documents for a key it does not accept, in a response with the status 400. */
    private const string INVALID_KEY = 'API_KEY_INVALID';

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
     * Whether a body that came with a successful status, or a chunk of a stream, holds an `error` object.
     *
     * @param array<mixed> $body
     */
    public static function isIn(#[SensitiveParameter] array $body): bool
    {
        return is_array($body['error'] ?? null) && $body['error'] !== [];
    }

    /**
     * The provider's own code, in lower case: the first `reason` among the details, otherwise the `status` word.
     *
     * @param array<mixed>|null $body
     */
    public static function code(#[SensitiveParameter] ?array $body): ?string
    {
        $reasons = self::reasons($body);
        if ($reasons !== []) {
            return strtolower($reasons[0]);
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        return is_string($error['status'] ?? null) && $error['status'] !== '' ? strtolower($error['status']) : null;
    }

    /**
     * Whether a detail of the body says the key is not valid.
     *
     * @param array<mixed>|null $body
     */
    public static function saysInvalidKey(#[SensitiveParameter] ?array $body): bool
    {
        return in_array(self::INVALID_KEY, self::reasons($body), true);
    }

    /**
     * The `reason` of every detail that has one, as written, in the order of the details.
     *
     * @param array<mixed>|null $body
     * @return list<string>
     */
    private static function reasons(#[SensitiveParameter] ?array $body): array
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        $reasons = [];
        foreach ($details as $detail) {
            if (is_array($detail) && is_string($detail['reason'] ?? null) && $detail['reason'] !== '') {
                $reasons[] = $detail['reason'];
            }
        }

        return $reasons;
    }
}
