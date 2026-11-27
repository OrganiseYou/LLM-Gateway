<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Cohere;

use OrganiseYou\LlmGateway\Contract\InvalidValue;

/**
 * The options of the Chat v2 adapter as they are for one model: what a preset says beside the capability options
 * every adapter reads. The name is that of the adapter map (`docs/design/adapters.md`, section 4.3).
 *
 * - `schema_key`: the name under which the schema of a response format stands inside `response_format`.
 *   `json_schema` (the default) is what the API reference of the provider writes, `schema` what its guide writes
 *   (`docs/providers/cohere.md`, "What a request can do"). The two pages disagree and only a live check can settle
 *   it: not confirmed, so it is an option and a host can change it without a new version of the library.
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
        'schema_key' => ['json_schema', 'schema'],
    ];

    /** The name of the member of `response_format` that holds the schema. */
    public string $schemaKey;

    /**
     * @param array<string, bool|int|string> $options the adapter options of a preset, as they are for one model
     * @throws InvalidValue when an option has a name this adapter does not know or holds a value outside its list;
     *                      ask hasAnUnknownOption() and optionOutsideItsList() first
     */
    public function __construct(array $options)
    {
        if (self::hasAnUnknownOption($options)) {
            throw new InvalidValue('The options hold a name the Chat v2 adapter does not know.');
        }
        $wrong = self::optionOutsideItsList($options);
        if ($wrong !== null) {
            throw new InvalidValue('The option ' . $wrong . ' has a value outside its list.');
        }

        $this->schemaKey = ($options['schema_key'] ?? 'json_schema') === 'schema' ? 'schema' : 'json_schema';
    }

    /**
     * Whether the options hold a name this adapter does not know: neither its own nor a capability option. A name
     * that is written wrongly would otherwise leave its option unset without a word.
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
