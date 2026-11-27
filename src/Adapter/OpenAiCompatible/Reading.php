<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\OpenAiCompatible;

use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\Usage;
use SensitiveParameter;

/**
 * The tolerant reading of an answer in the chat-completions format, the same for a whole response and for a stream.
 * A field that is missing, null or of another type than expected is read as "not there": nothing here throws.
 */
final readonly class Reading
{
    private const string NOT_AN_OBJECT = 'the arguments are not a JSON object';

    private const string NUMBERED_NAMES = 'the names of the arguments are the numbers of a list';

    private const int JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * The words a server ends an answer with, and what each means. A word that is not here means "other".
     */
    private const array FINISH_WORDS = [
        'stop' => FinishReason::Stop,
        'end_turn' => FinishReason::Stop,
        'stop_sequence' => FinishReason::Stop,
        'COMPLETE' => FinishReason::Stop,
        'STOP_SEQUENCE' => FinishReason::Stop,
        'STOP' => FinishReason::Stop,
        'length' => FinishReason::Length,
        'model_length' => FinishReason::Length,
        'max_tokens' => FinishReason::Length,
        'model_context_window_exceeded' => FinishReason::Length,
        'MAX_TOKENS' => FinishReason::Length,
        'tool_calls' => FinishReason::ToolCalls,
        'function_call' => FinishReason::ToolCalls,
        'tool_use' => FinishReason::ToolCalls,
        'TOOL_CALL' => FinishReason::ToolCalls,
        'content_filter' => FinishReason::ContentFilter,
    ];

    /**
     * The text of a `content`: a string as it is; of a list the `text` of its text entries, joined; of anything
     * else nothing. An entry of another kind (reasoning, a citation) is no text.
     */
    public static function text(#[SensitiveParameter] mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (! is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $entry) {
            if (is_array($entry) && ($entry['type'] ?? 'text') === 'text' && is_string($entry['text'] ?? null)) {
                $text .= $entry['text'];
            }
        }

        return $text;
    }

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
     * The reasoning beside a message or a delta: `reasoning_content`, or `reasoning`. It is no text of the answer.
     *
     * @param array<mixed> $holder a message or a delta
     */
    public static function reasoning(#[SensitiveParameter] array $holder): string
    {
        $reasoning = self::string($holder, 'reasoning_content');

        return $reasoning !== '' ? $reasoning : self::string($holder, 'reasoning');
    }

    /**
     * The usage a body reports, or null when it reports none: the prompt and the completion tokens, each only
     * when it is a whole number of 0 or more.
     *
     * @param array<mixed> $holder a response, a chunk or a choice
     */
    public static function usage(#[SensitiveParameter] array $holder): ?Usage
    {
        $usage = $holder['usage'] ?? null;
        if (! is_array($usage)) {
            return null;
        }

        $input = is_int($usage['prompt_tokens'] ?? null) && $usage['prompt_tokens'] >= 0 ? $usage['prompt_tokens'] : null;
        $output = is_int($usage['completion_tokens'] ?? null) && $usage['completion_tokens'] >= 0 ? $usage['completion_tokens'] : null;

        return $input === null && $output === null ? null : new Usage($input, $output);
    }

    /**
     * What the word a server ended an answer with means; a word that is not known, and no word, is "other".
     *
     * @param bool $contentFilterMeansWithheld false: on this server `content_filter` says only that the reason was
     *                                         not understood
     */
    public static function finishReason(?string $word, bool $contentFilterMeansWithheld): FinishReason
    {
        $reason = self::FINISH_WORDS[$word ?? ''] ?? FinishReason::Other;

        return $reason === FinishReason::ContentFilter && ! $contentFilterMeansWithheld ? FinishReason::Other : $reason;
    }

    /**
     * The id of a tool call: the one the server gave, or, when it gave none, `call_<position>` with a number
     * after it when another call of the answer already has that id.
     *
     * @param int $position the place of the call in the answer, from 0
     * @param list<string> $taken the ids the other calls of the answer have
     * @param string $given the id the server gave, or an empty string
     */
    public static function freeId(int $position, #[SensitiveParameter] array $taken, string $given = ''): string
    {
        if ($given !== '') {
            return $given;
        }

        $id = 'call_' . $position;
        for ($suffix = 2; in_array($id, $taken, true); $suffix++) {
            $id = 'call_' . $position . '_' . $suffix;
        }

        return $id;
    }

    /**
     * A tool call from what a server wrote. The arguments are a string that holds a JSON object, or a map; anything
     * else is a call whose arguments cannot be read, which is a value and not an error. Nothing more is checked: a
     * whole number that arrives as a string stays a string.
     *
     * @param string $id not empty
     * @param mixed $arguments as they arrived: a string, a decoded map, or anything else
     */
    public static function toolCall(
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
            // A decoded body cannot tell an empty object from an empty list: an empty map of arguments it is.
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
