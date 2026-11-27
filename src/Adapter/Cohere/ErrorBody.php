<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Cohere;

use SensitiveParameter;

/**
 * Reads the error body of the Chat v2 format: `{"id": "…", "message": "…"}`, both optional
 * (`docs/providers/cohere.md`, "Error body shape…"). The format documents no code and no type: there is no word of
 * the provider to hand on, and the status alone decides the reason. Nothing is ever taken from the message.
 */
final readonly class ErrorBody
{
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
     * Whether a body that came with a successful status, or the data of an event of a stream, is the error body of
     * the format: its `message` is a sentence, where an answer has an object.
     *
     * @param array<mixed> $body
     */
    public static function isIn(#[SensitiveParameter] array $body): bool
    {
        return is_string($body['message'] ?? null) && $body['message'] !== '';
    }
}
