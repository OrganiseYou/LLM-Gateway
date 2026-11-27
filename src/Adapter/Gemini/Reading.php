<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Gemini;

use OrganiseYou\LlmGateway\Adapter\Shared\RawJson;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\Usage;
use SensitiveParameter;

/**
 * The tolerant reading of an answer in the generate-content format, the same for a whole response and for a chunk
 * of a stream. A field that is missing, null or of another type than expected is read as "not there": nothing here
 * throws.
 */
final readonly class Reading
{
    /** The counts of `usageMetadata` that are read: the input, then the two that make the output. */
    public const array COUNTS = ['promptTokenCount', 'candidatesTokenCount', 'thoughtsTokenCount'];

    /**
     * A model name that can stand in the path of an address as one segment: letters, digits, dots, underscores and
     * hyphens, starting with a letter or a digit, at most 128 characters. Anything else (a slash, a colon, a
     * question mark, a percent sign, a whole address) is refused, never encoded. The provider file gives no
     * length: 128 is a bound of this library, so that a name cannot make an address of any length.
     */
    private const string MODEL_IN_A_PATH = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';

    /** The form of an id this adapter gives to a call that came without one. */
    private const string MADE_ID = '/^call-[0-9]+(?:-[0-9]+)?$/D';

    private const string NOT_AN_OBJECT = 'the arguments are not a JSON object';

    private const string NUMBERED_NAMES = 'the names of the arguments are the numbers of a list';

    private const int JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** One of the two spellings of a finish reason starts with this. */
    private const string FINISH_PREFIX = 'FINISH_REASON_';

    /**
     * The words the format ends an answer with, and what each means, without the prefix of the second spelling. A
     * word that is not here (`LANGUAGE`, `CONTINUATION`, `TOO_MANY_TOOL_CALLS`, `OTHER`, the unset value, a word
     * of a later version) means "other". The format has no word for an answer that ends with a tool call.
     */
    private const array FINISH_WORDS = [
        'STOP' => FinishReason::Stop,
        'MAX_TOKENS' => FinishReason::Length,
        'SAFETY' => FinishReason::ContentFilter,
        'RECITATION' => FinishReason::ContentFilter,
        'BLOCKLIST' => FinishReason::ContentFilter,
        'PROHIBITED_CONTENT' => FinishReason::ContentFilter,
        'SPII' => FinishReason::ContentFilter,
        'MODEL_ARMOR' => FinishReason::ContentFilter,
    ];

    /** The words that say the answer itself is a fault: there is no answer to hand on. */
    private const array FAULT_WORDS = ['MALFORMED_FUNCTION_CALL', 'UNEXPECTED_TOOL_CALL', 'MALFORMED_RESPONSE', 'MISSING_THOUGHT_SIGNATURE'];

    /** The two values of a block reason that say nothing was blocked: the unset value, in both spellings. */
    private const array NOT_BLOCKED = ['BLOCK_REASON_UNSPECIFIED', 'BLOCKED_REASON_UNSPECIFIED'];

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
     * Whether a model name can stand in the path of an address without changing what the address means.
     */
    public static function isModelInAPath(#[SensitiveParameter] string $model): bool
    {
        return preg_match(self::MODEL_IN_A_PATH, $model) === 1 && ! str_contains($model, '..');
    }

    /**
     * The first candidate of a response or of a chunk of a stream, or null when it holds none. The adapter never
     * asks for more than one.
     *
     * @param array<mixed> $body
     * @return array<mixed>|null
     */
    public static function candidate(#[SensitiveParameter] array $body): ?array
    {
        $candidates = $body['candidates'] ?? null;
        $first = is_array($candidates) && array_is_list($candidates) ? ($candidates[0] ?? null) : null;

        return is_array($first) ? $first : null;
    }

    /**
     * The parts of a candidate: the list under `content.parts`, or an empty list.
     *
     * @param array<mixed> $candidate
     * @return array<mixed>
     */
    public static function parts(#[SensitiveParameter] array $candidate): array
    {
        return self::members(self::members($candidate, 'content'), 'parts');
    }

    /**
     * Why the prompt of a response without a candidate was blocked, in lower case, or null when the response does
     * not say it was.
     *
     * @param array<mixed> $body
     */
    public static function blockReason(#[SensitiveParameter] array $body): ?string
    {
        $reason = self::string(self::members($body, 'promptFeedback'), 'blockReason');

        return $reason === '' || in_array($reason, self::NOT_BLOCKED, true) ? null : strtolower($reason);
    }

    /**
     * The counts known so far with those of one more `usageMetadata` laid over them: of each count the later
     * value, when it is a whole number of 0 or more. Every chunk of a stream may tell the usage; a count that is
     * null or missing leaves the earlier value as it is.
     *
     * @param array<string, int> $known
     * @param array<mixed> $holder a response, or a chunk of a stream
     * @return array<string, int>
     */
    public static function counts(array $known, #[SensitiveParameter] array $holder): array
    {
        $usage = self::members($holder, 'usageMetadata');
        foreach (self::COUNTS as $count) {
            if (is_int($usage[$count] ?? null) && $usage[$count] >= 0) {
                $known[$count] = $usage[$count];
            }
        }

        return $known;
    }

    /**
     * The usage of an answer from its counts. The input is the prompt count. The output is the count of the
     * candidates plus the count of the thoughts, which the format tells apart: whichever of the two is there. A
     * sum that is too large for a whole number of PHP is not a count: the output is then not known.
     *
     * @param array<string, int> $counts
     */
    public static function usage(array $counts): Usage
    {
        $output = isset($counts['candidatesTokenCount']) || isset($counts['thoughtsTokenCount'])
            ? ($counts['candidatesTokenCount'] ?? 0) + ($counts['thoughtsTokenCount'] ?? 0)
            : null;

        // Past the largest whole number PHP adds to a float.
        return new Usage($counts['promptTokenCount'] ?? null, is_int($output) ? $output : null);
    }

    /**
     * A finish reason without the prefix of its second spelling: `FINISH_REASON_STOP` is `STOP`.
     */
    public static function finishWord(#[SensitiveParameter] ?string $word): string
    {
        $word ??= '';

        return str_starts_with($word, self::FINISH_PREFIX) ? substr($word, strlen(self::FINISH_PREFIX)) : $word;
    }

    /**
     * The code of a finish reason that says the answer is a fault, in lower case and in one spelling, or null for
     * every other word.
     */
    public static function faultOf(#[SensitiveParameter] ?string $word): ?string
    {
        $word = self::finishWord($word);

        return in_array($word, self::FAULT_WORDS, true) ? strtolower($word) : null;
    }

    /**
     * What the end of an answer means. An answer that holds tool calls ends for tool calls unless it was cut off,
     * whatever word came with it: the format has no word of its own for that. A word that is not known, and no
     * word, is "other".
     */
    public static function finishReason(#[SensitiveParameter] ?string $word, bool $hasToolCalls): FinishReason
    {
        $reason = self::FINISH_WORDS[self::finishWord($word)] ?? FinishReason::Other;
        if ($hasToolCalls) {
            return $reason === FinishReason::Length ? FinishReason::Length : FinishReason::ToolCalls;
        }

        return $reason;
    }

    /**
     * The id of a tool call: the one the server gave, or, when it gave none, `call-<position>` with a number
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

        $id = 'call-' . $position;
        for ($suffix = 2; isset($taken[$id]); $suffix++) {
            $id = 'call-' . $position . '-' . $suffix;
        }

        return $id;
    }

    /**
     * Whether an id has the form this adapter gives to a call that came without one. Such an id is not sent back:
     * the server never wrote it.
     */
    public static function isAMadeId(#[SensitiveParameter] string $id): bool
    {
        return preg_match(self::MADE_ID, $id) === 1;
    }

    /**
     * A tool call from the `args` of a part. The format documents the arguments as a JSON object; no arguments at
     * all is a call without arguments. Anything else (a list, a string that holds no JSON object, a number) is a
     * call whose arguments cannot be read, which is a value and not an error.
     *
     * The arguments as they arrived are the text of `args` as the body wrote it, in one spelling, when that text is
     * at hand: decoded, an empty object cannot be told from an empty list.
     *
     * @param string $id not empty
     * @param mixed $arguments as they were decoded
     * @param string|null $written the JSON text of the arguments, cut out of the body
     */
    public static function toolCall(
        #[SensitiveParameter]
        string $id,
        #[SensitiveParameter]
        string $name,
        #[SensitiveParameter]
        mixed $arguments,
        #[SensitiveParameter]
        ?string $written = null,
    ): ToolCall {
        if (is_string($arguments)) {
            return self::toolCallFromText($id, $name, $arguments);
        }
        if ($arguments === null) {
            return ToolCall::decoded($id, $name, [], '{}');
        }
        if (! is_array($arguments)) {
            return ToolCall::unreadable($id, $name, self::NOT_AN_OBJECT, (string) json_encode($arguments, self::JSON));
        }

        // The text of the body counts only when it is the value that was decoded.
        $plain = $written === null ? null : RawJson::plain($written);
        if ($plain !== null && json_decode($plain, true) !== $arguments) {
            $plain = null;
        }
        $isAnObject = $plain !== null ? str_starts_with($plain, '{') : ($arguments === [] || ! array_is_list($arguments));
        $raw = $plain ?? ($arguments === [] ? '{}' : (string) json_encode($arguments, self::JSON));

        if (! $isAnObject) {
            return ToolCall::unreadable($id, $name, self::NOT_AN_OBJECT, $raw);
        }
        // PHP keeps the names "0", "1", … of an object as the numbers of a list, and a tool call cannot hold one.
        if ($arguments !== [] && array_is_list($arguments)) {
            return ToolCall::unreadable($id, $name, self::NUMBERED_NAMES, $raw);
        }

        /** @var array<string, mixed> $arguments */
        return ToolCall::decoded($id, $name, $arguments, $raw);
    }

    /**
     * A tool call from arguments that arrived as a string, which no page of the format documents. A text that holds
     * a JSON object is that object; any other text is a call whose arguments cannot be read, and is given as it is.
     *
     * @param string $id not empty
     */
    private static function toolCallFromText(
        #[SensitiveParameter]
        string $id,
        #[SensitiveParameter]
        string $name,
        #[SensitiveParameter]
        string $text,
    ): ToolCall {
        // Decoded twice: as objects to see that the text is an object and not a list, as arrays for the caller.
        $decoded = is_object(json_decode($text, false)) ? json_decode($text, true) : null;
        if (! is_array($decoded)) {
            return ToolCall::unreadable($id, $name, self::NOT_AN_OBJECT, $text === '' ? '""' : $text);
        }
        $raw = RawJson::plain($text) ?? $text;
        if ($decoded !== [] && array_is_list($decoded)) {
            return ToolCall::unreadable($id, $name, self::NUMBERED_NAMES, $raw);
        }

        /** @var array<string, mixed> $decoded */
        return ToolCall::decoded($id, $name, $decoded, $raw);
    }
}
