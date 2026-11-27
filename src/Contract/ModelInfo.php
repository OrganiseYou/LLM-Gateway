<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use OrganiseYou\LlmGateway\Contract\Internal\Rules;

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
        if (! Rules::isModelName($id)) {
            throw new InvalidValue('The id must not be empty and must not hold a control character.');
        }
        if ($name !== null && ! Rules::isModelName($name)) {
            throw new InvalidValue('The name must not be empty and must not hold a control character.');
        }
        if ($contextLength !== null && $contextLength < 1) {
            throw new InvalidValue('The context length must be 1 or more.');
        }

        self::assertAliases($aliases);
    }

    /**
     * @param array<mixed> $aliases
     */
    private static function assertAliases(array $aliases): void
    {
        if (! array_is_list($aliases)) {
            throw new InvalidValue('The aliases must be a list.');
        }

        foreach ($aliases as $alias) {
            if (! is_string($alias) || ! Rules::isModelName($alias)) {
                throw new InvalidValue(
                    'An alias must be a string that is not empty and holds no control character.',
                );
            }
        }
    }
}
