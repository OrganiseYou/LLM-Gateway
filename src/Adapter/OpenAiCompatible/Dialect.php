<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\OpenAiCompatible;

use OrganiseYou\LlmGateway\Contract\InvalidValue;

/**
 * The dialect options of the chat-completions adapter as they are for one model: every option of the preset that
 * says where a server differs from the plain format, read once and checked against its list.
 *
 * An option that is left out has its default, the first value of its list. The names and the values are those of
 * the adapter map (`docs/design/adapters.md`, section 2.2). Nothing here is decided by the id of a preset.
 *
 * The word `false` of an option may be written as the string or as the boolean; `tool_result_name` is a boolean.
 */
final readonly class Dialect
{
    /**
     * The options whose value is one word of a fixed list, the default first.
     *
     * `reasoning_carrier` has a third value in the adapter map, `content_entries`. It is not built yet, so it is
     * outside the list here.
     */
    private const array WORDS = [
        'max_tokens_field' => ['max_completion_tokens', 'max_tokens'],
        'system_role' => ['system', 'developer'],
        'system_messages' => ['separate', 'joined'],
        'assistant_content_without_text' => ['null', 'empty_string'],
        'required_word' => ['required', 'any'],
        'named_tool_choice' => ['object', 'required_single', 'unsupported'],
        'tool_strict' => ['omit', 'false'],
        'json_schema_strict' => ['omit', 'false', 'when_fits'],
        'strict_subset' => ['standard', 'narrow'],
        'json_schema_key' => ['schema', 'schema_definition'],
        'stream_usage' => ['on_request', 'unasked'],
        'store_field' => ['omit', 'false'],
        'reasoning_carrier' => ['none', 'message_field'],
        'inline_reasoning' => ['keep', 'strip'],
        'finish_content_filter' => ['withheld', 'unknown'],
    ];

    /** The dialect options whose value is not a word of a list. */
    private const array OTHER_OPTIONS = ['tool_result_name', 'models_path', 'model_id_field'];

    /** The capability options every adapter reads; the adapter base checks their values. */
    private const array CAPABILITY_OPTIONS = [
        'tool_calling', 'forced_tool_call', 'parallel_tool_calls', 'structured_output', 'tools_with_structured_output',
        'streaming', 'model_listing', 'temperature', 'context_length',
    ];

    /** The name of the field that holds the maximum of output tokens. */
    public string $outputLimitField;

    /** The role a system message is sent with. */
    public string $systemRole;

    /** One system message with the texts joined by a blank line, instead of one message per system message. */
    public bool $joinsSystemMessages;

    /** The content of an assistant message that holds only tool calls: null, or an empty string. */
    public ?string $contentWithoutText;

    /** The word of `tool_choice` for "must call some tool". */
    public string $requiredWord;

    public NamedToolChoice $namedToolChoice;

    /** Whether `strict: false` is written on every tool. */
    public bool $writesToolStrictFalse;

    /** Whether a tool message also carries the name of its tool. */
    public bool $namesToolResults;

    public SchemaStrictness $schemaStrictness;

    /** Whether `when_fits` tests the narrower of the two documented subsets. */
    public bool $narrowSubset;

    /** The name of the member that holds the schema inside `json_schema`. */
    public string $schemaMember;

    /** Whether a stream is asked to report its usage. */
    public bool $asksUsageInAStream;

    /** Whether `store: false` is sent. */
    public bool $sendsStoreFalse;

    public ReasoningCarrier $reasoningCarrier;

    /** Whether one leading block of inline reasoning is cut from the text of an answer. */
    public bool $stripsInlineReasoning;

    /** Whether the finish reason `content_filter` means that output was withheld; false: it means "not understood". */
    public bool $contentFilterMeansWithheld;

    /** Where the model list is, under the base URL. */
    public string $modelsPath;

    /** The field of an entry of the model list that goes into the model of a request. */
    public string $modelIdField;

    /**
     * @param array<string, bool|int|string> $options the adapter options of a preset, as they are for one model
     * @throws InvalidValue when an option has a name this adapter does not know or holds a value outside its list;
     *                      ask hasAnUnknownOption() and optionOutsideItsList() first
     */
    public function __construct(array $options)
    {
        if (self::hasAnUnknownOption($options)) {
            throw new InvalidValue('The options hold a name the chat-completions adapter does not know.');
        }
        $wrong = self::optionOutsideItsList($options);
        if ($wrong !== null) {
            throw new InvalidValue('The option ' . $wrong . ' has a value outside its list.');
        }

        $this->outputLimitField = self::word($options, 'max_tokens_field');
        $this->systemRole = self::word($options, 'system_role');
        $this->joinsSystemMessages = self::word($options, 'system_messages') === 'joined';
        $this->contentWithoutText = self::word($options, 'assistant_content_without_text') === 'empty_string' ? '' : null;
        $this->requiredWord = self::word($options, 'required_word');
        $this->namedToolChoice = NamedToolChoice::from(self::word($options, 'named_tool_choice'));
        $this->writesToolStrictFalse = self::word($options, 'tool_strict') === 'false';
        $this->namesToolResults = ($options['tool_result_name'] ?? false) === true;
        $this->schemaStrictness = SchemaStrictness::from(self::word($options, 'json_schema_strict'));
        $this->narrowSubset = self::word($options, 'strict_subset') === 'narrow';
        $this->schemaMember = self::word($options, 'json_schema_key');
        $this->asksUsageInAStream = self::word($options, 'stream_usage') === 'on_request';
        $this->sendsStoreFalse = self::word($options, 'store_field') === 'false';
        $this->reasoningCarrier = ReasoningCarrier::from(self::word($options, 'reasoning_carrier'));
        $this->stripsInlineReasoning = self::word($options, 'inline_reasoning') === 'strip';
        $this->contentFilterMeansWithheld = self::word($options, 'finish_content_filter') === 'withheld';
        $this->modelsPath = (string) ($options['models_path'] ?? '/models');
        $this->modelIdField = (string) ($options['model_id_field'] ?? 'id');
    }

    /**
     * The name of the first dialect option that holds a value outside its list, or null when every one is in it.
     * Only the name: the value is never repeated. The value of a capability option is the adapter base's to judge.
     *
     * @param array<string, bool|int|string> $options
     */
    public static function optionOutsideItsList(array $options): ?string
    {
        foreach (self::WORDS as $name => $words) {
            if (array_key_exists($name, $options) && self::wordOf($options[$name], $words) === null) {
                return $name;
            }
        }
        if (array_key_exists('tool_result_name', $options) && ! is_bool($options['tool_result_name'])) {
            return 'tool_result_name';
        }
        if (array_key_exists('models_path', $options) && ! self::isPath($options['models_path'])) {
            return 'models_path';
        }
        if (array_key_exists('model_id_field', $options) && (! is_string($options['model_id_field']) || $options['model_id_field'] === '')) {
            return 'model_id_field';
        }

        return null;
    }

    /**
     * Whether the options hold a name this adapter does not know: neither a dialect option nor a capability
     * option. A name that is written wrongly would otherwise leave its option at the default without a word.
     *
     * @param array<string, bool|int|string> $options
     */
    public static function hasAnUnknownOption(array $options): bool
    {
        foreach (array_keys($options) as $name) {
            if (! isset(self::WORDS[$name]) && ! in_array($name, self::OTHER_OPTIONS, true) && ! in_array($name, self::CAPABILITY_OPTIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, bool|int|string> $options
     */
    private static function word(array $options, string $name): string
    {
        $words = self::WORDS[$name];
        if (! array_key_exists($name, $options)) {
            return $words[0];
        }

        return self::wordOf($options[$name], $words) ?? $words[0];
    }

    /**
     * The value as a word of the list, or null when it is none. The boolean false stands for the word `false`
     * where the list has that word.
     *
     * @param list<string> $words
     */
    private static function wordOf(bool|int|string $value, array $words): ?string
    {
        if ($value === false) {
            $value = 'false';
        }

        return is_string($value) && in_array($value, $words, true) ? $value : null;
    }

    /**
     * A path under the base URL: it starts with a slash, holds only characters of a URL path and no dot segment.
     */
    private static function isPath(bool|int|string $value): bool
    {
        return is_string($value)
            && preg_match('/^\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]*$/D', $value) === 1
            && preg_match('/(?:^|\/)(?:\.|%2e){1,2}(?:\/|$)/Di', $value) !== 1;
    }
}
