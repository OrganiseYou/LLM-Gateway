<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use InvalidArgumentException;

/**
 * One model of the model list.
 */
final readonly class ModelInfo
{
    /**
     * @param string $id the value to put in a request's model
     * @param string|null $name a display name
     * @param int|null $contextLength in tokens, when the model list tells it
     * @param list<string> $aliases other ids that reach the same model; each can be put in a request's model too
     */
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?int $contextLength = null,
        public array $aliases = [],
        public bool $deprecated = false,
    ) {
        if ($id === '' || preg_match('/\p{Cc}/u', $id) !== 0) {
            throw new InvalidArgumentException('The id must not be empty and must not hold a control character.');
        }
        if ($name !== null && ($name === '' || preg_match('/\p{Cc}/u', $name) !== 0)) {
            throw new InvalidArgumentException('The name must not be empty and must not hold a control character.');
        }
        if ($contextLength !== null && $contextLength < 1) {
            throw new InvalidArgumentException('The context length must be 1 or more.');
        }

        self::assertAliases($aliases);
    }

    /**
     * @param array<mixed> $aliases
     */
    private static function assertAliases(array $aliases): void
    {
        if (! array_is_list($aliases)) {
            throw new InvalidArgumentException('The aliases must be a list.');
        }

        foreach ($aliases as $alias) {
            if (! is_string($alias) || $alias === '' || preg_match('/\p{Cc}/u', $alias) !== 0) {
                throw new InvalidArgumentException(
                    'An alias must be a string that is not empty and holds no control character.',
                );
            }
        }
    }
}
