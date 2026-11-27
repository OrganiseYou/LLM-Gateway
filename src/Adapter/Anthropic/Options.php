<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Anthropic;

use OrganiseYou\LlmGateway\Contract\InvalidValue;

/**
 * The options of the Messages adapter as they are for one model: what a preset says beside the capability options
 * every adapter reads. The names are those of the adapter map (`docs/design/adapters.md`, sections 4.1 and 9).
 *
 * - `api_version`: the value of the `anthropic-version` header, a date such as `2026-10-21`. The format asks for the
 *   header with every request and the adapter has no version of its own, so a preset must set it.
 * - `default_max_output_tokens`: the maximum of output tokens for a request that gives none, a whole number of 1 or
 *   more. The format requires the field and its documentation gives no default, so the number is preset data.
 *
 * A preset must set both: a preset without one of them can do nothing, and says so the first time it is asked
 * anything, not on the first request that happens to give no maximum.
 */
final readonly class Options
{
    /** The capability options every adapter reads; the adapter base checks their values. */
    private const array CAPABILITY_OPTIONS = [
        'tool_calling', 'forced_tool_call', 'parallel_tool_calls', 'structured_output', 'tools_with_structured_output',
        'streaming', 'model_listing', 'temperature', 'context_length',
    ];

    private const array OWN_OPTIONS = ['api_version', 'default_max_output_tokens'];

    /** The form of a version of the API: a date. */
    private const string VERSION = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D';

    /** The value of the version header. */
    public string $apiVersion;

    /** The maximum of output tokens for a request that gives none. */
    public int $defaultOutputLimit;

    /**
     * @param array<string, bool|int|string> $options the adapter options of a preset, as they are for one model
     * @throws InvalidValue when an option has a name this adapter does not know, holds a value outside its list, or
     *                      one of the two the adapter needs is missing; ask hasAnUnknownOption(),
     *                      optionOutsideItsList() and missingOption() first
     */
    public function __construct(array $options)
    {
        if (self::hasAnUnknownOption($options)) {
            throw new InvalidValue('The options hold a name the Messages adapter does not know.');
        }
        $wrong = self::optionOutsideItsList($options);
        if ($wrong !== null) {
            throw new InvalidValue('The option ' . $wrong . ' has a value outside its list.');
        }
        $missing = self::missingOption($options);
        if ($missing !== null) {
            throw new InvalidValue('The options do not set ' . $missing . '.');
        }

        $this->apiVersion = (string) $options['api_version'];
        $this->defaultOutputLimit = (int) $options['default_max_output_tokens'];
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
            if (! in_array($name, self::OWN_OPTIONS, true) && ! in_array($name, self::CAPABILITY_OPTIONS, true)) {
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
        if (array_key_exists('api_version', $options)
            && (! is_string($options['api_version']) || preg_match(self::VERSION, $options['api_version']) !== 1)) {
            return 'api_version';
        }
        if (array_key_exists('default_max_output_tokens', $options)
            && (! is_int($options['default_max_output_tokens']) || $options['default_max_output_tokens'] < 1)) {
            return 'default_max_output_tokens';
        }

        return null;
    }

    /**
     * The name of the first of the two options the adapter needs that the options do not set, or null.
     *
     * @param array<string, bool|int|string> $options
     */
    public static function missingOption(array $options): ?string
    {
        foreach (self::OWN_OPTIONS as $name) {
            if (! array_key_exists($name, $options)) {
                return $name;
            }
        }

        return null;
    }
}
