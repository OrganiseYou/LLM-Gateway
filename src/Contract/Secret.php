<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use Generator;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use Stringable;

/**
 * One secret value, kept where no output function reaches it. reveal() is the only way to the value.
 *
 * The value is not in a property: var_export, serialize and an array cast print private properties. It is not in
 * a closure either: the dump of a closure prints the variables it holds. It is the local variable of a paused
 * generator, which var_export, var_dump, print_r, an array cast and reflection all show without its variables.
 * A debugger that stops the process can still read it, as it can read everything.
 */
final class Secret implements JsonSerializable, Stringable
{
    private const string MASK = '[secret]';

    /** @var Generator<int, string, mixed, never> */
    private readonly Generator $holder;

    public function __construct(#[SensitiveParameter] string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException('A secret must not be empty.');
        }

        $this->holder = self::hold($value);
    }

    public function reveal(): string
    {
        return $this->holder->current();
    }

    public function __toString(): string
    {
        return self::MASK;
    }

    public function jsonSerialize(): string
    {
        return self::MASK;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => self::MASK];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A secret cannot be serialized.');
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('A secret cannot be unserialized.');
    }

    /**
     * Never ends and never moves on: every read of the current value gives the secret, also for a copy of the
     * object, which shares the holder.
     *
     * @return Generator<int, string, mixed, never>
     */
    private static function hold(#[SensitiveParameter] string $value): Generator
    {
        for (;;) {
            yield $value;
        }
    }
}
