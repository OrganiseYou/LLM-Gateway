<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Preset;

use InvalidArgumentException;

/**
 * One setting the customer fills in for a preset. Never a secret: a secret is a credential.
 */
final readonly class PresetField
{
    /**
     * @param string $key lowercase letters, digits and underscores, starting with a letter, at most 32 characters
     * @param string $label what the customer reads next to the field
     * @param string|null $pattern a regular expression without delimiters; a value must match it from its first to
     *                             its last character
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required,
        public ?string $pattern = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key) !== 1) {
            throw new InvalidArgumentException('The key of a field must match ^[a-z][a-z0-9_]{0,31}$.');
        }
        if ($label === '') {
            throw new InvalidArgumentException('The label of a field must not be empty.');
        }
        if ($pattern !== null && ($pattern === '' || ! self::compiles($pattern))) {
            throw new InvalidArgumentException(
                'The pattern of a field must be a regular expression without delimiters that can be compiled.',
            );
        }
    }

    /**
     * Whether a value has the form this field asks for. A field without a pattern accepts every value.
     */
    public function accepts(string $value): bool
    {
        return $this->pattern === null || preg_match(self::regex($this->pattern), $value) === 1;
    }

    private static function compiles(string $pattern): bool
    {
        // A pattern that cannot be compiled gives a warning and false; the warning is not for the host to see.
        set_error_handler(fn(): bool => true);
        try {
            return preg_match(self::regex($pattern), '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The whole value must match: the pattern is anchored here, so a preset cannot forget it.
     */
    private static function regex(string $pattern): string
    {
        return "\x01^(?:" . $pattern . ")$\x01D";
    }
}
