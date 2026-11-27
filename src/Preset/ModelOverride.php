<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Preset;

use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use OrganiseYou\LlmGateway\Contract\InvalidValue;

/**
 * Adapter options for the models whose name fits a pattern: a documented exception for named models, as data of a
 * preset. An override is not a list of the models a preset knows: a model that fits no override gets the options of
 * the preset.
 */
final readonly class ModelOverride
{
    /**
     * @param string $pattern compared with the whole model name, byte for byte: case-sensitive, and without
     *                        Unicode normalisation, so the same letter written in two ways does not fit; `*` stands
     *                        for any run of characters, also an empty one. Not empty, no control character. A model name that
     *                        itself holds a `*` cannot be named exactly
     * @param array<string, bool|int|string> $adapterOptions laid over the options of the preset for a model that
     *                                                       fits
     */
    public function __construct(
        public string $pattern,
        public array $adapterOptions,
    ) {
        if (! Rules::isModelName($pattern)) {
            throw new InvalidValue('The pattern of a model override must not be empty and must not hold a control character.');
        }
        self::assertAdapterOptions($adapterOptions);
    }

    /**
     * The rule of the options of a preset.
     *
     * @param array<mixed> $adapterOptions
     */
    private static function assertAdapterOptions(array $adapterOptions): void
    {
        foreach ($adapterOptions as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidValue('The name of an adapter option must be a string.');
            }
            if (! is_bool($value) && ! is_int($value) && ! is_string($value)) {
                throw new InvalidValue('An adapter option must be a boolean, an integer or a string.');
            }
        }
    }

    /**
     * Whether the whole model name fits the pattern. No regular expression is used: the answer does not depend on a
     * limit that is a setting of PHP.
     */
    public function matches(string $model): bool
    {
        $pieces = explode('*', $this->pattern);
        $last = count($pieces) - 1;
        if ($last === 0) {
            return $model === $this->pattern;
        }

        // The name starts with the first piece and ends with the last, and the two do not share a character.
        $at = strlen($pieces[0]);
        $end = strlen($model) - strlen($pieces[$last]);
        if ($end < $at || ! str_starts_with($model, $pieces[0]) || ! str_ends_with($model, $pieces[$last])) {
            return false;
        }

        // Every piece between them is found in order, each at its first place after the one before.
        for ($index = 1; $index < $last; $index++) {
            $piece = $pieces[$index];
            if ($piece === '') {
                continue;
            }
            $found = strpos($model, $piece, $at);
            if ($found === false || $found + strlen($piece) > $end) {
                return false;
            }
            $at = $found + strlen($piece);
        }

        return true;
    }
}
