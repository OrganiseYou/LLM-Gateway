<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Cohere;

use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\Usage;
use SensitiveParameter;

/**
 * The tolerant reading of an answer in the Chat v2 format, the same for a whole response and for the events of a
 * stream. A field that is missing, null or of another type than expected is read as "not there": nothing here
 * throws.
 */
final readonly class Reading
{
    private const string NOT_AN_OBJECT = 'the arguments are not a JSON object';

    private const string NUMBERED_NAMES = 'the names of the arguments are the numbers of a list';

    private const int JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * The words the format ends an answer with, and what each means (`docs/providers/cohere.md`, "Finish reasons
     * and what each means"). A word that is not here means "other". The format has no word for an answer the
     * model declined to give or that was withheld.
     */
    private const array FINISH_WORDS = [
        'COMPLETE' => FinishReason::Stop,
        'STOP_SEQUENCE' => FinishReason::Stop,
        'MAX_TOKENS' => FinishReason::Length,
        'TOOL_CALL' => FinishReason::ToolCalls,
    ];

    /** The words that say the generation failed: there is no answer to hand on. */
    private const array FAULT_WORDS = ['ERROR', 'TIMEOUT'];

    /** A count above this is no count of tokens: a float this large is no whole number PHP can hold. */
    private const float LARGEST_COUNT = 9.0e18;

    /**
     * The value under a name when it is a string, otherwise an empty string.
     *
     * @param array<mixed> $holder
     */
    public static function string(#[SensitiveParameter] array $holder, string $name): string
    {
        return is_string($holder[$name] ?? null) ? $holder[$name] : '';
    }

    /**
     * The value under a name when it is an object or a list, otherwise an empty array.
     *
     * @param array<mixed> $holder
     * @return array<mixed>
     */
    public static function members(#[SensitiveParameter] array $holder, string $name): array
    {
        return is_array($holder[$name] ?? null) ? $holder[$name] : [];
    }

    /**
     * The tool calls a `tool_calls` member holds, the same for a whole answer and for an event of a stream: a list
     * (the documented shape of an answer) gives its entries that are objects, one object (the documented shape of a
     * stream event) is one call, and anything else is none. An entry that is no object (a string, a number, null, a
     * list, or an empty JSON value, which a decoded body cannot tell from an empty list) is read past.
     *
     * @return list<array<mixed>>
     */
    public static function callsIn(#[SensitiveParameter] mixed $toolCalls): array
    {
        if (self::isObject($toolCalls)) {
            /** @var array<mixed> $toolCalls */
            return [$toolCalls];
        }
        if (! is_array($toolCalls)) {
            return [];
        }

        $calls = [];
        foreach ($toolCalls as $entry) {
            if (self::isObject($entry)) {
                /** @var array<mixed> $entry */
                $calls[] = $entry;
            }
        }

        return $calls;
    }

    /**
     * Whether a decoded value is a JSON object with at least one member.
     */
    public static function isObject(#[SensitiveParameter] mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }

    /**
     * The id for a tool call whose server gave this id, when an earlier call of the same answer was already given
     * an id the adapter made up that is the same: the server's id with `_2`, `_3`, … after it, the first that is not
     * taken. An id that was handed on in an event is final, so the later call is the one that yields.
     *
     * @param array<array-key, true> $taken
     */
    public static function suffixed(#[SensitiveParameter] string $given, #[SensitiveParameter] array $taken): string
    {
        $suffix = 2;
        do {
            $id = $given . '_' . $suffix++;
        } while (isset($taken[$id]));

        return $id;
    }

    /**
     * A count as a whole number of 0 or more, or null when the value is none. The format types its counts as
     * doubles, so `12.0` is 12. A number with a fraction (`12.5`), a negative one, one too large for a whole
     * number of PHP, one that is not finite, a number written as text and anything else is not a count.
     */
    public static function count(#[SensitiveParameter] mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_float($value) || ! is_finite($value) || $value < 0.0 || $value > self::LARGEST_COUNT || floor($value) !== $value) {
            return null;
        }

        return (int) $value;
    }

    /**
     * The usage of an answer: the tokens the model counted, under `usage.tokens`. The billing figures beside them
     * (`billed_units`) are not token counts of the request and are not read. Each count stands alone: the format
     * gives the input and the output as one number each, so nothing is added up here.
     *
     * @param array<mixed> $holder a response, or the `delta` of the last event of a stream
     */
    public static function usage(#[SensitiveParameter] array $holder): Usage
    {
        $tokens = self::members(self::members($holder, 'usage'), 'tokens');

        return new Usage(self::count($tokens['input_tokens'] ?? null), self::count($tokens['output_tokens'] ?? null));
    }

    /**
     * The code of a finish reason that says the generation failed, in lower case, or null for every other word.
     */
    public static function faultOf(#[SensitiveParameter] ?string $word): ?string
    {
        return in_array($word, self::FAULT_WORDS, true) ? strtolower((string) $word) : null;
    }

    /**
     * What the end of an answer means. An answer that holds tool calls ends for tool calls unless it was cut off,
     * whatever word came with it. The word for tool calls on an answer without a call is "other": a loop that goes
     * on while the reason is "tool calls" would otherwise spin. A word that is not known, and no word, is "other".
     */
    public static function finishReason(#[SensitiveParameter] ?string $word, bool $hasToolCalls): FinishReason
    {
        $reason = self::FINISH_WORDS[$word ?? ''] ?? FinishReason::Other;
        if ($hasToolCalls) {
            return $reason === FinishReason::Length ? FinishReason::Length : FinishReason::ToolCalls;
        }

        return $reason === FinishReason::ToolCalls ? FinishReason::Other : $reason;
    }

    /**
     * The id of a tool call: the one the server gave, or, when it gave none, `call_<position>` with a number
     * after it when another call of the answer already has that id.
     *
     * @param int $position the place of the call among the calls of the answer, from 0
     * @param array<array-key, true> $taken the ids the other calls of the answer have, as the names of a set: asking
     *                                      it costs the same however many calls an answer holds
     * @param string $given the id the server gave, or an empty string
     */
    public static function freeId(int $position, #[SensitiveParameter] array $taken, #[SensitiveParameter] string $given = ''): string
    {
        if ($given !== '') {
            return $given;
        }

        $id = 'call_' . $position;
        for ($suffix = 2; isset($taken[$id]); $suffix++) {
            $id = 'call_' . $position . '_' . $suffix;
        }

        return $id;
    }

    /**
     * A tool call from what a server wrote. The format documents the arguments as a string that holds a JSON
     * object; the text is kept as it arrived. A text that holds no JSON object (cut off, a list, a number, nothing)
     * is a call whose arguments cannot be read, which is a value and not an error. Arguments that arrive as an
     * object, which no page documents, are read as that object.
     *
     * @param string $id not empty
     * @param mixed $arguments as they arrived: a string, a decoded object, or anything else
     */
    public static function toolCall(
        #[SensitiveParameter]
        string $id,
        #[SensitiveParameter]
        string $name,
        #[SensitiveParameter]
        mixed $arguments,
    ): ToolCall {
        if (is_string($arguments)) {
            // Decoded twice: as objects to see that the text is an object and not a list, as arrays for the caller.
            $decoded = is_object(json_decode($arguments, false)) ? json_decode($arguments, true) : null;

            return is_array($decoded)
                ? self::readable($id, $name, $decoded, $arguments)
                : ToolCall::unreadable($id, $name, self::NOT_AN_OBJECT, $arguments);
        }
        if ($arguments === []) {
            // A decoded body cannot tell an empty object from an empty list: an empty object of arguments it is.
            return ToolCall::decoded($id, $name, [], '{}');
        }

        $raw = $arguments === null ? '' : (string) json_encode($arguments, self::JSON);

        return is_array($arguments) && ! array_is_list($arguments)
            ? self::readable($id, $name, $arguments, $raw)
            : ToolCall::unreadable($id, $name, self::NOT_AN_OBJECT, $raw);
    }

    /**
     * A call with the arguments of a JSON object. PHP keeps the names "0", "1", … of an object as the numbers of a
     * list, and a tool call cannot hold a list: such arguments are given as they arrived, as not readable.
     *
     * @param array<mixed> $arguments the members of a JSON object
     */
    private static function readable(
        #[SensitiveParameter]
        string $id,
        #[SensitiveParameter]
        string $name,
        #[SensitiveParameter]
        array $arguments,
        #[SensitiveParameter]
        string $raw,
    ): ToolCall {
        if ($arguments !== [] && array_is_list($arguments)) {
            return ToolCall::unreadable($id, $name, self::NUMBERED_NAMES, $raw);
        }

        /** @var array<string, mixed> $arguments */
        return ToolCall::decoded($id, $name, $arguments, $raw);
    }
}
