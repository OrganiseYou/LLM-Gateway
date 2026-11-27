<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Gemini;

use OrganiseYou\LlmGateway\Contract\InvalidValue;

/**
 * The options of the generate-content adapter as they are for one model: what a preset says beside the capability
 * options every adapter reads. The names are those of the adapter map (`docs/design/adapters.md`, section 4.2).
 *
 * - `structured_output_field`: where the schema of a response format is written. `response_json_schema` (the
 *   default): `generationConfig.responseMimeType` with `generationConfig.responseJsonSchema`. `response_format`:
 *   `generationConfig.responseFormat.text` with `mimeType` and `schema`. The pages of the provider give three forms
 *   and do not agree on which one counts (`docs/providers/gemini.md`, "Schema dialect"): not confirmed, so it is an
 *   option. The third form, the schema subset under `responseSchema`, is not built.
 * - `store_field`: `omit` (the default) sends nothing; `false` sends `store: false`, on the one way in that
 *   documents the field.
 *
 * An option that is left out has its default.
 */
final readonly class Options
{
    /** The capability options every adapter reads; the adapter base checks their values. */
    private const array CAPABILITY_OPTIONS = [
        'tool_calling', 'forced_tool_call', 'parallel_tool_calls', 'structured_output', 'tools_with_structured_output',
        'streaming', 'model_listing', 'temperature', 'context_length',
    ];

    /** The adapter's own options, each with its values, the default first. */
    private const array OWN_OPTIONS = [
        'structured_output_field' => ['response_json_schema', 'response_format'],
        'store_field' => ['omit', 'false'],
    ];

    /** Whether the schema of a response format is written under `responseFormat.text`, not under `responseJsonSchema`. */
    public bool $schemaInResponseFormat;

    /** Whether `store: false` is sent. */
    public bool $sendsStoreFalse;

    /**
     * @param array<string, bool|int|string> $options the adapter options of a preset, as they are for one model
     * @throws InvalidValue when an option has a name this adapter does not know or holds a value outside its list;
     *                      ask hasAnUnknownOption() and optionOutsideItsList() first
     */
    public function __construct(array $options)
    {
        if (self::hasAnUnknownOption($options)) {
            throw new InvalidValue('The options hold a name the generate-content adapter does not know.');
        }
        $wrong = self::optionOutsideItsList($options);
        if ($wrong !== null) {
            throw new InvalidValue('The option ' . $wrong . ' has a value outside its list.');
        }

        $this->schemaInResponseFormat = ($options['structured_output_field'] ?? 'response_json_schema') === 'response_format';
        $this->sendsStoreFalse = ($options['store_field'] ?? 'omit') === 'false';
    }

    /**
     * Whether the options hold a name this adapter does not know: neither one of its own two nor a capability
     * option. A name that is written wrongly would otherwise leave its option unset without a word.
     *
     * @param array<string, bool|int|string> $options
     */
    public static function hasAnUnknownOption(array $options): bool
    {
        foreach (array_keys($options) as $name) {
            if (! isset(self::OWN_OPTIONS[$name]) && ! in_array($name, self::CAPABILITY_OPTIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name of the first of the adapter's own options that holds a value outside its list, or null. Only the
     * name: the value is never repeated. The value of a capability option is the adapter base's to judge.
     *
     * @param array<string, bool|int|string> $options
     */
    public static function optionOutsideItsList(array $options): ?string
    {
        foreach (self::OWN_OPTIONS as $name => $values) {
            if (array_key_exists($name, $options) && ! in_array($options[$name], $values, true)) {
                return $name;
            }
        }

        return null;
    }
}
