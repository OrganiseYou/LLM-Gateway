<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use InvalidArgumentException;

/**
 * A call the model asked for. Arguments that could not be read are a value, not an exception: the call then holds
 * the reason, so it can go back to the model as readable text.
 */
final readonly class ToolCall
{
    /**
     * @param string $name as the model wrote it; not checked, a model may garble it
     * @param array<string, mixed>|null $arguments the decoded arguments, or null when they could not be read
     * @param string|null $argumentsError why they could not be read, in plain words, or null
     * @param string $rawArguments the arguments as they arrived
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?array $arguments,
        public ?string $argumentsError,
        public string $rawArguments,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('A tool call needs an id.');
        }
        if (($arguments === null) === ($argumentsError === null)) {
            throw new InvalidArgumentException('A tool call holds either arguments or an arguments error.');
        }
        if ($argumentsError === '') {
            throw new InvalidArgumentException('The arguments error of a tool call must not be empty.');
        }
        if ($arguments !== null && $arguments !== [] && self::isList($arguments)) {
            throw new InvalidArgumentException('The arguments of a tool call are a JSON object, not a list.');
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function decoded(string $id, string $name, array $arguments, string $rawArguments): self
    {
        return new self($id, $name, $arguments, null, $rawArguments);
    }

    public static function unreadable(string $id, string $name, string $argumentsError, string $rawArguments): self
    {
        return new self($id, $name, null, $argumentsError, $rawArguments);
    }

    public function isReadable(): bool
    {
        return $this->arguments !== null;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
