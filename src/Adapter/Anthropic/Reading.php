<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Anthropic;

use OrganiseYou\LlmGateway\Adapter\Shared\RawJson;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\Usage;
use SensitiveParameter;

/**
 * The tolerant reading of an answer in the Messages format, the same for a whole response and for a stream. A field
 * that is missing, null or of another type than expected is read as "not there": nothing here throws.
 */
final readonly class Reading
{
    /** The counts of a usage object that are read, the three of the input first. */
    public const array COUNTS = ['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'output_tokens'];

    private const string NOT_AN_OBJECT = 'the arguments are not a JSON object';

    private const string NUMBERED_NAMES = 'the names of the arguments are the numbers of a list';

    private const int JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * The words the format ends an answer with, and what each means. A word that is not here (`pause_turn`, a word
     * of a later version) means "other".
     */
    private const array FINISH_WORDS = [
        'end_turn' => FinishReason::Stop,
        'stop_sequence' => FinishReason::Stop,
        'max_tokens' => FinishReason::Length,
        'model_context_window_exceeded' => FinishReason::Length,
        'tool_use' => FinishReason::ToolCalls,
        'refusal' => FinishReason::Refusal,
    ];

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
     * The counts known so far with those of one more usage object laid over them: of each count the later value,
     * when it is a whole number of 0 or more. A stream tells its usage twice, and the second time as totals; a
     * count that is null or missing there leaves the earlier value as it is.
     *
     * @param array<string, int> $known
     * @param array<mixed> $holder a response, the message of the first event of a stream, or a later event
     * @return array<string, int>
     */
    public static function counts(array $known, #[SensitiveParameter] array $holder): array
    {
        $usage = self::members($holder, 'usage');
        foreach (self::COUNTS as $count) {
            if (is_int($usage[$count] ?? null) && $usage[$count] >= 0) {
                $known[$count] = $usage[$count];
            }
        }

        return $known;
    }

    /**
     * The usage of an answer from its counts. The input is the tokens after the last cache point plus the tokens
     * that were written to and read from the cache; the output is the output tokens, thinking included. A sum that
     * is too large for a whole number of PHP is not a count: the input is then not known.
     *
     * @param array<string, int> $counts
     */
    public static function usage(array $counts): Usage
    {
        $input = isset($counts['input_tokens'])
            ? $counts['input_tokens'] + ($counts['cache_creation_input_tokens'] ?? 0) + ($counts['cache_read_input_tokens'] ?? 0)
            : null;

        // Past the largest whole number PHP adds to a float.
        return new Usage(is_int($input) ? $input : null, $counts['output_tokens'] ?? null);
    }

    /**
     * What the end of an answer means. An answer that holds tool calls ends for tool calls unless it was cut off,
     * whatever word came with it; the word for tool calls on an answer that holds none is "other", so that a caller
     * who loops on that reason does not spin. A word that is not known, and no word, is "other".
     */
    public static function finishReason(?string $word, bool $hasToolCalls): FinishReason
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
    public static function freeId(int $position, #[SensitiveParameter] array $taken, string $given = ''): string
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
     * A tool call from the `input` of a block of a whole answer. The format documents the input as a JSON object;
     * no input at all is a call without arguments. Anything else (a list, a string that holds no JSON object, a
     * number) is a call whose arguments cannot be read, which is a value and not an error.
     *
     * The arguments as they arrived are the text of the input as the body wrote it, in one spelling, when that
     * text is at hand: decoded, an empty object cannot be told from an empty list.
     *
     * @param string $id not empty
     * @param mixed $input as it was decoded
     * @param string|null $written the JSON text of the input, cut out of the body
     */
    public static function toolCall(
        string $id,
        #[SensitiveParameter]
        string $name,
        #[SensitiveParameter]
        mixed $input,
        #[SensitiveParameter]
        ?string $written = null,
    ): ToolCall {
        if (is_string($input)) {
            return self::toolCallFromText($id, $name, $input);
        }
        if ($input === null) {
            return ToolCall::decoded($id, $name, [], '{}');
        }
        if (! is_array($input)) {
            return ToolCall::unreadable($id, $name, self::NOT_AN_OBJECT, (string) json_encode($input, self::JSON));
        }

        // The text of the body counts only when it is the value that was decoded.
        $plain = $written === null ? null : RawJson::plain($written);
        if ($plain !== null && json_decode($plain, true) !== $input) {
            $plain = null;
        }
        $isAnObject = $plain !== null ? str_starts_with($plain, '{') : ($input === [] || ! array_is_list($input));
        $raw = $plain ?? ($input === [] ? '{}' : (string) json_encode($input, self::JSON));

        if (! $isAnObject) {
            return ToolCall::unreadable($id, $name, self::NOT_AN_OBJECT, $raw);
        }

        return self::readable($id, $name, $input, $raw);
    }

    /**
     * A tool call from arguments that are a text: the pieces of a stream, joined, or an input that arrived as a
     * string. A text that holds a JSON object is that object, and the arguments as they arrived are that text in
     * one spelling; any other text is a call whose arguments cannot be read, and is given as it is. An empty text is
     * a call without arguments: a stream documents no other way to say so.
     *
     * @param string $id not empty
     */
    public static function toolCallFromText(
        string $id,
        #[SensitiveParameter]
        string $name,
        #[SensitiveParameter]
        string $text,
    ): ToolCall {
        if (trim($text) === '') {
            return ToolCall::decoded($id, $name, [], '{}');
        }

        // Decoded twice: as objects to see that the text is an object and not a list, as arrays for the caller.
        $decoded = is_object(json_decode($text, false)) ? json_decode($text, true) : null;
        if (! is_array($decoded)) {
            return ToolCall::unreadable($id, $name, self::NOT_AN_OBJECT, $text);
        }

        // In one spelling, as the input of a whole answer: a stream and a whole answer then give the same call.
        return self::readable($id, $name, $decoded, RawJson::plain($text) ?? $text);
    }

    /**
     * A call with the arguments of a JSON object. PHP keeps the names "0", "1", … of an object as the numbers of a
     * list, and a tool call cannot hold a list: such arguments are given as they arrived, as not readable.
     *
     * @param array<mixed> $arguments the members of a JSON object
     */
    private static function readable(
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
