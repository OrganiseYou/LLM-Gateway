<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\OpenAiCompatible;

use SensitiveParameter;

/**
 * Reads the error body of a server of the chat-completions format. Four shapes are documented, and several servers
 * document none: an `error` object with `code` and `type`, a flat object with `type` and `code`, a flat object
 * whose `error` is a word, and a body with only a `detail`, which is a sentence or a list with an item per field
 * that was refused. A body that is not JSON tells nothing.
 *
 * Only words are taken from a body: never its message.
 */
final readonly class ErrorBody
{
    /** The types that mean "no credit" or a spend limit: waiting does not help, so the call is not tried again. */
    private const array NO_CREDIT = ['insufficient_quota', 'exceeded_current_quota_error'];

    /** The types that mean "the provider is overloaded": not the limit of the customer. */
    private const array OVERLOADED = ['engine_overloaded_error', 'backend_out_of_capacity'];

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
     * Whether a body that came with a successful status, or a chunk of a stream, is an error all the same: it
     * has an `error` that is an object or a word, or it is the flat error body that names itself with
     * `"object": "error"`.
     *
     * @param array<mixed> $body
     */
    public static function isIn(#[SensitiveParameter] array $body): bool
    {
        $error = $body['error'] ?? null;

        return (is_array($error) && $error !== []) || (is_string($error) && $error !== '') || ($body['object'] ?? null) === 'error';
    }

    /**
     * The provider's own code, from the first place that has one: `error.code` when it is a word, `error.type`,
     * a top-level `error` that is a string, a top-level `type`, a top-level `code`.
     *
     * @param array<mixed>|null $body
     */
    public static function code(#[SensitiveParameter] ?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $error = $body['error'] ?? null;
        if (is_array($error)) {
            if (self::isWord($error['code'] ?? null)) {
                return $error['code'];
            }
            if (self::isText($error['type'] ?? null)) {
                return $error['type'];
            }
        }
        if (self::isText($error)) {
            return $error;
        }
        if (self::isText($body['type'] ?? null)) {
            return $body['type'];
        }

        $code = $body['code'] ?? null;
        if (is_int($code)) {
            return (string) $code;
        }

        return self::isText($code) ? $code : null;
    }

    /**
     * Whether the body says the account is out of credit or over a spend limit.
     *
     * @param array<mixed>|null $body
     */
    public static function saysNoCredit(#[SensitiveParameter] ?array $body): bool
    {
        return in_array(self::type($body), self::NO_CREDIT, true);
    }

    /**
     * Whether the body says the provider itself is overloaded.
     *
     * @param array<mixed>|null $body
     */
    public static function saysOverloaded(#[SensitiveParameter] ?array $body): bool
    {
        return in_array(self::type($body), self::OVERLOADED, true);
    }

    /**
     * The type of the error: `error.type`, or the flat `type`.
     *
     * @param array<mixed>|null $body
     */
    private static function type(#[SensitiveParameter] ?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $error = $body['error'] ?? null;
        if (is_array($error) && is_string($error['type'] ?? null)) {
            return $error['type'];
        }

        return is_string($body['type'] ?? null) ? $body['type'] : null;
    }

    /**
     * @phpstan-assert-if-true non-empty-string $value
     */
    private static function isText(#[SensitiveParameter] mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /**
     * A word: letters, digits and `_ . -`, with a letter in it. A number written as a string is no word.
     *
     * @phpstan-assert-if-true non-empty-string $value
     */
    private static function isWord(#[SensitiveParameter] mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9_.-]{1,64}$/D', $value) === 1;
    }
}
